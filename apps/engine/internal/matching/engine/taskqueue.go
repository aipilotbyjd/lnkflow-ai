package engine

import (
	"container/list"
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"sync"
	"time"

	"github.com/redis/go-redis/v9"
	"golang.org/x/time/rate"
)

const DefaultLeaseTimeout = 60 * time.Second

var ErrTaskExists = errors.New("task already exists")

// TaskStore defines the interface for task persistence.
type TaskStore interface {
	AddTask(ctx context.Context, task *Task) error
	PollTask(ctx context.Context, timeout time.Duration) (*Task, error)
	AckTask(ctx context.Context, taskID string) (bool, error)
	Len(ctx context.Context) (int64, error)
}

// MemoryTaskStore is an in-memory implementation of TaskStore.
type MemoryTaskStore struct {
	tasks    *list.List
	tasksMap map[string]*list.Element
	mu       sync.Mutex
}

func NewMemoryTaskStore() *MemoryTaskStore {
	return &MemoryTaskStore{
		tasks:    list.New(),
		tasksMap: make(map[string]*list.Element),
	}
}

func (s *MemoryTaskStore) AddTask(ctx context.Context, task *Task) error {
	s.mu.Lock()
	defer s.mu.Unlock()

	if _, exists := s.tasksMap[task.ID]; exists {
		return ErrTaskExists
	}

	elem := s.tasks.PushBack(task)
	s.tasksMap[task.ID] = elem
	return nil
}

func (s *MemoryTaskStore) PollTask(ctx context.Context, timeout time.Duration) (*Task, error) {
	if err := ctx.Err(); err != nil {
		return nil, err
	}

	s.mu.Lock()
	defer s.mu.Unlock()

	elem := s.tasks.Front()
	if elem == nil {
		return nil, nil // Or wait if we implement condition variable
	}

	task := elem.Value.(*Task)
	s.tasks.Remove(elem)
	delete(s.tasksMap, task.ID)
	return task, nil
}

func (s *MemoryTaskStore) AckTask(ctx context.Context, taskID string) (bool, error) {
	if err := ctx.Err(); err != nil {
		return false, err
	}

	s.mu.Lock()
	defer s.mu.Unlock()

	// Support removing pending tasks by ID for idempotent completion paths.
	if elem, exists := s.tasksMap[taskID]; exists {
		s.tasks.Remove(elem)
		delete(s.tasksMap, taskID)
		return true, nil
	}

	return false, nil
}

func (s *MemoryTaskStore) Len(ctx context.Context) (int64, error) {
	s.mu.Lock()
	defer s.mu.Unlock()
	return int64(s.tasks.Len()), nil
}

// RedisTaskStore is a Redis-backed implementation of TaskStore.
type RedisTaskStore struct {
	client   *redis.Client
	queueKey string
}

func NewRedisTaskStore(client *redis.Client, queueName string) *RedisTaskStore {
	return &RedisTaskStore{
		client:   client,
		queueKey: fmt.Sprintf("taskqueue:%s", queueName),
	}
}

func (s *RedisTaskStore) AddTask(ctx context.Context, task *Task) error {
	data, err := json.Marshal(task)
	if err != nil {
		return err
	}
	return s.client.RPush(ctx, s.queueKey, data).Err()
}

func (s *RedisTaskStore) PollTask(ctx context.Context, timeout time.Duration) (*Task, error) {
	if err := ctx.Err(); err != nil {
		return nil, err
	}

	// BLPOP returns [key, value]
	results, err := s.client.BLPop(ctx, timeout, s.queueKey).Result()
	if err != nil {
		if err == redis.Nil {
			return nil, nil
		}
		return nil, err
	}

	if len(results) < 2 {
		return nil, nil
	}

	var task Task
	if err := json.Unmarshal([]byte(results[1]), &task); err != nil {
		return nil, err
	}
	return &task, nil
}

func (s *RedisTaskStore) AckTask(ctx context.Context, taskID string) (bool, error) {
	// In Redis List model, pop removes it.
	// For reliability, we should use RPOPLPUSH to a processing queue, but keeping it simple for now.
	return false, nil
}

func (s *RedisTaskStore) Len(ctx context.Context) (int64, error) {
	return s.client.LLen(ctx, s.queueKey).Result()
}

type TaskQueue struct {
	name           string
	kind           TaskQueueKind
	store          TaskStore
	pollers        *list.List
	rateLimiter    *rate.Limiter
	metrics        *Metrics
	mu             sync.Mutex
	inFlight       map[string]*Task
	inFlightExpiry map[string]time.Time
	leaseTimeout   time.Duration
}

