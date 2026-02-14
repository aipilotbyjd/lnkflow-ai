package history

import (
	"context"
	"errors"
	"log/slog"
	"sync"
	"time"

	apiv1 "github.com/linkflow/engine/api/gen/linkflow/api/v1"
	commonv1 "github.com/linkflow/engine/api/gen/linkflow/common/v1"
	historyv1 "github.com/linkflow/engine/api/gen/linkflow/history/v1"
	matchingv1 "github.com/linkflow/engine/api/gen/linkflow/matching/v1"
	"github.com/linkflow/engine/internal/history/engine"
	"github.com/linkflow/engine/internal/history/shard"
	"github.com/linkflow/engine/internal/history/types"
	"github.com/linkflow/engine/internal/history/visibility"
	"google.golang.org/protobuf/types/known/timestamppb"
)

var (
	ErrServiceNotRunning     = errors.New("history service is not running")
	ErrServiceAlreadyRunning = errors.New("history service is already running")
	ErrEventNotFound         = errors.New("event not found")
)

// EventStore defines the interface for storing and retrieving history events.
type EventStore interface {
	AppendEvents(ctx context.Context, key types.ExecutionKey, events []*types.HistoryEvent, expectedVersion int64) error
	GetEvents(ctx context.Context, key types.ExecutionKey, firstEventID, lastEventID int64) ([]*types.HistoryEvent, error)
}

// MutableStateStore defines the interface for storing workflow mutable state.
type MutableStateStore interface {
	GetMutableState(ctx context.Context, key types.ExecutionKey) (*engine.MutableState, error)
	UpdateMutableState(ctx context.Context, key types.ExecutionKey, state *engine.MutableState, expectedVersion int64) error
}

// ShardController manages shard ownership and distribution.
type ShardController interface {
	Start() error
	GetShardForExecution(key types.ExecutionKey) (shard.Shard, error)
	GetShardIDForExecution(key types.ExecutionKey) int32
	Stop()
}

// Metrics provides hooks for observability.
type Metrics interface {
	RecordEventRecorded(eventType types.EventType)
	RecordEventRetrieved(count int)
	RecordServiceLatency(operation string, duration time.Duration)
}

// noopMetrics is a no-op implementation of Metrics.
type noopMetrics1 struct{}

func (noopMetrics1) RecordEventRecorded(types.EventType)        {}
func (noopMetrics1) RecordEventRetrieved(int)                   {}
func (noopMetrics1) RecordServiceLatency(string, time.Duration) {}

// Service provides workflow history management capabilities.
type Service struct {
	shardController ShardController
	eventStore      EventStore
	stateStore      MutableStateStore
	visibilityStore visibility.Store // Added visibility store
	matchingClient  matchingv1.MatchingServiceClient
	historyEngine   *engine.Engine
	metrics         Metrics
	logger          *slog.Logger

	running bool
	mu      sync.RWMutex
}

// Config holds configuration for the history service.
type Config struct {
	ShardController ShardController
	EventStore      EventStore
	StateStore      MutableStateStore
	VisibilityStore visibility.Store // Added visibility store
	MatchingClient  matchingv1.MatchingServiceClient
	Logger          *slog.Logger
	Metrics         Metrics
}

// NewService creates a new history service with default config.
func NewService(
	shardController ShardController,
	eventStore EventStore,
	stateStore MutableStateStore,
	visibilityStore visibility.Store,
	matchingClient matchingv1.MatchingServiceClient,
	logger *slog.Logger,
) *Service {
	return NewServiceWithConfig(Config{
		ShardController: shardController,
		EventStore:      eventStore,
		StateStore:      stateStore,
		VisibilityStore: visibilityStore,
		MatchingClient:  matchingClient,
		Logger:          logger,
	})
}

// NewServiceWithConfig creates a new history service with full configuration.
func NewServiceWithConfig(cfg Config) *Service {
	if cfg.Logger == nil {
		cfg.Logger = slog.Default()
	}
	metrics := cfg.Metrics
	if metrics == nil {
		metrics = noopMetrics1{}
	}
	return &Service{
		shardController: cfg.ShardController,
		eventStore:      cfg.EventStore,
		stateStore:      cfg.StateStore,
		visibilityStore: cfg.VisibilityStore,
		historyEngine:   engine.NewEngine(cfg.Logger),
		metrics:         metrics,
		logger:          cfg.Logger,
		running:         false,
	}
}

