# FleetOps visual-testing environment (local only)

A second, separate copy of FleetOps with **realistic fictional records**, used to check the Motorpool and Purchasing
screens in a real browser and to try every supported action **without touching `mulawin_v2`** or the normal app at
`http://localhost/RP_Mulawin_FMS_V2/`.

| Item | Value |
|---|---|
| Folder | `C:\xampp\fleetops_visual_test\` (outside `htdocs`: Apache never serves it) |
| URL | `http://127.0.0.1:8081/` (PHP built-in server, loopback only, web root = `public\`) |
| Database | `mulawin_visual_test` (disposable; ends in `_test`) |
| Session cookie | `fleetops_visual_test` on `127.0.0.1:8081` (separate from the normal app) |
| Logins | `C:\xampp\fleetops_visual_test\VISUAL_LOGINS.txt` (written by the seeding script; never in Git, never printed) |
| Seeding script | `scripts\visual_test_seed.php` (runs only inside the visual copy) |

## Safety rules

1. `mulawin_v2`, the project `.env`, Apache and XAMPP configuration are never changed.
2. The visual copy has its **own** `.env`; nothing is copied from the project `.env`.
3. The seeding script refuses to run unless **all** of these are true (checked before any write):
   - CLI, and the copy is not inside an `htdocs` folder;
   - the copy's `.env` contains `APP_ENV=visual_test` (read literally from the file);
   - `DB_NAME` is exactly `mulawin_visual_test` (ends in `_test`, is not `mulawin_v2`);
   - the open PDO connection reports `SELECT DATABASE() = mulawin_visual_test`;
   - the database is empty (`--fresh` drops and re-creates **only** `mulawin_visual_test`).
4. Records: base master data (accounts, trucks, drivers/helpers, clients, rates) is inserted directly like the test
   fixtures; **every workflow record and status goes through the existing module actions** as the right role. A step
   refused by an existing rule is reported as BLOCKED, never worked around. `seed_test_data.php` and
   `seed_masterlists.sql` (real company records) are not used.
5. `APP_ENV` is `visual_test`, **not** `test`: the routing code only allows test overrides when `APP_ENV=test`.
6. Mail settings are blank, so the copy sends no email. The server listens on `127.0.0.1` only.

## The visual copy's `.env`

```
APP_ENV=visual_test
APP_TIMEZONE=Asia/Manila
DB_HOST=127.0.0.1
DB_PORT=3306
DB_NAME=mulawin_visual_test
DB_USER=root
DB_PASS=
SESSION_NAME=fleetops_visual_test
MAIL_HOST=
MAIL_USERNAME=
MAIL_PASSWORD=
MAIL_FROM_ADDRESS=
ORS_API_KEY=
```
(`DB_USER` / `DB_PASS` = the local XAMPP MySQL account used for the isolated test databases.)

## Setup (each step is run only after approval)

1. **Copy the code** (one `robocopy /MIR` per folder; see *Why the copy cannot delete the .env, logins or storage*):
   ```
   robocopy C:\xampp\htdocs\RP_Mulawin_FMS_V2\backend  C:\xampp\fleetops_visual_test\backend  /MIR
   robocopy C:\xampp\htdocs\RP_Mulawin_FMS_V2\config   C:\xampp\fleetops_visual_test\config   /MIR
   robocopy C:\xampp\htdocs\RP_Mulawin_FMS_V2\database C:\xampp\fleetops_visual_test\database /MIR
   robocopy C:\xampp\htdocs\RP_Mulawin_FMS_V2\public   C:\xampp\fleetops_visual_test\public   /MIR
   robocopy C:\xampp\htdocs\RP_Mulawin_FMS_V2\scripts  C:\xampp\fleetops_visual_test\scripts  /MIR
   mkdir C:\xampp\fleetops_visual_test\storage\private, C:\xampp\fleetops_visual_test\storage\backups
   ```
2. **Write** `C:\xampp\fleetops_visual_test\.env` (content above).
3. **Create the empty database** (after a read-only check that it does not exist yet):
   ```sql
   CREATE DATABASE `mulawin_visual_test` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
   ```
4. **Seed** (installs the schema into the empty database, then creates the records; about 16 minutes because the
   existing 15-minute minimum pre-dispatch checklist duration is waited for):
   ```
   cd C:\xampp\fleetops_visual_test
   C:\xampp\php\php.exe scripts\visual_test_seed.php
   ```
5. **Start the server** (keep it running in its own window or as a background task):
   ```
   C:\xampp\php\php.exe -S 127.0.0.1:8081 -t C:\xampp\fleetops_visual_test\public
   ```
6. Open `http://127.0.0.1:8081/` and log in with an account from `VISUAL_LOGINS.txt`.

## Interruption and recovery (seeding step)

The full run takes about 16 minutes because the existing **15-minute minimum pre-dispatch checklist duration** is
waited for (it is never shortened or bypassed).

1. **Run it in the background with a log**, so progress survives the terminal:
   ```
   cd C:\xampp\fleetops_visual_test
   C:\xampp\php\php.exe scripts\visual_test_seed.php > seed_run.log 2>&1
   ```
   `seed_run.log` stays in the visual copy (outside Git).
2. **Stopping is safe at any moment** (Ctrl+C or stopping the background task): every action runs in its own
   database transaction, so a half-finished action is rolled back. At worst the dataset stops part-way.
3. **Exit code** of the script:

   | Code | Meaning |
   |---|---|
   | 0 | Completed; nothing blocked |
   | 2 | Refused by a safety check; nothing was written |
   | 3 | Completed, but some steps were BLOCKED by existing rules (listed at the end of the log with the reason) |
   | other (e.g. 255) | Crashed part-way |

