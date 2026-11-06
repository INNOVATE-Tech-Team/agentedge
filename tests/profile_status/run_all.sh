#!/usr/bin/env bash
# Profile Status test run — ISOLATED DEV CONTAINERS ONLY.
#   agentedge-profile-base    : baseline = current production code (detached worktree)
#   agentedge-profile-dev     : feature branch
#   agentedge-profile-mockcrm : throwaway MariaDB with SYNTHETIC tblstaff /
#                               tblre_transaction_agents (stands in for the CRM)
# All sit on the --internal docker network profile-dev-internal (no outbound
# route); SendGrid key is blank; every suite rebuilds a schema-only SQLite DB
# with synthetic @test.invalid records. Nothing touches production or the live CRM.
set -u
DEV=agentedge-profile-dev
BASE=agentedge-profile-base
CRM=agentedge-profile-mockcrm
for c in $DEV $BASE $CRM; do
  if [ "$(docker inspect -f '{{.HostConfig.NetworkMode}}' $c 2>/dev/null)" != "profile-dev-internal" ]; then
    echo "REFUSING: $c is not on the isolated profile-dev-internal network"; exit 2
  fi
done
TMP=$(mktemp -d)
rc=0
crm_up() { docker start $CRM >/dev/null; for i in $(seq 1 30); do docker exec $CRM sh -c 'mariadb -u"$MARIADB_USER" -p"$MARIADB_PASSWORD" -e "select 1" "$MARIADB_DATABASE"' >/dev/null 2>&1 && return 0; sleep 2; done; echo "mock CRM did not come up"; return 1; }
crm_up || exit 2

echo "######## 1. Onboarding regression — BASELINE (production code)"
docker exec -u www-data $BASE php /opt/tests/regression_onboarding.php /tmp/reg.json || rc=1
docker exec $BASE cat /tmp/reg.json > $TMP/base.json

echo; echo "######## 2. Onboarding regression — FEATURE branch"
docker exec -u www-data $DEV php /var/www/html/tests/profile_status/regression_onboarding.php /tmp/reg.json || rc=1
docker exec $DEV cat /tmp/reg.json > $TMP/feature.json

echo; echo "######## 3. Baseline vs feature outcome comparison"
python3 -I - "$TMP/base.json" "$TMP/feature.json" <<'EOF' || rc=1
import json, sys
b, f = (json.load(open(p)) for p in sys.argv[1:3])
bad = 0
for k in sorted(set(b) | set(f)):
    same = b.get(k) == f.get(k)
    expect_same = k.startswith('ONB-')
    verdict = 'ok' if same == expect_same else 'UNEXPECTED'
    if verdict != 'ok': bad += 1
    print(f'  {k:8} {"IDENTICAL" if same else "DIFFERENT":9} ({"must be identical" if expect_same else "expected to differ: the bug fix"}) -> {verdict}')
    if not same:
        print('     baseline:', json.dumps(b.get(k), ensure_ascii=False))
        print('     feature: ', json.dumps(f.get(k), ensure_ascii=False))
sys.exit(1 if bad else 0)
EOF

echo; echo "######## 4. Migration — FEATURE branch"
docker exec -u www-data $DEV php /var/www/html/tests/profile_status/migration_tests.php || rc=1

echo; echo "######## 5. Feature tests — FEATURE branch"
docker exec -u www-data $DEV php /var/www/html/tests/profile_status/feature_tests.php || rc=1

echo; echo "######## 5b. Review-hold import / recovery — FEATURE branch"
docker exec -u www-data $DEV php /var/www/html/tests/profile_status/import_tests.php || rc=1

echo; echo "######## 6. CRM outage — mock CRM STOPPED"
docker stop $CRM >/dev/null
echo "--- FEATURE branch (must pass)"
docker exec -u www-data $DEV php /var/www/html/tests/profile_status/crm_outage_tests.php || rc=1
echo "--- BASELINE / production code (report only: documents current behaviour)"
docker exec -u www-data $BASE php /opt/tests/crm_outage_tests.php > $TMP/base-outage.txt 2>&1
grep -E "PASS|FAIL|passed" $TMP/base-outage.txt | sed 's/^/    /'
crm_up || rc=1

echo; [ $rc -eq 0 ] && echo "ALL SUITES PASSED" || echo "FAILURES — see above"
rm -rf "$TMP"
exit $rc
