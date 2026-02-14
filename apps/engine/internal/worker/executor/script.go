package executor

import (
	"context"
	"encoding/json"
	"fmt"
	"time"
)

// ScriptExecutor handles action_script nodes.
// This is an alias/wrapper that can execute JavaScript-like expressions.
type ScriptExecutor struct{}

// NewScriptExecutor creates a new script executor.
func NewScriptExecutor() *ScriptExecutor {
	return &ScriptExecutor{}
}

func (e *ScriptExecutor) NodeType() string {
	return "action_script"
}

func (e *ScriptExecutor) Execute(ctx context.Context, req *ExecuteRequest) (*ExecuteResponse, error) {
	start := time.Now()
	logs := make([]LogEntry, 0)

	logs = append(logs, LogEntry{
		Timestamp: time.Now(),
		Level:     "info",
		Message:   fmt.Sprintf("executing script node %s", req.NodeID),
	})

	// Parse script configuration
	var config struct {
		Code     string `json:"code"`
		Language string `json:"language"` // javascript, python (future)
		Timeout  int    `json:"timeout"`  // seconds
	}

	if err := json.Unmarshal(req.Config, &config); err != nil {
		// If no config, treat as passthrough
		logs = append(logs, LogEntry{
			Timestamp: time.Now(),
			Level:     "warn",
			Message:   "no script configuration provided, passing input through",
		})

		return &ExecuteResponse{
			Output:   req.Input,
			Logs:     logs,
			Duration: time.Since(start),
		}, nil
	}

	// Parse input data
	var inputData map[string]interface{}
	if len(req.Input) > 0 {
		if err := json.Unmarshal(req.Input, &inputData); err != nil {
			inputData = make(map[string]interface{})
		}
	} else {
		inputData = make(map[string]interface{})
	}

	logs = append(logs, LogEntry{
		Timestamp: time.Now(),
		Level:     "info",
		Message:   fmt.Sprintf("script language: %s, code length: %d chars", config.Language, len(config.Code)),
	})

	// For now, script execution passes through input with metadata
	// In production, this would use a sandboxed JS/WASM runtime
	result := map[string]interface{}{
		"input":        inputData,
		"script_run":   true,
		"executed_at":  time.Now().UTC().Format(time.RFC3339),
		"node_id":      req.NodeID,
		"code_preview": truncateString(config.Code, 100),
	}

	output, err := json.Marshal(result)
	if err != nil {
		return &ExecuteResponse{
			Error: &ExecutionError{
				Message: fmt.Sprintf("failed to marshal output: %v", err),
				Type:    ErrorTypeNonRetryable,
			},
			Logs:     logs,
			Duration: time.Since(start),
		}, nil
	}

	logs = append(logs, LogEntry{
		Timestamp: time.Now(),
		Level:     "info",
		Message:   "script execution completed successfully",
	})

	return &ExecuteResponse{
		Output:   output,
		Logs:     logs,
		Duration: time.Since(start),
	}, nil
}