func (s *Service) Start(ctx context.Context) error {
	s.mu.Lock()
	defer s.mu.Unlock()

	if s.running {
		return ErrServiceAlreadyRunning
	}

	s.logger.Info("starting history service")

	if s.shardController != nil {
		if err := s.shardController.Start(); err != nil {
			return err
		}
	}

	s.running = true
	return nil
}

func (s *Service) Stop(ctx context.Context) error {
	s.mu.Lock()
	defer s.mu.Unlock()

	if !s.running {
		return nil
	}

	s.logger.Info("stopping history service")

	if s.shardController != nil {
		s.shardController.Stop()
	}

	s.running = false
	return nil
}

func (s *Service) IsRunning() bool {
	s.mu.RLock()
	defer s.mu.RUnlock()
	return s.running
}

// RecordEvent is legacy/direct event recording. Kept for backward compatibility or direct calls.
func (s *Service) RecordEvent(ctx context.Context, key types.ExecutionKey, event *types.HistoryEvent) error {
	// Re-route to standard event processing which includes task dispatching
	return s.processEvents(ctx, key, []*types.HistoryEvent{event})
}

// processEvents is the core event processing loop that persists events and dispatches tasks
func (s *Service) processEvents(ctx context.Context, key types.ExecutionKey, events []*types.HistoryEvent) error {
	start := time.Now()
	defer func() {
		s.metrics.RecordServiceLatency("ProcessEvents", time.Since(start))
	}()

	s.mu.RLock()
	running := s.running
	s.mu.RUnlock()

	if !running {
		return ErrServiceNotRunning
	}

	_, err := s.shardController.GetShardForExecution(key)
	if err != nil {
		return err
	}

	state, err := s.stateStore.GetMutableState(ctx, key)
	if err != nil {
		if errors.Is(err, types.ErrExecutionNotFound) {
			// Create new mutable state if it doesn't exist
			state = engine.NewMutableState(&types.ExecutionInfo{
				NamespaceID: key.NamespaceID,
				WorkflowID:  key.WorkflowID,
				RunID:       key.RunID,
			})
		} else {
			return err
		}
	}

	expectedVersion := state.DBVersion

	// Apply all events to state and assign IDs
	for _, event := range events {
		if event.EventID == 0 {
			event.EventID = state.NextEventID
		}
		if err := s.historyEngine.ProcessEvent(state, event); err != nil {
			return err
		}
	}

	// Persist events
	if err := s.eventStore.AppendEvents(ctx, key, events, expectedVersion); err != nil {
		return err
	}

	state.DBVersion++

	// Update mutable state
	if err := s.stateStore.UpdateMutableState(ctx, key, state, expectedVersion); err != nil {
		s.logger.Warn("failed to update mutable state", "error", err, "workflow_id", key.WorkflowID)
		return err
	}

	// Metrics
	for _, event := range events {
		s.metrics.RecordEventRecorded(event.EventType)
	}

	// Record Visibility
	if s.visibilityStore != nil {
		for _, event := range events {
			s.recordVisibility(ctx, key, event, state)
		}
	}

	// Dispatch tasks to Matching Service based on new state/events
	if s.matchingClient != nil {
		// We dispatch tasks for the LAST event usually, or iterate all
		for _, event := range events {
			if err := s.dispatchTasks(ctx, key, event, state); err != nil {
				s.logger.Error("failed to dispatch tasks to matching", "error", err)
			}
		}
	}

	return nil
}

