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

	// Check if all nodes are done or if we need to schedule new ones
	allNodesCompleted := true
	nodesToSchedule := []Node{}
	inputs := make(map[string][]byte)

	// Check for Start Node
	var startNode *Node
	for _, node := range graph.Nodes {
		if nodeStates[node.ID] == "" && (node.Type == "trigger_manual" || node.Type == "trigger_webhook" || node.Type == "trigger_schedule") {
			startNode = &node
			break
		}
	}

	if startNode != nil {
		allNodesCompleted = false
		nodesToSchedule = append(nodesToSchedule, *startNode)
		triggerDataBytes, _ := json.Marshal(payload.TriggerData)
		inputs[startNode.ID] = triggerDataBytes
	} else {
		// Check dependencies
		for _, node := range graph.Nodes {
			if nodeStates[node.ID] != "Completed" {
				allNodesCompleted = false
			}

			// If already scheduled/completed, skip
			if nodeStates[node.ID] != "" {
				continue
			}

			// Check incoming edges
			canRun := true
			var input []byte

			incomingEdges := 0
			for _, edge := range graph.Edges {
				if edge.Target == node.ID {
					incomingEdges++
					if nodeStates[edge.Source] != "Completed" {
						canRun = false
						break
					}
					input = nodeOutputs[edge.Source] // Simple single input
				}
			}

			// If it's a root node (no incoming edges) but not a trigger?
			// In this graph model, usually triggers are roots.
			// If canRun is true, schedule it.
			if canRun && incomingEdges > 0 {
				nodesToSchedule = append(nodesToSchedule, node)
				inputs[node.ID] = input
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
					Config:    configBytes, // We added this field to Command
				},
			},
		}
		commands = append(commands, cmd)
	}

	// 5. Check for Workflow Completion
	if allNodesCompleted {
		// Find leaf nodes results? Or just complete.
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