func NewTaskQueue(name string, kind TaskQueueKind, rateLimit float64, burst int, redisClient *redis.Client) *TaskQueue {
	var store TaskStore
	if redisClient != nil {
		store = NewRedisTaskStore(redisClient, name)
	} else {
		store = NewMemoryTaskStore()
	}

	return &TaskQueue{
		name:           name,
		kind:           kind,
		store:          store,
		pollers:        list.New(),
		rateLimiter:    rate.NewLimiter(rate.Limit(rateLimit), burst),
		metrics:        NewMetrics(),
		inFlight:       make(map[string]*Task),
		inFlightExpiry: make(map[string]time.Time),
		leaseTimeout:   DefaultLeaseTimeout,
	}
}

func (tq *TaskQueue) Name() string {
	return tq.name
}

func (tq *TaskQueue) Kind() TaskQueueKind {
	return tq.kind
}

func (tq *TaskQueue) Metrics() *Metrics {
	return tq.metrics
}

func (tq *TaskQueue) AddTask(task *Task) error {
	tq.mu.Lock()
	defer tq.mu.Unlock()

	// Check inFlight? No, just add to store.
	// Redis handles dupes? No, List allows dupes. Ideally we check existence.
	// For now, let's assume History service doesn't spam dupes.

	tq.metrics.TaskAdded()

	// Try dispatch directly to waiting poller first (optimization)
	if tq.tryDispatchLocked(task) {
		return nil
	}

	// Persist to Store
	if err := tq.store.AddTask(context.Background(), task); err != nil {
		return err
	}
	return nil
}

func (tq *TaskQueue) Poll(ctx context.Context, identity string) (*Task, error) {
	if err := ctx.Err(); err != nil {
		return nil, err
	}

	// First check rate limit
	tq.mu.Lock()
	if !tq.rateLimiter.Allow() {
		tq.mu.Unlock()
		return nil, ErrRateLimited
	}
	tq.mu.Unlock()

	// Polling logic:
	// 1. Check Store (Blocking Poll if Redis)
	// 2. If nothing, register as waiting poller?
	// Redis BLPOP blocks, so we don't need 'pollers' list for waiting if using Redis.
	// BUT, if using Memory, we do.
	// AND, we have 'tryDispatchLocked' which pushes to 'pollers'.

	// Hybrid approach:
	// If Redis: BLPOP.
	// If Memory: Check list, if empty, wait on chan.

	// Simplification: Always use Store.PollTask
	// But we need to handle context cancellation.

	for {
		task, err := tq.store.PollTask(ctx, time.Second)
		if err != nil {
			return nil, err
		}

		if task != nil {
			tq.mu.Lock()
			tq.inFlight[task.ID] = task
			tq.inFlightExpiry[task.ID] = time.Now().Add(tq.leaseTimeout)
			tq.mu.Unlock()

			tq.metrics.TaskDispatched()
			tq.metrics.RecordLatency(time.Since(task.ScheduledTime))
			return task, nil
		}

		if err := ctx.Err(); err != nil {
			return nil, err
		}
	}
}

func (tq *TaskQueue) CompleteTask(taskID string) bool {
	tq.mu.Lock()
	defer tq.mu.Unlock()

	if _, exists := tq.inFlight[taskID]; exists {
		delete(tq.inFlight, taskID)
		delete(tq.inFlightExpiry, taskID)
		return true
	}
	// Task might be in store but not in flight?
	// AckTask logic might be needed for Redis if we used RPOPLPUSH
	acked, err := tq.store.AckTask(context.Background(), taskID)
	if err != nil {
		return false
	}
	return acked
}

func (tq *TaskQueue) tryDispatchLocked(task *Task) bool {
	elem := tq.pollers.Front()
	if elem == nil {
		return false
	}

	poller := elem.Value.(*Poller)
	tq.pollers.Remove(elem)

	task.StartedTime = time.Now()
	tq.inFlight[task.ID] = task
	tq.inFlightExpiry[task.ID] = time.Now().Add(tq.leaseTimeout)
	poller.ResultCh <- task

	tq.metrics.TaskDispatched()
	tq.metrics.RecordLatency(time.Since(task.ScheduledTime))
	return true
}

func (tq *TaskQueue) PendingTaskCount() int {
	len, _ := tq.store.Len(context.Background())
	return int(len)
}

func (tq *TaskQueue) PollerCount() int {
	tq.mu.Lock()
	defer tq.mu.Unlock()
	return tq.pollers.Len()
}

func (tq *TaskQueue) RequeueExpiredTasks() int {
	tq.mu.Lock()
	defer tq.mu.Unlock()

	now := time.Now()
	requeued := 0

	for taskID, expiry := range tq.inFlightExpiry {
		if now.After(expiry) {
			task := tq.inFlight[taskID]
			delete(tq.inFlight, taskID)
			delete(tq.inFlightExpiry, taskID)

			// Re-add to store
			go tq.store.AddTask(context.Background(), task)
			requeued++
		}
	}

	return requeued
}

var ErrRateLimited = errRateLimited{}

type errRateLimited struct{}

func (errRateLimited) Error() string { return "rate limited" }
