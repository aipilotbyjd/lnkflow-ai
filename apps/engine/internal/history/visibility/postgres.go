package visibility

import (
	"context"
	"encoding/json"
	"time"

	"github.com/jackc/pgx/v5/pgxpool"
	apiv1 "github.com/linkflow/engine/api/gen/linkflow/api/v1"
	commonv1 "github.com/linkflow/engine/api/gen/linkflow/common/v1"
)

type PostgresStore struct {
	pool *pgxpool.Pool
}

func NewPostgresStore(pool *pgxpool.Pool) *PostgresStore {
	return &PostgresStore{pool: pool}
}

// Ensure the table exists:
// CREATE TABLE executions_visibility (
//     namespace_id VARCHAR(64) NOT NULL,
//     workflow_id VARCHAR(255) NOT NULL,
//     run_id VARCHAR(64) NOT NULL,
//     workflow_type VARCHAR(255) NOT NULL,
//     start_time TIMESTAMP NOT NULL,
//     close_time TIMESTAMP,
//     status INT NOT NULL,
//     history_length BIGINT,
//     memo BYTEA,
//     PRIMARY KEY (namespace_id, run_id)
// );
// CREATE INDEX idx_visibility_open ON executions_visibility (namespace_id, start_time DESC) WHERE status = 1;
// CREATE INDEX idx_visibility_closed ON executions_visibility (namespace_id, close_time DESC) WHERE status != 1;

func (s *PostgresStore) RecordWorkflowExecutionStarted(ctx context.Context, req *RecordWorkflowExecutionStartedRequest) error {
	memoBytes, _ := json.Marshal(req.Memo)

	_, err := s.pool.Exec(ctx, `
		INSERT INTO executions_visibility (
			namespace_id, workflow_id, run_id, workflow_type, start_time, status, memo
		) VALUES ($1, $2, $3, $4, $5, $6, $7)
		ON CONFLICT (namespace_id, run_id) DO UPDATE SET
			status = $6, start_time = $5, memo = $7
	`,
		req.NamespaceID,
		req.Execution.WorkflowId,
		req.Execution.RunId,
		req.WorkflowType.Name,
		req.StartTime,
		int32(req.Status),
		memoBytes,
	)
	return err
}

func (s *PostgresStore) RecordWorkflowExecutionClosed(ctx context.Context, req *RecordWorkflowExecutionClosedRequest) error {
	memoBytes, _ := json.Marshal(req.Memo)

	_, err := s.pool.Exec(ctx, `
		UPDATE executions_visibility
		SET status = $1, close_time = $2, history_length = $3, memo = $4
		WHERE namespace_id = $5 AND run_id = $6
	`,
		int32(req.Status),
		req.CloseTime,
		req.HistoryLength,
		memoBytes,
		req.NamespaceID,
		req.Execution.RunId,
	)
	return err
}

func (s *PostgresStore) ListOpenWorkflowExecutions(ctx context.Context, req *ListRequest) (*ListResponse, error) {
	return s.listExecutions(ctx, req, true)
}

func (s *PostgresStore) ListClosedWorkflowExecutions(ctx context.Context, req *ListRequest) (*ListResponse, error) {
	return s.listExecutions(ctx, req, false)
}

func (s *PostgresStore) listExecutions(ctx context.Context, req *ListRequest, open bool) (*ListResponse, error) {
	var query string
	if open {
		query = `
			SELECT workflow_id, run_id, workflow_type, start_time, close_time, status, memo
			FROM executions_visibility
			WHERE namespace_id = $1 AND status = 1
			ORDER BY start_time DESC
			LIMIT $2 OFFSET $3
		`
	} else {
		query = `
			SELECT workflow_id, run_id, workflow_type, start_time, close_time, status, memo
			FROM executions_visibility
			WHERE namespace_id = $1 AND status != 1
			ORDER BY close_time DESC
			LIMIT $2 OFFSET $3
		`
	}

	// Simple offset pagination for now (should use token)
	offset := 0 // TODO: Decode token
	limit := req.PageSize
	if limit == 0 {
		limit = 100
	}

	rows, err := s.pool.Query(ctx, query, req.NamespaceID, limit, offset)
	if err != nil {
		return nil, err
	}
	defer rows.Close()

	var infos []*WorkflowExecutionInfo
	for rows.Next() {
		var wid, rid, wtype string
		var start, close *time.Time
		var status int32
		var memoBytes []byte

		if err := rows.Scan(&wid, &rid, &wtype, &start, &close, &status, &memoBytes); err != nil {
			return nil, err
		}

		info := &WorkflowExecutionInfo{
			Execution: &commonv1.WorkflowExecution{WorkflowId: wid, RunId: rid},
			Type:      &apiv1.WorkflowType{Name: wtype},
			Status:    commonv1.ExecutionStatus(status),
		}
		if start != nil {
			info.StartTime = *start
		}
		if close != nil {
			info.CloseTime = *close
		}

		if len(memoBytes) > 0 {
			var memo commonv1.Memo
			json.Unmarshal(memoBytes, &memo)
			info.Memo = &memo
		}

		infos = append(infos, info)
	}

	return &ListResponse{
		Executions: infos,
	}, nil
}
