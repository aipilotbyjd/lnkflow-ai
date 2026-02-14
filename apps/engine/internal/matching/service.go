package matching

import (
	"context"
	"errors"
	"log/slog"
	"sync"
	"time"

	"github.com/linkflow/engine/internal/matching/engine"
	"github.com/linkflow/engine/internal/matching/partition"
	"github.com/redis/go-redis/v9"
)

const (
	defaultRateLimit = 1000.0
	defaultBurst     = 100
)

type Service struct {
	partitionMgr *partition.Manager
	taskQueues   map[string]*engine.TaskQueue
	logger       *slog.Logger
	mu           sync.RWMutex

	stopCh  chan struct{}
	wg      sync.WaitGroup
	running bool
}

type Config struct {
	NumPartitions int32
	Replicas      int
	Logger        *slog.Logger
	RedisClient   *redis.Client
}

func NewService(cfg Config) *Service {
	if cfg.NumPartitions <= 0 {
		cfg.NumPartitions = 4
	}
	if cfg.Replicas <= 0 {
		cfg.Replicas = 100
	}
	if cfg.Logger == nil {
		cfg.Logger = slog.Default()
	}

	return &Service{
		partitionMgr: partition.NewManager(cfg.NumPartitions, cfg.Replicas, cfg.RedisClient),
		taskQueues:   make(map[string]*engine.TaskQueue),
		logger:       cfg.Logger,
	}
}

func (s *Service) AddTask(ctx context.Context, taskQueueName string, task *engine.Task) error {
	tq := s.GetOrCreateTaskQueue(taskQueueName, engine.TaskQueueKindNormal)
	if err := tq.AddTask(task); err != nil {
		if errors.Is(err, engine.ErrTaskExists) {
			s.logger.Warn("task already exists",
				slog.String("task_id", task.ID),
				slog.String("task_queue", taskQueueName),
			)
			return nil
		}

		s.logger.Error("failed to add task",
			slog.String("task_id", task.ID),
			slog.String("task_queue", taskQueueName),
			slog.String("error", err.Error()),
		)
		return err
	}

	return nil
}

func (s *Service) CompleteTaskByID(ctx context.Context, taskID string) error {
	s.mu.RLock()
	queues := make([]*engine.TaskQueue, 0, len(s.taskQueues))
	for _, tq := range s.taskQueues {
		queues = append(queues, tq)
	}
	s.mu.RUnlock()

	for _, tq := range queues {
		if tq.CompleteTask(taskID) {
			return nil
		}
	}

	return ErrTaskNotFound
}

func (s *Service) CompleteTask(ctx context.Context, taskQueueName string, taskID string) error {
	s.mu.RLock()
	tq, exists := s.taskQueues[taskQueueName]
	s.mu.RUnlock()

	if !exists {
		return ErrTaskQueueNotFound
	}

	if !tq.CompleteTask(taskID) {
		return ErrTaskNotFound
	}

	return nil
}

func (s *Service) PollTask(ctx context.Context, taskQueueName string, identity string) (*engine.Task, error) {
	s.mu.RLock()
	tq, exists := s.taskQueues[taskQueueName]
	s.mu.RUnlock()

	if !exists {
		// If queue is not in memory, we might still have it in Redis if persistence is enabled.
		// But s.taskQueues tracks active queues.
		// Let's try to "create" (load) it.
		tq = s.GetOrCreateTaskQueue(taskQueueName, engine.TaskQueueKindNormal)
	}

	task, err := tq.Poll(ctx, identity)
	if err != nil {
		return nil, err
	}

	return task, nil
}

func (s *Service) GetOrCreateTaskQueue(name string, kind engine.TaskQueueKind) *engine.TaskQueue {
	s.mu.RLock()
	tq, exists := s.taskQueues[name]
	s.mu.RUnlock()

	if exists {
		return tq
	}

	s.mu.Lock()
	defer s.mu.Unlock()

	if tq, exists = s.taskQueues[name]; exists {
		return tq
	}

	partition := s.partitionMgr.GetPartitionForTaskQueue(name)
	tq = partition.GetOrCreateTaskQueue(name, kind, defaultRateLimit, defaultBurst)
	s.taskQueues[name] = tq

	s.logger.Info("created task queue",
		slog.String("name", name),
		slog.Int("kind", int(kind)),
		slog.Int("partition", int(partition.ID)),
	)

	return tq
}

func (s *Service) GetTaskQueue(name string) (*engine.TaskQueue, error) {
	s.mu.RLock()
	defer s.mu.RUnlock()

	tq, exists := s.taskQueues[name]
	if !exists {
		return nil, ErrTaskQueueNotFound
	}
	return tq, nil
}

func (s *Service) PartitionManager() *partition.Manager {
	return s.partitionMgr
}

func (s *Service) Start(ctx context.Context) error {
	s.mu.Lock()
	if s.running {
		s.mu.Unlock()
		return nil
	}
	s.running = true
	s.stopCh = make(chan struct{})
	s.mu.Unlock()

	s.wg.Add(1)
	go s.runLeaseReaper(ctx)

	s.logger.Info("matching service started")
	return nil
}

func (s *Service) Stop() error {
	s.mu.Lock()
	if !s.running {
		s.mu.Unlock()
		return nil
	}
	s.running = false
	close(s.stopCh)
	s.mu.Unlock()

	s.wg.Wait()
	s.logger.Info("matching service stopped")
	return nil
}

func (s *Service) runLeaseReaper(ctx context.Context) {
	defer s.wg.Done()

	ticker := time.NewTicker(10 * time.Second)
	defer ticker.Stop()

	for {
		select {
		case <-ctx.Done():
			return
		case <-s.stopCh:
			return
		case <-ticker.C:
			s.requeueExpiredTasks()
		}
	}
}

func (s *Service) requeueExpiredTasks() {
	s.mu.RLock()
	queues := make([]*engine.TaskQueue, 0, len(s.taskQueues))
	for _, tq := range s.taskQueues {
		queues = append(queues, tq)
	}
	s.mu.RUnlock()

	totalRequeued := 0
	for _, tq := range queues {
		requeued := tq.RequeueExpiredTasks()
		totalRequeued += requeued
	}

	if totalRequeued > 0 {
		s.logger.Info("requeued expired tasks", slog.Int("count", totalRequeued))
	}
}