4. **What to do, by where it stopped:**

   | Stopped during | State | Recovery |
   |---|---|---|
   | sections 1–9 (first ~1 minute: accounts, master data, repairs, parts, purchase orders) | partial dataset | `scripts\visual_test_seed.php --fresh` (re-creates ONLY `mulawin_visual_test`, seeds again) |
   | section 10, the wait (log shows `waiting …`) | everything complete except the trip's checklist, dispatch, incident and assessment; the trip is Pre-Assigned with its fund Released and checklist opened (a valid state) | `scripts\visual_test_seed.php --finish-trip` (or keep the dataset as it is) |
   | after "Summary", exit code 3 | complete, with gaps listed under BLOCKED | read the reasons; never edit the database by hand. Accept the gap, or meet the rule (for example wait for a time window) and run `--finish-trip` (trip steps) or `--fresh` (everything) |
   | crash (other exit code) | unknown | `--fresh` |

5. **`--finish-trip`** completes the incident path of exactly ONE interrupted trip and creates nothing else:
   - same safety checks (`APP_ENV=visual_test`, `DB_NAME=mulawin_visual_test`, connected database verified before
     any write); needs an already seeded database; **never re-creates the database**;
   - finds the pending trip first and refuses when there is none or more than one;
   - re-reads the real status before every step and runs only the step that status allows: open the checklist (if not
     opened), fund request → approval → cash release (only from the fund's current status), wait for the remaining
     minimum checklist duration (database clock, as the rule), submit the checklist, wait for the Confirm Dispatch
     window (if it opens within 30 minutes; otherwise it stops and says when), dispatch, report the breakdown (only if
     this attempt has no incident), Major assessment by a mechanic who is timed in and Available;
   - every step is the existing module action called as the right role; a refused step is reported as BLOCKED;
   - running it again after success refuses ("no eligible pending trip"), so nothing is duplicated.
6. A normal run (without an option) on a database that already has records **refuses**, so a partial run is never
   mixed with a second one.

## Why the copy cannot delete the `.env`, logins or storage

`robocopy <src>\<folder> <dst>\<folder> /MIR` mirrors **only the inside of that one destination folder**: it adds,
replaces and deletes files under `C:\xampp\fleetops_visual_test\<folder>\` and nowhere else. The five commands name
only `backend`, `config`, `database`, `public` and `scripts`. The files that must survive live outside those folders:

| Kept file / folder | Location | Touched by a sync? |
|---|---|---|
| `.env` | `C:\xampp\fleetops_visual_test\.env` (root) | No: not inside a mirrored folder |
| `VISUAL_LOGINS.txt` | `C:\xampp\fleetops_visual_test\VISUAL_LOGINS.txt` (root) | No |
| uploaded photos / receipts | `C:\xampp\fleetops_visual_test\storage\` | No: `storage` is never a robocopy source or destination |

The source side (`C:\xampp\htdocs\RP_Mulawin_FMS_V2\...`) is only read. The project root, its `.env`, `storage`,
`tests` and `docs` are not copied. Before each sync the exact commands are shown for approval.

## Syncing new code (during Stage A / Stage B)

Re-run only the `robocopy` lines of the folders that changed (usually `public` and `backend`). The database, the
`.env`, the logins and `storage` stay as they are. Hard-refresh the browser (assets are versioned by file time).

## Starting again with a clean dataset

```
cd C:\xampp\fleetops_visual_test
C:\xampp\php\php.exe scripts\visual_test_seed.php --fresh
```
`--fresh` drops and re-creates only `mulawin_visual_test` (same guards), then seeds again. Uploaded files of the
previous run stay in `storage\private` (harmless); delete that folder's contents to tidy up.

## Removing the environment

1. Stop the PHP server (close its window / stop the background task).
2. `DROP DATABASE mulawin_visual_test;` (shown for approval first).
3. Delete `C:\xampp\fleetops_visual_test\`.

## What the dataset contains

- 9 staff accounts (one per role) with fictional Filipino names; email addresses use the reserved `.example` domain.
- 14 trucks (Wing Van, Container, Car Carrier; LTO-style plates), 8 drivers, 5 helpers, 2 clients with billing rates.
- 9 mechanics: timed in and Available, Assigned to repairs, timed out, never timed in, deactivated.
- Repairs: Reported, Under Inspection, Under Repair, Completed (awaiting verification), returned to service,
  verification failed (back to repair), cancelled; repair costs (labor, outside service).
- Parts requests: Requested, Partially Released, Not Available, Released, Cancelled.
- 18 truck parts with low-stock items, stock-in / stock-out movements and one adjustment.
- Purchasing budget for the month; purchase orders routed to Finance (PHP 10,000 and below) and Admin (above):
  Pending Approval, Approved (pending payment), Partially Received (partially paid), Received (paid),
  Closed with an undelivered balance (paid), Rejected, Rejected - Insufficient Budget.
- Operations: a Wing Van trip created, funded (request, approval, cash release), checklist, dispatched, breakdown
  incident reported and assessed MAJOR (trip For Reschedule, truck Under Maintenance).

## Limits

- Timestamps are the time of the seeding run (not rewritten). Only dates the forms let users choose (date reported,
  completion date, cost date) are earlier days, so activity feeds show one recent burst rather than months of history.
- Photos and receipts are plain-colour PNG images (no image library is needed).
- Any step refused by an existing rule is listed under BLOCKED at the end of the run; it is not forced.