func (s *Service) recordVisibility(ctx context.Context, key types.ExecutionKey, event *types.HistoryEvent, state *engine.MutableState) {
	switch event.EventType {
	case types.EventTypeExecutionStarted:
		attr := event.Attributes.(*historyv1.HistoryEvent_ExecutionStartedAttributes)
		s.visibilityStore.RecordWorkflowExecutionStarted(ctx, &visibility.RecordWorkflowExecutionStartedRequest{
			NamespaceID:  key.NamespaceID,
			Execution:    &commonv1.WorkflowExecution{WorkflowId: key.WorkflowID, RunId: key.RunID},
			WorkflowType: &apiv1.WorkflowType{Name: state.ExecutionInfo.WorkflowTypeName}, // Simplified
			StartTime:    event.Timestamp,
			Status:       commonv1.ExecutionStatus_EXECUTION_STATUS_RUNNING,
			Memo:         attr.ExecutionStartedAttributes.Memo,
		})

	case types.EventTypeExecutionCompleted:
		s.visibilityStore.RecordWorkflowExecutionClosed(ctx, &visibility.RecordWorkflowExecutionClosedRequest{
			NamespaceID:  key.NamespaceID,
			Execution:    &commonv1.WorkflowExecution{WorkflowId: key.WorkflowID, RunId: key.RunID},
			WorkflowType: &apiv1.WorkflowType{Name: state.ExecutionInfo.WorkflowTypeName},
			CloseTime:    event.Timestamp,
			Status:       commonv1.ExecutionStatus_EXECUTION_STATUS_COMPLETED,
		})

	case types.EventTypeExecutionFailed:
		s.visibilityStore.RecordWorkflowExecutionClosed(ctx, &visibility.RecordWorkflowExecutionClosedRequest{
			NamespaceID:  key.NamespaceID,
			Execution:    &commonv1.WorkflowExecution{WorkflowId: key.WorkflowID, RunId: key.RunID},
			WorkflowType: &apiv1.WorkflowType{Name: state.ExecutionInfo.WorkflowTypeName},
			CloseTime:    event.Timestamp,
			Status:       commonv1.ExecutionStatus_EXECUTION_STATUS_FAILED,
		})
	}
}

// RespondWorkflowTaskCompleted processes decisions from the workflow worker
func (s *Service) RespondWorkflowTaskCompleted(ctx context.Context, req *historyv1.RespondWorkflowTaskCompletedRequest) (*historyv1.RespondWorkflowTaskCompletedResponse, error) {
	key := types.ExecutionKey{
		NamespaceID: req.Namespace,
		WorkflowID:  req.WorkflowExecution.WorkflowId,
		RunID:       req.WorkflowExecution.RunId,
	}

	// 1. Validate WorkflowTaskCompleted
	// In a real system, we'd check if the task_token (scheduledEventID) matches the current pending workflow task.
	// For now, we assume it's valid.

	newEvents := []*types.HistoryEvent{}

	// Event: WorkflowTaskCompleted
	completedEvent := &types.HistoryEvent{
		EventType: types.EventTypeWorkflowTaskCompleted,
		Attributes: &types.WorkflowTaskCompletedAttributes{
			ScheduledEventID: req.TaskToken,
			Identity:         req.Identity,
			BinaryChecksum:   req.BinaryChecksum,
		},
	}
	newEvents = append(newEvents, completedEvent)

	// Process Commands
	for _, cmd := range req.Commands {
		switch cmd.CommandType {
		case historyv1.CommandType_COMMAND_TYPE_SCHEDULE_ACTIVITY_TASK:
			attr := cmd.GetScheduleActivityTaskAttributes()

			scheduledEvent := &types.HistoryEvent{
				EventType: types.EventType(commonv1.EventType_EVENT_TYPE_NODE_SCHEDULED),
				Attributes: &historyv1.HistoryEvent_NodeScheduledAttributes{
					NodeScheduledAttributes: &historyv1.NodeScheduledEventAttributes{
						NodeId:    attr.NodeId,
						NodeType:  attr.NodeType,
						Name:      attr.Name,
						TaskQueue: &apiv1.TaskQueue{Name: attr.TaskQueue, Kind: commonv1.TaskQueueKind_TASK_QUEUE_KIND_NORMAL},
						Input:     attr.Input,
					},
				},
			}
			newEvents = append(newEvents, scheduledEvent)

		case historyv1.CommandType_COMMAND_TYPE_COMPLETE_WORKFLOW_EXECUTION:
			attr := cmd.GetCompleteWorkflowExecutionAttributes()
			completeEvent := &types.HistoryEvent{
				EventType: types.EventType(commonv1.EventType_EVENT_TYPE_EXECUTION_COMPLETED),
				Attributes: &historyv1.HistoryEvent_ExecutionCompletedAttributes{
					ExecutionCompletedAttributes: &historyv1.ExecutionCompletedEventAttributes{
						Result: attr.Result,
					},
				},
			}
			newEvents = append(newEvents, completeEvent)

		case historyv1.CommandType_COMMAND_TYPE_FAIL_WORKFLOW_EXECUTION:
			attr := cmd.GetFailWorkflowExecutionAttributes()
			failEvent := &types.HistoryEvent{
				EventType: types.EventType(commonv1.EventType_EVENT_TYPE_EXECUTION_FAILED),
				Attributes: &historyv1.HistoryEvent_ExecutionFailedAttributes{
					ExecutionFailedAttributes: &historyv1.ExecutionFailedEventAttributes{
						Failure: attr.Failure,
					},
				},
			}
			newEvents = append(newEvents, failEvent)
		}
	}

	if err := s.processEvents(ctx, key, newEvents); err != nil {
		return nil, err
	}

	return &historyv1.RespondWorkflowTaskCompletedResponse{ActivityTasksScheduled: true}, nil
}

