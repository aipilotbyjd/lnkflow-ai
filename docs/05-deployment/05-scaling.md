# Scaling Guide

LinkFlow is designed to scale horizontally. Here's how to identify bottlenecks and scale specific components.

## 1. API Layer
-   **Metric**: CPU usage > 70% or High Response Time.
-   **Action**: Add more `linkflow-api` replicas.
-   **Dependency**: Ensure Database connection pool allows for more connections.

## 2. Queue Workers
-   **Metric**: Queue Depth (Redis) increasing.
-   **Action**: Add more `linkflow-queue` replicas.
-   **Note**: This processes lightweight jobs like notifications, not workflow nodes.

## 3. Engine Workers
-   **Metric**: `ScheduleToStart` latency increasing (tasks waiting in queue).
-   **Action**: Add more `linkflow-engine-worker` replicas.
-   **Limit**: Bound by the external APIs you are calling (rate limits).

## 4. History Service
-   **Metric**: Database CPU high or Write Latency.
-   **Action**:
    1.  Increase Shard Count (requires migration).
    2.  Scale `linkflow-engine-history` replicas.
    3.  Upgrade Database instance.

## 5. Matching Service
-   **Metric**: Redis CPU high.
-   **Action**:
    1.  Scale `linkflow-engine-matching` replicas.
    2.  Upgrade Redis instance (or use Redis Cluster).

## 6. Database (PostgreSQL)
The database is often the ultimate bottleneck.
-   **Optimization**: Use Read Replicas for `Visibility` service queries.
-   **Partitioning**: Partition `history_events` table by time (e.g., monthly).
