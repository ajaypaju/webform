# Load generator + reconciliation. Node's built-in fetch is undici; no npm dependencies.
# docker-cli is for reconcile.mjs (rpk in the redpanda container, psql in the postgres container) via the host socket.
FROM node:22-alpine
RUN apk add --no-cache docker-cli
WORKDIR /app/loadtest