func (s *Service) RespondWorkflowTaskFailed(ctx context.Context, req *historyv1.RespondWorkflowTaskFailedRequest) (*historyv1.RespondWorkflowTaskFailedResponse, error) {
	key := types.ExecutionKey{
		NamespaceID: req.Namespace,
		WorkflowID:  req.WorkflowExecution.WorkflowId,
		RunID:       req.WorkflowExecution.RunId,
	}

	event := &types.HistoryEvent{
		EventType: types.EventTypeWorkflowTaskFailed,
		Attributes: &types.WorkflowTaskFailedAttributes{
			ScheduledEventID: req.TaskToken,
			Identity:         req.Identity,
			FailureReason:    req.Failure.GetMessage(),
		},
	}

	if err := s.processEvents(ctx, key, []*types.HistoryEvent{event}); err != nil {
		return nil, err
	}
	return &historyv1.RespondWorkflowTaskFailedResponse{}, nil
}

func (s *Service) RespondActivityTaskCompleted(ctx context.Context, req *historyv1.RespondActivityTaskCompletedRequest) (*historyv1.RespondActivityTaskCompletedResponse, error) {
	key := types.ExecutionKey{
		NamespaceID: req.Namespace,
		WorkflowID:  req.WorkflowExecution.WorkflowId,
		RunID:       req.WorkflowExecution.RunId,
	}

	// Event: ActivityTaskCompleted (NodeCompleted)
	event := &types.HistoryEvent{
		EventType: types.EventType(commonv1.EventType_EVENT_TYPE_NODE_COMPLETED),
		Attributes: &historyv1.HistoryEvent_NodeCompletedAttributes{
			NodeCompletedAttributes: &historyv1.NodeCompletedEventAttributes{
				ScheduledEventId: req.ScheduledEventId,
				Result:           req.Result,
				Identity:         req.Identity,
			},
		},
	}

	// Also Schedule a new WorkflowTask to wake up the decider
	// We need to know the workflow's task queue. We can get it from MutableState in processEvents
	// But we need to create the event here.
	// Actually, processEvents should handle the "auto-scheduling" of WorkflowTask when a Node completes.
	// Let's rely on dispatchTasks logic for that.

	if err := s.processEvents(ctx, key, []*types.HistoryEvent{event}); err != nil {
		return nil, err
	}

	return &historyv1.RespondActivityTaskCompletedResponse{}, nil
}

func (s *Service) RespondActivityTaskFailed(ctx context.Context, req *historyv1.RespondActivityTaskFailedRequest) (*historyv1.RespondActivityTaskFailedResponse, error) {
	key := types.ExecutionKey{
		NamespaceID: req.Namespace,
		WorkflowID:  req.WorkflowExecution.WorkflowId,
		RunID:       req.WorkflowExecution.RunId,
	}

	event := &types.HistoryEvent{
		EventType: types.EventType(commonv1.EventType_EVENT_TYPE_NODE_FAILED),
		Attributes: &historyv1.HistoryEvent_NodeFailedAttributes{
			NodeFailedAttributes: &historyv1.NodeFailedEventAttributes{
				ScheduledEventId: req.ScheduledEventId,
				Failure:          req.Failure,
				Identity:         req.Identity,
			},
		},
	}

	if err := s.processEvents(ctx, key, []*types.HistoryEvent{event}); err != nil {
		return nil, err
	}

	return &historyv1.RespondActivityTaskFailedResponse{}, nil
}

