# Kubernetes Deployment

This guide outlines how to deploy LinkFlow to a Kubernetes cluster using Helm (coming soon) or raw manifests.

## Architecture

We recommend deploying LinkFlow in a dedicated namespace `linkflow`.

### Components
-   **Deployments**:
    -   `linkflow-api` (Scale: 2+)
    -   `linkflow-worker` (Scale: 2+)
    -   `linkflow-queue` (Scale: 1+)
    -   `linkflow-frontend` (Scale: 2+)
    -   `linkflow-history` (Scale: 3+)
    -   `linkflow-matching` (Scale: 3+)
    -   `linkflow-timer` (Scale: 1+)
-   **Services**:
    -   `ClusterIP` for all internal services.
    -   `Ingress` for `linkflow-api` and `linkflow-frontend` (if using gRPC ingress).
-   **ConfigMaps/Secrets**: For environment variables.

## Deployment Manifests

### API Deployment

```yaml
apiVersion: apps/v1
kind: Deployment
metadata:
  name: linkflow-api
  namespace: linkflow
spec:
  replicas: 2
  selector:
    matchLabels:
      app: linkflow-api
  template:
    metadata:
      labels:
        app: linkflow-api
    spec:
      containers:
        - name: api
          image: linkflow/api:latest
          envFrom:
            - secretRef:
                name: linkflow-secrets
            - configMapRef:
                name: linkflow-config
          ports:
            - containerPort: 8000
          livenessProbe:
            httpGet:
              path: /api/v1/health
              port: 8000
```

*(Repeat similar patterns for engine services, ensuring gRPC ports are exposed)*

## Ingress Configuration

Use NGINX Ingress Controller for best compatibility.

```yaml
apiVersion: networking.k8s.io/v1
kind: Ingress
metadata:
  name: linkflow-api
  annotations:
    nginx.ingress.kubernetes.io/proxy-body-size: "10m"
spec:
  rules:
    - host: api.linkflow.io
      http:
        paths:
          - path: /
            pathType: Prefix
            backend:
              service:
                name: linkflow-api
                port:
                  number: 8000
```

## Helm Chart (Planned)

We are developing an official Helm chart to automate this setup.
