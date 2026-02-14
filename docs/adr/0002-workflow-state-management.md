# ADR 0002: Event-Sourced Workflow State

## Status
Accepted

## Date
2026-02-05

## Context
Workflows need reliable state management that:
- Survives service restarts
- Allows replay for debugging
- Maintains audit trail
- Handles long-running workflows (days/weeks)

## Decision
We will use event sourcing for workflow state:
- The History service stores all workflow events
- State is reconstructed by replaying events
- Events are immutable and append-only

## Consequences

### Positive
- Complete audit trail of workflow execution
- Ability to replay and debug workflows
- Natural support for workflow versioning
- Resilient to failures (can resume from last event)

### Negative
- More storage required than simple state snapshots
- Potential performance impact for workflows with many events
- Complexity in event schema evolution

### Mitigations
- Implement periodic state snapshots for performance
- Use event versioning strategy for schema evolution
- Archive old completed workflow events