func (s *Service) dispatchTasks(ctx context.Context, key types.ExecutionKey, event *types.HistoryEvent, state *engine.MutableState) error {
	var taskType commonv1.TaskType
	var taskQueue string

	switch event.EventType {
	case types.EventTypeExecutionStarted:
		attrs, ok := event.Attributes.(*historyv1.HistoryEvent_ExecutionStartedAttributes)
		if !ok {
			return nil
		}
		taskType = commonv1.TaskType_TASK_TYPE_WORKFLOW_TASK
		taskQueue = attrs.ExecutionStartedAttributes.TaskQueue.Name

	case types.EventTypeNodeScheduled:
		// When a node is scheduled, we dispatch an Activity Task
		attrs, ok := event.Attributes.(*historyv1.HistoryEvent_NodeScheduledAttributes)
		if !ok {
			return nil
		}
		taskType = commonv1.TaskType_TASK_TYPE_ACTIVITY_TASK
		taskQueue = attrs.NodeScheduledAttributes.TaskQueue.Name

		// We need to include the "Config" in the task.
		// In a real system, we'd pass this through attributes.
		// The generic task struct in Matching service has a 'Config' field.
		// We should extract it from Input or attributes.

	case types.EventTypeNodeCompleted, types.EventTypeNodeFailed:
		// When a node completes/fails, we dispatch a Workflow Task to wake up the decider
		taskType = commonv1.TaskType_TASK_TYPE_WORKFLOW_TASK
		if state.ExecutionInfo != nil {
			taskQueue = state.ExecutionInfo.TaskQueue
		} else {
			return nil
		}

		// Optimization: If a workflow task is already scheduled/started, don't schedule another one?
		// For simplicity, we schedule. Matching service handles deduplication.

	case types.EventTypeWorkflowTaskScheduled:
		// Already handled by the creator of this event?
		// No, if we write this event, we must create the task.
		attrs, ok := event.Attributes.(*historyv1.HistoryEvent_WorkflowTaskScheduledAttributes)
		if !ok {
			return nil
		}
		taskType = commonv1.TaskType_TASK_TYPE_WORKFLOW_TASK
		taskQueue = attrs.WorkflowTaskScheduledAttributes.TaskQueue.Name

	default:
		return nil
	}

	// Create task request
	req := &matchingv1.AddTaskRequest{
		Namespace: key.NamespaceID,
		TaskQueue: &matchingv1.TaskQueue{
			Name: taskQueue,
			Kind: commonv1.TaskQueueKind_TASK_QUEUE_KIND_NORMAL,
		},
		TaskType: taskType,
		WorkflowExecution: &commonv1.WorkflowExecution{
			WorkflowId: key.WorkflowID,
			RunId:      key.RunID,
		},
		ScheduledEventId: event.EventID,
	}

	_, err := s.matchingClient.AddTask(ctx, req)
	return err
}

// GetHistory, GetMutableState, etc. remain unchanged...
func (s *Service) GetHistory(ctx context.Context, key types.ExecutionKey, firstEventID, lastEventID int64) ([]*types.HistoryEvent, error) {
	return s.eventStore.GetEvents(ctx, key, firstEventID, lastEventID)
}

func (s *Service) GetMutableState(ctx context.Context, key types.ExecutionKey) (*engine.MutableState, error) {
	return s.stateStore.GetMutableState(ctx, key)
}

func (s *Service) GetShardForExecution(key types.ExecutionKey) (shard.Shard, error) {
	return s.shardController.GetShardForExecution(key)
}

func (s *Service) GetShardIDForExecution(key types.ExecutionKey) int32 {
	return s.shardController.GetShardIDForExecution(key)
}

func (s *Service) ResetExecution(ctx context.Context, key types.ExecutionKey, reason string, resetEventID int64) (string, error) {
	return "", errors.New("reset execution not implemented")
}

func (s *Service) ListWorkflowExecutions(ctx context.Context, req *historyv1.ListWorkflowExecutionsRequest) (*historyv1.ListWorkflowExecutionsResponse, error) {
	if s.visibilityStore == nil {
		return nil, errors.New("visibility store not initialized")
	}

	visReq := &visibility.ListRequest{
		NamespaceID:   req.Namespace,
		PageSize:      int(req.PageSize),
		NextPageToken: req.NextPageToken,
		Query:         req.Query,
	}

	resp, err := s.visibilityStore.ListOpenWorkflowExecutions(ctx, visReq)
	if err != nil {
		return nil, err
	}

	executions := make([]*historyv1.WorkflowExecutionInfo, len(resp.Executions))
	for i, exec := range resp.Executions {
		startProto := timestamppb.New(exec.StartTime)
		closeProto := timestamppb.New(exec.CloseTime)

		executions[i] = &historyv1.WorkflowExecutionInfo{
			Execution:     exec.Execution,
			Type:          exec.Type,
			StartTime:     startProto,
			CloseTime:     closeProto,
			Status:        exec.Status,
			HistoryLength: exec.HistoryLength,
			Memo:          exec.Memo,
		}
	}

	return &historyv1.ListWorkflowExecutionsResponse{
		Executions:    executions,
		NextPageToken: resp.NextPageToken,
	}, nil
}
