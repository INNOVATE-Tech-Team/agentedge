# Profile status tests (isolated only)

These tests cover profile completeness vs. intake submission, Needs Review holds, completion-link rules, the `20261105_profile_status` migration, the growth-network emails, and behaviour during a CRM outage. They run **only** in throwaway Docker containers on an `--internal` network, which has no route out. Every suite also refuses to start unless it finds the dev config and the schema-only template.

All data is synthetic: `@test.invalid` accounts, a SQLite database copied from production's *structure only* (no rows), and a throwaway MariaDB that stands in for the CRM with fake `tblstaff` and `tblre_transaction_agents` rows. The SendGrid key is blank, so no email can be sent.

| Container | Purpose |
|---|---|
| `agentedge-profile-dev` | the branch under test (mounted read-only at `/var/www/html`), entrypoint `apache2-foreground` |
| `agentedge-profile-base` | the code currently in production (for the side-by-side comparison), with this folder mounted at `/opt/tests` |
| `agentedge-profile-mockcrm` | `mariadb:10.11` on tmpfs; credentials come from container env vars (`MARIADB_USER`, `MARIADB_PASSWORD`, `MARIADB_DATABASE`). Generate a random password per environment and never reuse a real one. |
| `agentedge-profile-proxy` | optional; only needed for manual browsing, bound to `127.0.0.1` |

Each app container also needs the following, mounted from a support directory that is **not** in the repo:
- `/opt/dev-support/schema-template.db`: `sqlite_master` DDL copied from the live DB, no rows.
- A dev `config.php`:
  - `local_db_dir=/var/dev-data`
  - `sendgrid_key=''`
  - `db_host=agentedge-profile-mockcrm` plus the mock credentials
- `test_login.php` behind `/__test_login`, which accepts `@test.invalid` accounts only.

Run everything:

```
tests/profile_status/run_all.sh
```

It runs:
1. The onboarding regression on the baseline and on the branch.
2. A diff of the two: every `ONB-*` scenario must be identical, and `EXIST-*` must differ (that difference is the fix).
3. The migration tests.
4. The feature tests.
5. The CRM-outage tests. These run with the mock CRM stopped. The branch must pass; the baseline run is reported only, to document how production behaves today.
