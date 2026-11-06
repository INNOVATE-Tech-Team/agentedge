# Profile completeness + Needs Review: migration and rollback

Operator reference for the `20261105_profile_status` release. Every step that changes a live database or live files needs its own explicit approval at the time it is run. All scripts here are **CLI only**: over the web they return 404, they never load `auth.php` or sessions, and they send nothing.

Placeholders: `<db>` is the live `agentedge.db`, `<dir>` is an existing, writable directory that the web server can't serve.

## Order
1. **Pre-flight** (read-only): the live files match the tested versions, the schema is unchanged, and the migration state is `none`.
2. **Migration**: `php scripts/migrations/20261105_profile_status.php --db=<db>` (verify, read-only), then `--apply --backup-dir=<dir>`, then verify again (`STATUS: APPLIED`).
   - Additive only: 4 nullable or defaulted columns on `profile_completion_tokens`, the table `agent_review_holds`, and a unique partial index allowing one active hold per agent.
   - Existing completion links keep `expires_at` NULL, so they never auto-expire.
   - Takes a `VACUUM INTO` backup first. The apply is one transaction. Re-running is a no-op, and a half-applied database is refused.
3. **Seed holds** from a reviewed CSV kept **outside the repo**: `php scripts/review_holds_seed.php --db=<db> --csv=<file>` (dry run), then `--apply --by=<approver>`.
   - One write-locked transaction.
   - Skips agents already held, and agents who had a seeded hold before, even a resolved one.
   - Only inserts hold rows.
4. **Deploy the tested files** (never a branch that lacks live-only work), then **smoke test** read-only.

Until step 2 runs, the code degrades safely: old links keep working, new links and holds are disabled, and the app never alters tables itself.

## Rollback
**Roll back the code, not the data.**

- **A. Code only (preferred).** Restore the previous files. The database is untouched. Holds stay in `agent_review_holds` and reappear on redeploy. Links issued while live simply don't expire under the old code.
- **B. Failed migration.** A failed apply has already rolled itself back. If verify says `PARTIALLY APPLIED`, stop and investigate. Don't deploy.
- **C. Problem after use.** Do A, keep the schema and holds, fix in isolation, retest, redeploy. Correct wrong holds in the app (resolve with a note), never by restoring.
- **D. Schema rollback (only if the schema itself must go).** `php scripts/migrations/20261105_profile_status.php --db=<db> --rollback`
  - It refuses if any hold exists, or any link has an expiry, revocation or override.
  - With `--force --export-dir=<dir>` it takes the write lock, writes all of that data to a JSON export, reads the export back to verify it, and then drops, all in one transaction.
  - Link rows are never removed.
- **D2. Restore holds after D.** First re-apply the migration (step 2). Do **not** re-run the seed script first. Then:
  ```
  php scripts/review_holds_import.php --db=<db> --export=<rollback-export.json>      # dry run (read-only)
  php scripts/review_holds_import.php --db=<db> --export=<rollback-export.json> \
      --apply --approved-by=<approver> --expect-sha256=<sha256 printed by the dry run>
  ```
  - **Validates the whole export first.** Any problem means nothing is applied.
  - **Restores every hold verbatim,** active and resolved: original id, reason, notes, placed by/at, and resolved by/at with the resolution note. Resolved holds stay resolved.
  - **Idempotent.** Rows already present with identical content are skipped.
  - **Refuses on conflicts.** That covers an id used by a different hold, and an agent who already has hold rows that aren't in the export.
  - **One transaction, verified before commit.** An interrupted run applies nothing, and re-running completes it.
  - **Approval is tied to the export's sha256.**
  - **Limitation:** link expiry and revocation values in the export are reported but **not** restored.
- **E. Database restore: last resort (corruption only).** It overwrites every later change. Stop writes, take a fresh copy of the current database, get explicit approval, and reconcile afterwards.

## Tests
`tests/profile_status/run_all.sh` runs every suite in isolated containers only, with synthetic data and no outbound network. See `tests/profile_status/README.md`. The hold import is covered by `import_tests.php`.
