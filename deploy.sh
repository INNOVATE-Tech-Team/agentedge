#!/bin/bash
# Rebuild and redeploy the agentedge container.
#
# This exists because the container is not managed by docker-compose or any
# other IaC — it's a plain `docker build` + `docker run`. On 2026-08-11, a
# manual rebuild (to add GD/jpeg/webp support) recreated the container
# without reattaching it to perfex-net, the custom Docker network that gives
# it DNS resolution for perfex-db (the Perfex MySQL host). That silently
# broke every page/endpoint that reads the Perfex DB (Google login among
# them) until someone noticed. This script bakes in the perfex-net attach
# so a redeploy can't drop it again.
set -euo pipefail

IMAGE="agentedge-php:gd"
CONTAINER="agentedge"
NETWORK="perfex-net"
PORT="8095"
SRC_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

echo "==> Building $IMAGE from $SRC_DIR"
docker build -t "$IMAGE" "$SRC_DIR"

echo "==> Stopping/removing existing $CONTAINER (if any)"
docker rm -f "$CONTAINER" >/dev/null 2>&1 || true

echo "==> Starting $CONTAINER"
docker run -d \
  --name "$CONTAINER" \
  --restart unless-stopped \
  -p "0.0.0.0:${PORT}:80" \
  -v "${SRC_DIR}:/var/www/html" \
  "$IMAGE"

echo "==> Attaching $CONTAINER to $NETWORK"
docker network connect "$NETWORK" "$CONTAINER"

echo "==> Verifying perfex-db resolves from inside $CONTAINER"
for i in 1 2 3 4 5; do
  if docker exec "$CONTAINER" getent hosts perfex-db >/dev/null 2>&1; then
    echo "    OK: perfex-db resolves."
    break
  fi
  if [ "$i" -eq 5 ]; then
    echo "    FAILED: perfex-db still not resolvable after deploy. Check network/DNS manually." >&2
    exit 1
  fi
  sleep 1
done

echo "==> Deploy complete: $CONTAINER running on port ${PORT}, attached to ${NETWORK}"
