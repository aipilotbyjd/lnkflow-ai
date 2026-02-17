package executor

import (
	"context"
	"encoding/json"
	"fmt"
	"log/slog"

	commonv1 "github.com/linkflow/engine/api/gen/linkflow/common/v1"
	historyv1 "github.com/linkflow/engine/api/gen/linkflow/history/v1"
	"github.com/linkflow/engine/internal/worker/adapter"
)

type WorkflowExecutor struct {
	historyClient    *adapter.HistoryClient
	logger           *slog.Logger
	executorRegistry *Registry
}

func NewWorkflowExecutor(client *adapter.HistoryClient, logger *slog.Logger) *WorkflowExecutor {
	return &WorkflowExecutor{
		historyClient: client,
		logger:        logger,
	}
}

func (e *WorkflowExecutor) SetRegistry(registry *Registry) {
	e.executorRegistry = registry
}

func (e *WorkflowExecutor) NodeType() string {
	return "workflow"
}

// Execute is now pure decision logic. It returns a list of Commands marshaled in Output.
func (e *WorkflowExecutor) Execute(ctx context.Context, req *ExecuteRequest) (*ExecuteResponse, error) {
	e.logger.Info("deciding workflow", slog.String("workflow_id", req.WorkflowID))

	// 1. Fetch History
	namespace := req.Namespace
	if namespace == "" {
		namespace = "default"
	}
	resp, err := e.historyClient.GetHistory(ctx, namespace, req.WorkflowID, req.RunID)
	if err != nil {
		return nil, fmt.Errorf("failed to fetch history: %w", err)
	}

	events := resp.GetHistory().GetEvents()
	if len(events) == 0 {
		return nil, fmt.Errorf("history is empty")
	}

	// 2. Parse Payload from ExecutionStarted
	var payload JobPayload
	var payloadFound bool

	for _, event := range events {
		if event.GetEventType() == commonv1.EventType_EVENT_TYPE_EXECUTION_STARTED {
			attr := event.GetExecutionStartedAttributes()
			// Assume payload is in first input
			if attr != nil && attr.GetInput() != nil && len(attr.GetInput().GetPayloads()) > 0 {
				inputData := attr.GetInput().GetPayloads()[0].GetData()
				if err := json.Unmarshal(inputData, &payload); err == nil {
					payloadFound = true
				}
			}
			break
		}
	}

	if !payloadFound {
		return nil, fmt.Errorf("workflow definition not found in execution input")
	}

	// 3. Replay History to build State
	nodeStates := make(map[string]string) // NodeID -> Status
	nodeOutputs := make(map[string][]byte)
	eventIDToNodeID := make(map[int64]string)

	for _, event := range events {
		switch event.GetEventType() {
		case commonv1.EventType_EVENT_TYPE_NODE_SCHEDULED:
			attr := event.GetNodeScheduledAttributes()
			nodeStates[attr.GetNodeId()] = "Scheduled"
			eventIDToNodeID[event.GetEventId()] = attr.GetNodeId()

		case commonv1.EventType_EVENT_TYPE_NODE_COMPLETED:
			attr := event.GetNodeCompletedAttributes()
			if nodeID, ok := eventIDToNodeID[attr.GetScheduledEventId()]; ok {
				nodeStates[nodeID] = "Completed"
				if attr.GetResult() != nil && len(attr.GetResult().GetPayloads()) > 0 {
					nodeOutputs[nodeID] = attr.GetResult().GetPayloads()[0].GetData()
				}
			}

		case commonv1.EventType_EVENT_TYPE_NODE_FAILED:
			attr := event.GetNodeFailedAttributes()
			if nodeID, ok := eventIDToNodeID[attr.GetScheduledEventId()]; ok {
				nodeStates[nodeID] = "Failed"
			}
		}
	}

	// 4. Decide Next Steps
	commands := []*historyv1.Command{}
	graph := payload.Workflow

	// Check for failed nodes first — fail the workflow immediately
	hasFailedNode := false
	var failureMessage string
	for nodeID, status := range nodeStates {
		if status == "Failed" {
			hasFailedNode = true
			failureMessage = fmt.Sprintf("node '%s' failed", nodeID)
			break
		}
	}

	if hasFailedNode {
		cmd := &historyv1.Command{
			CommandType: historyv1.CommandType_COMMAND_TYPE_FAIL_WORKFLOW_EXECUTION,
			Attributes: &historyv1.Command_FailWorkflowExecutionAttributes{
				FailWorkflowExecutionAttributes: &historyv1.FailWorkflowExecutionCommandAttributes{
					Failure: &commonv1.Failure{
						Message: failureMessage,
					},
				},
			},
		}
		commands = append(commands, cmd)

		outputBytes, err := json.Marshal(commands)
		if err != nil {
			return nil, err
		}
		return &ExecuteResponse{Output: outputBytes}, nil
	}

	allNodesCompleted := true
	nodesToSchedule := []Node{}
	inputs := make(map[string]json.RawMessage)

	for _, node := range graph.Nodes {
		if nodeStates[node.ID] != "Completed" {
			allNodesCompleted = false
		}

		// Skip already scheduled/completed/failed nodes
		if nodeStates[node.ID] != "" {
			continue
		}

		// Determine if this is a trigger node
		isTrigger := node.Type == "trigger_manual" || node.Type == "trigger_webhook" || node.Type == "trigger_schedule"

		// Find incoming edges
		var incomingEdges []Edge
		for _, edge := range graph.Edges {
			if edge.Target == node.ID {
				incomingEdges = append(incomingEdges, edge)
			}
		}

		if isTrigger || len(incomingEdges) == 0 {
			// Root node (trigger or no incoming edges) — schedule with trigger data
			nodesToSchedule = append(nodesToSchedule, node)
			triggerDataBytes, _ := json.Marshal(payload.TriggerData)
			inputs[node.ID] = triggerDataBytes
			continue
		}

		// Check if all dependencies are completed
		canRun := true
		mergedInput := make(map[string]json.RawMessage)
		for _, edge := range incomingEdges {
			if nodeStates[edge.Source] != "Completed" {
				canRun = false
				break
			}
			if output, ok := nodeOutputs[edge.Source]; ok {
				mergedInput[edge.Source] = output
			}
		}

		if canRun {
			nodesToSchedule = append(nodesToSchedule, node)
			if len(mergedInput) == 1 {
				// Single input — pass directly for backward compatibility
				for _, v := range mergedInput {
					inputs[node.ID] = v
				}
			} else {
				// Multiple inputs — merge into a keyed map
				mergedBytes, _ := json.Marshal(mergedInput)
				inputs[node.ID] = mergedBytes
			}
		}
	}

	// Generate ScheduleActivity Commands
	for _, node := range nodesToSchedule {
		inputData := inputs[node.ID]
		if inputData == nil {
			inputData = []byte("{}")
		}

		// Extract config from node
		var nodeData struct {
			Config json.RawMessage `json:"config"`
		}
		configBytes := node.Data
		if err := json.Unmarshal(node.Data, &nodeData); err == nil && len(nodeData.Config) > 0 {
			configBytes = nodeData.Config
		}
		if len(configBytes) == 0 {
			configBytes = []byte("{}")
		}

		envelopeBytes, err := json.Marshal(struct {
			Input         json.RawMessage      `json:"input"`
			Config        json.RawMessage      `json:"config"`
			NodeID        string               `json:"node_id"`
			Type          string               `json:"node_type"`
			Deterministic DeterministicContext `json:"deterministic"`
		}{
			Input:         json.RawMessage(inputData),
			Config:        json.RawMessage(configBytes),
			NodeID:        node.ID,
			Type:          node.Type,
			Deterministic: payload.Deterministic,
		})
		if err != nil {
			return nil, fmt.Errorf("failed to marshal activity envelope: %w", err)
		}

		cmd := &historyv1.Command{
			CommandType: historyv1.CommandType_COMMAND_TYPE_SCHEDULE_ACTIVITY_TASK,
			Attributes: &historyv1.Command_ScheduleActivityTaskAttributes{
				ScheduleActivityTaskAttributes: &historyv1.ScheduleActivityTaskCommandAttributes{
					NodeId:   node.ID,
					NodeType: node.Type,
					Name:     node.GetName(),
					Input: &commonv1.Payloads{
						Payloads: []*commonv1.Payload{{Data: envelopeBytes}},
					},
					TaskQueue: "default",
					Config:    configBytes,
				},
			},
		}
		commands = append(commands, cmd)
	}

	// 5. Check for Workflow Completion
	if allNodesCompleted {
		cmd := &historyv1.Command{
			CommandType: historyv1.CommandType_COMMAND_TYPE_COMPLETE_WORKFLOW_EXECUTION,
			Attributes: &historyv1.Command_CompleteWorkflowExecutionAttributes{
				CompleteWorkflowExecutionAttributes: &historyv1.CompleteWorkflowExecutionCommandAttributes{
					Result: &commonv1.Payloads{
						Payloads: []*commonv1.Payload{{Data: []byte(`{"status":"completed"}`)}},
					},
				},
			},
		}
		commands = append(commands, cmd)
	}

	// Marshal commands to Output
	outputBytes, err := json.Marshal(commands)
	if err != nil {
		return nil, err
	}

	return &ExecuteResponse{
		Output: outputBytes,
	}, nil
}
