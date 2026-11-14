# Development showcase dataset

Connected, traceable **development fixtures** for demonstrating the Head Management screens and the departmental
workflows. They are NOT company history. Everything runs against an isolated `*_showcase` database, never `mulawin_v2`.

## Files

| File | Purpose |
|---|---|
| `run_showcase.php` | Safe launcher: copies the backend to a temporary folder with its own `.env` (target DB only, mail and map keys blank), runs the population, copies or deletes ONLY the attachments listed in the manifest. |
| `populate.php` | Entry point inside the copy: guards, duplicate prevention, one transaction, manifest. |
| `lib_sim.php` | Discrete-event simulation (steps in time order; `SET TIMESTAMP` per step), system-loader actors, labelled placeholder attachments. |
| `scenarios.php` | The scenarios: Aug 3 – now trips (A/B intake, funds, checklists, dispatch, incidents, completion, arrival checks), billing, invoices, collections, repairs, purchasing, payables. |
| `lib_verify.php` | Counts, reconciliation and lifecycle checks, Head Management reads, targeted cleanup. |
| `switch_env.php` | Reversible switch of `DB_NAME` in `.env` only (no copy of `.env` is made). |

## Commands (project root, XAMPP PHP)

```
php scripts/showcase/run_showcase.php --db=mulawin_v2_showcase --dry-run      # build in one transaction, roll back
php scripts/showcase/run_showcase.php --db=mulawin_v2_showcase --apply        # build and commit; writes the manifest
php scripts/showcase/run_showcase.php --db=mulawin_v2_showcase --verify       # read-only checks
php scripts/showcase/run_showcase.php --db=mulawin_v2_showcase --cleanup --confirm   # remove ONLY manifest records

php scripts/showcase/switch_env.php --status
php scripts/showcase/switch_env.php --to=showcase    # the app now reads mulawin_v2_showcase
php scripts/showcase/switch_env.php --to=original    # back to mulawin_v2
```

## Rules the scripts enforce

- Target must end with `_showcase`, must exist, and must not be the database the app currently uses.
- Every record is created through the application's own actions (role checks, status rules, reference generators,
  audit). Actors are inactive `sysloader_*` accounts (unusable password), never real staff.
- Required attachments are small PDF/PNG files that state they are non-official development placeholders.
- `--apply` refuses to run twice (system-loader accounts or a manifest already exist).
- The manifest (`C:/xampp/mulawin_backups/showcase/manifest_<db>.json`, outside the repository) lists every generated id,
  every attachment file and a snapshot of the pre-existing rows the workflows changed (clients, parts, budget, vehicles,
  crew, mechanics, notifications). Cleanup deletes only those ids and restores the snapshot; the schema is not touched.

## Prerequisite

The showcase copy needs migration `2026-11-07_07_trip_routing` (applied to `mulawin_v2_showcase` only, with approval).
`mulawin_v2` does not have it.
