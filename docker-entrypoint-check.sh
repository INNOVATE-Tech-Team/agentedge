#!/bin/sh
# Runs before Apache starts. Fails the container immediately if perfex-db
# isn't resolvable, instead of staying up and only failing the first time a
# page hits the Perfex DB (which is how the 2026-08-11 outage went unnoticed
# until Google login broke). With restart:unless-stopped, a failure here
# makes the container crash-loop — an obvious, loud signal in `docker ps`
# and `docker logs`, rather than a silently-broken-but-Running container.
set -e

echo "AgentEdge startup check: resolving perfex-db..."
i=0
until getent hosts perfex-db > /dev/null 2>&1; do
  i=$((i + 1))
  if [ "$i" -ge 5 ]; then
    echo "############################################################" >&2
    echo "FATAL: perfex-db is not resolvable after ${i} attempts." >&2
    echo "This container is most likely not attached to the perfex-net" >&2
    echo "Docker network. Fix with:" >&2
    echo "    docker network connect perfex-net agentedge" >&2
    echo "or redeploy via ./deploy.sh, which attaches it automatically." >&2
    echo "############################################################" >&2
    exit 1
  fi
  echo "  perfex-db not resolvable yet (attempt ${i}/5), retrying..."
  sleep 1
done
echo "perfex-db resolved OK."

exec apache2-foreground
