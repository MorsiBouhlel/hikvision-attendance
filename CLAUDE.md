# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this repository is

This started as a **`src/`-only starter kit** description in `README.md`
(copy `src/` over a fresh `symfony/skeleton`). The repo now also contains a
complete, runnable Symfony 6.4 skeleton (`composer.json`, `config/`,
`public/index.php`, `bin/console`, `src/Kernel.php`) plus a full Docker setup,
so it can be run directly — see **Docker workflow** below. The README's
manual bootstrap sequence is kept for reference/for anyone who wants to copy
`src/` into their own pre-existing Symfony project instead.

Routes are declared via PHP attributes on controllers (`#[Route(...)]`);
`config/routes.yaml` imports `src/Controller/` with `type: attribute`.
`config/services.yaml` autowires `App\` (excluding `Entity/` and
`Kernel.php`) and binds `WebhookRegistrar::$appBaseUrl` to
`%env(APP_BASE_URL)%`.

A server-rendered Twig UI lives alongside the JSON API (see **Web UI**
below) — `symfony/twig-bundle`, `symfony/asset-mapper`, `symfony/form`,
`symfony/validator`, `symfony/security-csrf` are installed. Session is
enabled (`config/packages/framework.yaml`, required for CSRF + flash
messages) — don't disable it, the JSON API endpoints don't use it either
way.

**Flex recipes are unreliable in this environment** (GitHub API calls
during `composer require` intermittently 504 or silently no-op instead of
generating recipe files). After adding a package, always verify
`config/bundles.php` and `config/packages/*.yaml` actually got created —
don't assume Flex succeeded. Flex has also previously auto-injected a stray
Postgres `database` service + `database_data` volume into
`docker-compose.yml` and a conflicting `DATABASE_URL` line into `.env`
(from `doctrine/doctrine-bundle`'s recipe, irrelevant here since this
project already defines its own MySQL service) — if you see that pattern
again after a `composer require`, remove it; the project's `DATABASE_URL`
is the MySQL one near the top of `.env` and in `docker-compose.yml`'s
`php.environment`. In a real `.env` file the *last* definition of a
variable wins, so a stray duplicate silently overrides the working one.

## Docker workflow

Stack: `nginx` (public entrypoint) → `php` (PHP 8.2-FPM, Alpine) → `database`
(MySQL 8.0), plus `adminer` for DB inspection.

```bash
docker compose up -d --build      # build php image + start everything
docker compose exec php php bin/console doctrine:migrations:diff   # first time only: generate initial migration
docker compose exec php php bin/console doctrine:migrations:migrate --no-interaction
docker compose exec php php bin/console app:hikvision:register-webhook 1
docker compose exec php php bin/console app:hikvision:sync-employees 1
docker compose logs -f php
docker compose down                # stops containers, keeps db_data/vendor_data volumes
```

- App: http://localhost:8080 — Adminer: http://localhost:8081 (server
  `database`, user `app`, password `app`, db `hikvision_attendance`) — MySQL
  is also published on host port 3306.
- `APP_BASE_URL` defaults to `http://localhost:8080` (used by
  `register-webhook`); override via env var or `docker-compose.yml` if the
  backend needs to be reachable from real terminals on the LAN (see README
  point d'attention 1) — terminals must be able to reach whatever URL you
  register, not just `localhost`.
- `vendor/` is built into the image at build time, then copied into a named
  volume (`vendor_data`) on first container start by
  `docker/php/docker-entrypoint.sh` (which also `chown`s it to `www-data`,
  since it's copied from a root-owned image layer), because the bind mount
  `./:/var/www/html` would otherwise shadow it with the (vendor-less) host
  directory. If you change `composer.json`, the entrypoint only copies
  vendor once (guarded on `vendor/autoload_runtime.php` not existing), so an
  already-populated volume won't auto-refresh — run
  `docker compose exec php composer install` (or `require`) directly
  against the running container, which writes into the same mounted
  volume; no rebuild needed for a pure dependency change. The `php`
  container itself runs as root (matches the stock `php-fpm` image's own
  internal privilege drop for the FPM worker pool) — don't add a `USER
  www-data` / `su-exec` step back into the entrypoint, it breaks PHP-FPM's
  own `error_log` fd handling (`failed to open error_log (/proc/self/fd/2):
  Permission denied`, crash-looped when this was tried).
- No Xdebug/dev tooling wired in yet; add it to `Dockerfile`/`docker/php` if
  needed rather than to `composer.json` (`--no-dev` install in the image).

## Domain

A multi-device (pointeuse) attendance system for Hikvision ISAPI terminals
(DS-K1T341CMF or compatible). Devices push fingerprint/face punch events to
this backend via webhook; the backend computes daily check-in/check-out per
employee.

```
[Device 1] ─┐
[Device 2] ─┼─► POST /api/hikvision/webhook/{token} ─► AttendanceEvent (raw)
[Device N] ─┘                                                │
                                                               ▼
                                               AttendanceService::dailySummary()
                                                               │
                                                               ▼
                                               GET /api/attendance/today
```

## Architecture

**Entities** (`src/Entity/`):
- `Device` — one physical terminal (IP, port, admin credentials, a unique
  `webhookToken` auto-generated in the constructor via `random_bytes(16)`).
- `Employee` — a person, independent of any device.
- `DeviceEmployee` — pivot between `Device` and `Employee`. Necessary because
  the same employee can have a *different* `employeeNo` on each terminal if
  enrolled separately per device. Unique on `(device_id, employee_no)` and
  `(device_id, employee_id)`.
- `AttendanceEvent` — one raw punch, deduplicated per device by `serialNo`
  (unique constraint on `(device_id, serial_no)`). Stores the full decoded
  webhook payload in `rawPayload` (json column) for debugging/reprocessing.

**Webhook ingestion** (`src/Controller/HikvisionWebhookController.php`):
- `POST /api/hikvision/webhook/{token}` — the token in the URL *is* the auth
  mechanism (terminals can't easily do outbound Bearer auth), matched via
  `DeviceRepository::findByWebhookToken()`.
- `extractPayload()` handles three payload shapes from the terminal: raw
  JSON body, multipart form field (`event_log` or `Event`, JSON or XML), and
  a raw-body JSON fallback.
- `storeEvent()` maps firmware-specific payload keys (`employeeNoString`,
  `currentVerifyMode`, `AccessControllerEvent.subEventType`, etc.) to
  `AttendanceEvent` fields. **These exact key names are firmware-dependent**
  — the README explicitly calls out that you should trigger a real punch,
  inspect `raw_payload` in the DB, and adjust this method accordingly for
  the specific terminal model/firmware in use.
- Idempotency: looks up `findOneBy(['device' => ..., 'serialNo' => ...])`
  before persisting, to survive terminal retry behavior on network failure.
- **The webhook responds `200 OK` with an ISAPI-style JSON body
  (`{"statusCode":1,"statusString":"OK","subStatusCode":"ok"}`), not a bare
  `204`.** This is load-bearing, not stylistic: a real terminal was observed
  retrying its HTTP Listening Host test in a tight ~1.1s loop indefinitely
  when it received `204 No Content` — it apparently doesn't recognize that
  as a valid ack. Switching to `200` + this body stopped the loop
  immediately and the terminal then flushed its entire backlog of queued
  historical events in one burst. If you ever "simplify" this back to an
  empty 204, re-verify against real hardware, not just curl — the failure
  mode only shows up with an actual device attached.
- **Two different payload shapes exist for the same logical event**,
  confirmed by inspecting a real terminal's backlog flush: a "simple" shape
  with `employeeNoString`/`currentVerifyMode`/`attendanceStatus`/`serialNo`
  at the payload root, and a "nested" shape (seen on `AccessControllerEvent`
  major/minor style events) where all of those same keys live one level
  down under `payload['AccessControllerEvent']` instead. Both are legitimate
  — not a firmware quirk to special-case away. `AttendanceEventMapper::map()`
  reads a local `$ace = $payload['AccessControllerEvent'] ?? []` and checks
  both root and `$ace` for each field (see the method body). If you add a
  new field to the mapper, check both locations or you'll silently lose
  data for whichever shape you didn't test against — this exact bug (nested
  `employeeNoString` never resolving to an `Employee`) went unnoticed until
  a real device backlog flush surfaced ~28 affected historical rows.
- Payload → `AttendanceEvent` mapping lives in
  `src/Service/AttendanceEventMapper.php` (extracted so it's shared with the
  historical-events pull sync below — both formats use largely the same
  keys: `employeeNoString`, `dateTime`/`time`, `major`/`minor`,
  `currentVerifyMode`, `serialNo`).
- **Logging**: `symfony/monolog-bundle` is installed; `HikvisionWebhookController`
  logs every request (`app.INFO`), the parsed payload, and the resulting
  mapped event (employee id, occurred_at) — in `dev`, this goes to
  `var/log/dev.log` at `debug` level, which is what made diagnosing the
  issues above possible in the first place. Tail that file when debugging a
  "nothing arrived" report before assuming it's a network problem — it may
  well be arriving and just not doing what's expected.
- **Fixed pagination bug that used to cause missing employees** (root-caused
  and resolved): `HikvisionDigestClient::listUsers()` and `::searchEvents()`
  both advanced their `searchResultPosition` by the *requested* `$maxResults`
  each loop iteration. This terminal's `UserInfo/Search` silently caps
  results at 30 per page regardless of what's requested (confirmed via
  `GET /ISAPI/AccessControl/UserInfo/Capabilities` →
  `UserInfoSearchCond.maxResults.@max = 30`) while still reporting
  `responseStatusStrg: "MORE"` — so requesting `maxResults: 100` meant the
  position jumped 0 → 100 → 200 while the device only ever returned 30 at a
  time, skipping employeeNo 31 onward entirely (`totalMatches: 83` vs. the
  30 we were actually finding). Both methods now advance position by
  `count($list)` — the size actually received — not the requested page
  size. If you ever see a device apparently having far fewer enrolled users
  than expected, check `Capabilities` for a `maxResults.@max` below what's
  being requested before assuming it's a device-side limitation.
  This was root-caused live: `app:hikvision:sync-employees 1` went from
  finding 0 unlinked users to 53 after the fix, all 53 were linked, and a
  one-off relink pass (`employee_no` → `Employee` lookup, not committed as a
  permanent command — see git history if you need this pattern again) fixed
  2177 pre-existing `AttendanceEvent` rows that had `employee_id = NULL`
  with a populated `employee_no` from before their `DeviceEmployee` link
  existed. Total went from 30 → 83 linked employees in this deployment.
- **Known, accepted duplicate**: one real person ("Hachem Charfi") is
  enrolled twice on the terminal under two different `employeeNo`s (1 and
  61) — likely a re-enrollment (new badge/card) that was never cleaned up
  device-side. This produces two separate `Employee` rows; left as-is per
  user decision rather than merged. If this pattern recurs, dedup by name +
  fuzzy-matching isn't safe (real homonyms exist) — would need a manual
  merge UI or an explicit "these employeeNos are the same person" mapping,
  neither of which exists today.
- **`verifyMode`/`currentVerifyMode` is NOT the verification method actually
  used for a punch** — it's the set of methods the reader *accepts* (e.g.
  `"cardOrFaceOrFp"`, `"faceOrFpOrCardOrPw"`), confirmed by inspecting real
  payloads in this deployment. If you ever need the actual per-punch method
  (face vs. card), it's not stored as a field — you'd have to derive it
  from `rawPayload` (e.g. presence of `FaceRect` vs `cardNo`); this was
  tried and then deliberately removed from the UI/service, see git history
  if resurrecting it.
- **`attendanceStatus`** (`AttendanceEvent::$attendanceStatus`, extracted
  from `$payload['attendanceStatus']`) — a genuinely reliable field when
  the terminal sends it (`checkIn`/`checkOut`), confirmed on real payloads.
  Unlike `verifyMode` above, this one really does tell you what a specific
  punch was. Only present on ~28% of events in this deployment though (the
  rest are raw access-control events with no attendance intent attached) —
  see `AttendanceService::classifyPunches()` for how the gap is filled.

**Reading data** (`src/Controller/AttendanceController.php` +
`src/Service/AttendanceService.php`):
- `GET /api/attendance/today`, `GET /api/attendance/{date}` — per-employee
  daily summary via `AttendanceService::dailySummaryForAll()`.
- `GET /api/attendance/missing/today` — active employees with zero events
  today.
- **Check-in/check-out determination** (`AttendanceService::classifyPunches()`):
  an employee can punch multiple times a day for unrelated reasons (lunch
  break, stepping out, etc.), so "last punch of the day" is not reliably
  "check-out". The rule now depends on whether the `Employee` has a
  `WorkSchedule` assigned:
  - **No `WorkSchedule`** (the default for new employees): unchanged
    historical behavior — first punch of the day = check-in, last = check-out.
    Zero behavior change here, matches the existing "no schedule = simple
    logic" principle used throughout this feature area.
  - **With `WorkSchedule`**: each punch is classified check-in/check-out by,
    in priority order: (1) `AttendanceEvent::$attendanceStatus` when present
    (trust it directly), (2) otherwise whether the punch falls in the
    check-in half or check-out half of an expected window derived from
    `WorkSchedule::$startTime`/`$endTime` ± `$checkWindowMarginMinutes`
    (default 240 min), split at the window's midpoint. `check_in` = first
    punch classified check-in; `check_out` = **last** punch classified
    check-out (a later deduced check-out punch overrides an earlier
    explicit one — deliberate, confirmed with the user: "check-out" means
    "when they left for good," not their first exit of the day).
  - `WorkSchedule::$checkWindowMarginMinutes` is editable per schedule via
    `/horaires` — tune it if punches near the edges of the expected window
    are being misclassified for a given team.

**Talking to terminals** (`src/Service/HikvisionDigestClient.php` +
`HikvisionClientFactory`):
- Hikvision ISAPI requires HTTP Digest Auth (RFC 2617). Symfony's
  `HttpClientInterface` has no built-in digest support (unlike Guzzle), so
  `HikvisionDigestClient` hand-rolls the two-request handshake: first
  request without auth → 401 with `WWW-Authenticate` challenge → compute
  `Authorization: Digest ...` → replay request.
  - `deviceInfo()` → `GET /ISAPI/System/deviceInfo`
  - `registerWebhook()` → `PUT /ISAPI/Event/notification/httpHosts`
  - `listUsers()` → paginated `POST /ISAPI/AccessControl/UserInfo/Search`
    (loops while `responseStatusStrg === 'MORE'`)
  - `searchEvents(from, to)` → paginated `POST /ISAPI/AccessControl/AcsEvent`
    — pulls historical punch events already stored on the terminal (e.g.
    events that happened before the webhook was registered, or during a
    network outage). Same pagination pattern as `listUsers()`.
- `HikvisionClientFactory::forDevice(Device $device)` constructs a client
  bound to one device's credentials/base URL — always go through the
  factory rather than instantiating `HikvisionDigestClient` directly.

**Commands** (`src/Command/`) — thin CLI adapters, business logic lives in
`src/Service/` (shared with the web UI, see below):
- `app:hikvision:register-webhook {deviceId}` — pushes this backend's
  webhook URL to a terminal so it starts sending events. Delegates to
  `WebhookRegistrar::register()`. Run once per device.
- `app:hikvision:sync-employees {deviceId}` — imports `employeeNo`s already
  enrolled on a terminal, interactively prompting (`$io->confirm`) to create
  and link a new `Employee` + `DeviceEmployee` for each unmatched one.
  Delegates to `EmployeeSyncService::previewUnlinked()` /
  `::linkSelected()`.
- `app:hikvision:sync-events {deviceId} [--from=Y-m-d] [--to=Y-m-d]` —
  pulls historical punch events via `HikvisionDigestClient::searchEvents()`
  and maps them through `AttendanceEventMapper` (same idempotency guarantee
  as the webhook: existing `serialNo` rows are updated, not duplicated).
  Defaults to the last 30 days. Delegates to
  `AttendanceEventSyncService::sync()`. Use this once after registering a
  device (or after any outage) to backfill punches that happened before the
  webhook was wired up — the webhook only captures *future* events, it
  can't retroactively receive what already happened.

## Advanced attendance rules (HikCentral-style)

On top of the raw first/last-punch logic, `Employee` can optionally be
linked to a `WorkSchedule` (`src/Entity/WorkSchedule.php`: name, startTime,
endTime, toleranceMinutes, `crossesMidnight(): bool` for night shifts).
`Holiday` (company-wide, unique per date) and `Leave` (admin-entered, no
approval workflow by design — direct record only, fields:
employee/startDate/endDate/type/reason) round out the model.

This is **fully additive** — an `Employee` with `workSchedule = null` keeps
the original binary present/absent behavior exactly as before. Only
employees with a schedule assigned get lateness/overtime computed.

`AttendanceService` (`src/Service/AttendanceService.php`) is the single
place all this logic lives:
- `dailySummary()` return shape gained `status` (`present|late|absent|on_leave|holiday`),
  `late_minutes`, `early_leave_minutes`, `schedule` — appended to the
  original keys (`employee`, `date`, `check_in`, `check_out`,
  `worked_hours`, `events_count`), which are untouched. The JSON API
  (`AttendanceController`) picks these up automatically with zero code
  change since it just serializes whatever the service returns.
- `dailySummary()`/`dailySummaryForAll()`/`monthlySummary()` all funnel
  through a private `classifyDay()` — the late/absent/on-leave/holiday
  rules are written exactly once. Never duplicate that logic elsewhere.
- **N+1 discipline is load-bearing here**: `dailySummaryForAll()` calls
  `HolidayRepository::isHoliday()` and `LeaveRepository::employeeIdsOnLeave()`
  once per invocation (not per employee) and passes the results down.
  `monthlySummary()` goes further — 3 bulk queries total
  (`AttendanceEventRepository::findForAllEmployeesBetween()`,
  `LeaveRepository::findOverlapping()`, `HolidayRepository::findDatesBetween()`)
  cover the entire month for every employee, grouped into PHP arrays, then
  walked day-by-day with zero additional queries. If you touch this method,
  preserve that shape — a naive per-employee-per-day query loop here is
  `employees × ~30` queries and will not scale past a handful of people.
- Badge vocabulary reuses existing CSS classes, no new ones needed beyond
  `.nav__group-label`: `late` → `badge--warning` (this class existed
  unused before this feature — it was clearly meant for exactly this),
  `on_leave`/`holiday` → `badge--inactive`, `present`/`absent` unchanged.

**HR metrics: early leave + overtime in/out** (added for the HR
department's consumption of this data, not just internal dashboard use) —
`classifyDay()` gained 4 more keys: `is_early_leave` (bool|null),
`expected_hours` (float|null), `overtime_in_minutes`, `overtime_out_minutes`
(int|null). All `null` together exactly when `worked_hours` is `null`
(no `check_out`, or an absent/holiday/on_leave day) — no comparison is
possible without both punches, same nullability pattern as
`late_minutes`/`early_leave_minutes`.
- **`is_early_leave` is a different concept from `early_leave_minutes`** —
  don't conflate them. `early_leave_minutes` (pre-existing) is about *when*
  the check-out happened relative to `endTime`. `is_early_leave` (new) is
  about the *total hours worked* that day being below what was expected,
  regardless of when exactly the employee left — an employee could clock
  out exactly on time but still trigger `is_early_leave` if they arrived
  late and their total hours fell short. Deliberately **does not** affect
  `status` — no new badge-driving status value, `is_early_leave` is a
  secondary flag rendered as an extra `badge--warning` "Départ anticipé"
  chip next to the existing status badge, not a replacement for it (a day
  can be `status: late` *and* `is_early_leave: true` simultaneously).
- **Expected hours**: `expectedDailyHours(?WorkSchedule $schedule): float`
  — the assigned `WorkSchedule`'s `endTime - startTime` (via the shared
  `expectedWindow()` helper below, so `crossesMidnight()` night shifts are
  handled the same way as everywhere else), or a flat **8.0** if the
  employee has no `WorkSchedule` assigned at all. This was a deliberate
  product decision (not a technical default) — confirmed with the user:
  an unscheduled employee is still expected to work a standard 8h day for
  this specific comparison, even though every *other* rule in this file
  treats `workSchedule = null` as "skip advanced logic entirely."
  `is_early_leave` = `worked_hours < expectedDailyHours($schedule)`, no
  extra tolerance margin (confirmed with the user — any shortfall counts,
  unlike `late_minutes` which subtracts `toleranceMinutes` first).
- **`overtime_in_minutes`/`overtime_out_minutes`** are the mirror image of
  `late_minutes`/`early_leave_minutes`: time spent *outside* the expected
  window instead of *encroaching into* it. `overtime_in` = minutes the
  check-in happened *before* `expectedStart` (arriving early); `overtime_out`
  = minutes the check-out happened *after* `expectedEnd` (leaving late).
  Both computed in `computeScheduleDeltas()` alongside the existing two
  values (one method, one call site, all four numbers derived from the
  same `expectedStart`/`expectedEnd` pair — no duplicate window
  computation). No tolerance applied here either (confirmed with the
  user), consistent with `early_leave_minutes`'s existing no-tolerance
  behavior. Both `null` when there's no schedule (can't have "overtime
  outside a window" that doesn't exist) — this differs from
  `is_early_leave`, which *does* apply without a schedule via the 8h
  default; don't assume the two features share the same null conditions.
- **`expectedWindow(WorkSchedule $schedule, \DateTimeImmutable $date):
  array{0: \DateTimeImmutable, 1: \DateTimeImmutable}`** — extracted from
  what used to be duplicated inline in both `classifyPunches()` and
  `computeScheduleDeltas()` (the `expectedStart`/`expectedEnd` +
  `crossesMidnight()` `+1 day` block). Adding overtime calculations would
  have made that a 3rd/4th copy — refactored to a single private helper
  instead. Pure refactor, no behavior change to the pre-existing
  late/early-leave numbers; re-verified against real webhook data after
  the change (same late_minutes/early_leave_minutes values as before for
  known scenarios).
- `monthlySummary()`'s aggregation loop picked up 3 more running totals
  alongside the existing `late_count`/`late_minutes_total`/`absences`:
  `early_leave_count` (days where `is_early_leave === true`, strict
  comparison — `null` days don't count), `overtime_in_total`,
  `overtime_out_total` (both summed in minutes across the month). Same
  bulk-query discipline as the rest of this method — no new queries added,
  these are computed from the same `classifyDay()` call already happening
  per day per employee.
- CSV export (`AttendanceReportController::monthlyExport`) and
  `/rapports/mensuel` both gained 3 columns for these — "Départs
  anticipés", "Overtime entrée (min)", "Overtime sortie (min)" — fed
  directly from the 3 new `monthlySummary()` keys, no controller-side
  computation. `/api/attendance/*` (JSON) needed zero code changes: it
  already serializes whatever `classifyDay()`/`dailySummaryForAll()`
  return, so the 4 new keys just show up.
- Daily views (`templates/attendance/_summary_table.html.twig`, used by
  dashboard + historique, and `templates/employee/show.html.twig`'s
  per-employee history — the latter duplicates the table markup inline
  rather than including the former, pre-existing duplication, not
  introduced by this feature) both gained an "Overtime" column showing
  `+X min (entrée)` / `+Y min (sortie)` when either is non-zero, and the
  "Départ anticipé" chip described above. Monthly report and daily views
  intentionally show overtime differently: monthly/CSV keep in/out as two
  separate numeric columns (RH needs the raw split for payroll-type
  analysis), daily views combine them into one compact cell (a single day
  rarely has both, and the table is already wide).

**UI**: `/horaires` (`WorkScheduleController`), `/jours-feries`
(`HolidayController`), `/conges` (`LeaveController`) — all thin CRUD
following the `EmployeeController` pattern (index/new[/edit]/delete,
CSRF-protected POST actions). `Holiday`/`Leave` have no edit action
(delete + recreate is simpler for short, few-field records — deliberate,
not an oversight). `EmployeeType` gained a `workSchedule` `EntityType`
field (nullable, placeholder "Aucun (logique simple)").
`/rapports/mensuel` (`AttendanceReportController`) — on-screen monthly
summary per employee + `/rapports/mensuel/export` CSV download via
`StreamedResponse`/`fputcsv` (no new dependency — both are core
`symfony/http-foundation`/PHP).

## Department filter, dashboard auto-refresh, Telegram alerts

- **Department filter**: `department` on `Employee` is a real entity
  relation (`ManyToOne` to `Department`, nullable, `onDelete: 'SET NULL'`
  on the FK) — originally free-text, changed to a configurable entity so
  department names can't drift ("RH" vs "Ressources humaines" typos/dupes).
  `Department` (`src/Entity/Department.php`) is a plain configuration
  entity (`id`, `name` unique, inverse `OneToMany` to `Employee`) managed
  via `/departements` (`DepartmentController`, `ROLE_ADMIN` for
  new/edit/delete — same access pattern as `/horaires`: `index` stays
  under the catch-all `ROLE_VIEWER` rule). Deleting a department that
  still has employees attached **does not** block the deletion or cascade
  — the FK's `onDelete: 'SET NULL'` detaches them automatically
  (`department` becomes `null` on those `Employee` rows), enforced at the
  DB level so there's no risk of an application-code path forgetting to
  detach. A `<select name="department">` (values are department IDs, not
  names) backs the filter on `/`, `/historique`, `/rapports/mensuel` (+
  CSV export, same filter applied). `AttendanceService::dailySummaryForAll()`/
  `::monthlySummary()` both take an optional `?int $departmentId` —
  filtered in-memory via a private `activeEmployees()` helper comparing
  `getDepartment()?->getId()` (not a new repository query; the employee
  count here is small enough — ~83 — that in-memory filtering is simpler
  than adding a parameterized query). The JSON API
  (`AttendanceController::missingToday()`) serializes
  `$e->getDepartment()?->getName()`, not the entity itself — Symfony's
  `json()` can't serialize a Doctrine entity directly, this would 500 if
  you forget the `?->getName()` after touching this code.
- **Bulk employee assignment on `/departements/new`+`/edit` and
  `/horaires/new`+`/edit`**: both `DepartmentType`/`WorkScheduleType` have
  an unmapped `employees` field (`EntityType`, `multiple: true, expanded:
  true` — renders as one checkbox per active employee, `mapped: false`
  since the inverse `OneToMany` collection can't be persisted from that
  side by Symfony's form layer without explicit code). The corresponding
  controllers (`DepartmentController`/`WorkScheduleController`)
  synchronize the ManyToOne side by hand after `isValid()`: every selected
  employee gets `setDepartment($department)`/`setWorkSchedule($schedule)`,
  and on `edit()` specifically, employees that were in the entity's
  collection *before* submission but aren't in the new selection get
  `setDepartment(null)`/`setWorkSchedule(null)` (diffed via
  `array_udiff()` comparing `getId()`) — otherwise an unchecked employee
  would silently stay assigned in the DB despite the unchecked box.
  `edit()` must pre-populate the field's data with the entity's current
  collection *before* `handleRequest()` (`$form->get('employees')->setData(...)`),
  or the checkboxes won't show as pre-checked on load. Both templates'
  `_form.html.twig` render each checkbox manually (loop over
  `form.employees` + `employeesById[child.vars.value]` — the controller
  passes `employeesById`, an `id => Employee` map, because
  `child.vars.data` on an expanded choice child is the checked-state bool,
  not the entity; the entity has to come from elsewhere) instead of
  `form_widget(form.employees)`, so a "actuellement : X" badge can be
  shown next to any employee already assigned somewhere else — informational
  only, not a validation block, reassignment happens silently on submit.
  Reused the `.checkbox-list`/`.checkbox-row` classes from
  `device/sync_preview.html.twig` (added `.checkbox-list--scroll` for a
  bounded, scrollable height — ~83 employees is too many to render
  unbounded). `assets/checkbox-search.js` adds a client-side, no-dependency
  search box above any `[data-checkbox-search]` list (same zero-dep
  pattern as `data-table.js`'s table search, just filtering `.checkbox-row`
  elements by `textContent` instead of `<tr>`).
- **Assign-by-department shortcut on `/horaires` only**: above the
  employee checkbox list, `/horaires/new`+`/edit` also render one checkbox
  per `Department` (`data-department-toggle="{id}"`) inside a
  `[data-department-picker]` container; each employee checkbox carries
  `data-department-id="{their department id, or empty}"`.
  `assets/department-picker.js` is purely a client-side convenience —
  toggling a department checkbox just checks/unchecks the matching
  employee checkboxes in the DOM; there is **no server-side department
  concept in this flow** and no dedicated form field for it. Whatever's
  actually checked in the employee list at submit time is what gets
  saved — an admin can check a department then uncheck one individual
  employee from it before saving, and that employee is excluded (manual
  per-checkbox state always wins, confirmed deliberate: department
  checkboxes are a "check all" shortcut, not a live-tracked rule).
- **`Employee::$workSchedule`'s FK originally lacked `onDelete: 'SET
  NULL'`** — a preexisting gap (predates `Department`, which got it right
  from the start) only surfaced when building the department-picker
  feature above and testing deletion of a `WorkSchedule` still linked to
  employees: deleting it raised a raw 500
  (`ForeignKeyConstraintViolationException`, DB-level FK violation, no
  graceful `\Throwable` catch anywhere in that path) instead of detaching
  them. Fixed the same way as `department_id`: `onDelete: 'SET NULL'`
  added to the `JoinColumn`, migrated. If you add another nullable
  `ManyToOne` from `Employee` in the future, default to `onDelete: 'SET
  NULL'` unless there's a specific reason not to — an admin deleting a
  parent record they still reference shouldn't 500 the request.
- **Dashboard auto-refresh**: plain `<meta http-equiv="refresh" content="60">`,
  only on `templates/dashboard/index.html.twig` (via a new `head_extra`
  block added to `base.html.twig` for pages to inject `<head>` content) —
  deliberately not JS/fetch-based polling, a full reload every 60s is fine
  for a passively-viewed screen and preserves the current `?department=`
  filter automatically (meta-refresh reloads the current URL, query string
  included).

**Telegram alerts** (`src/Service/AttendanceAlertService.php`) — two triggers:
- **Late, real-time**: `HikvisionWebhookController::handle()` calls
  `checkLate($event)` right after mapping a webhook payload, but **only
  when both** `$wasNew` (see below) **and** the event's `occurredAt` is
  today — a historical backlog flush or a re-delivered event must never
  fire an alert.
- **Absent, daily at 10:00**: `app:hikvision:check-absences` (no arguments,
  absences are global not per-device) runs via a cron job baked into the
  `php` container itself (`docker/php/crontab` →
  `0 10 * * * php bin/console app:hikvision:check-absences`, started by
  `crond -b -l 8` in `docker/php/docker-entrypoint.sh` before the `exec`).
  Alpine's `php:8.2-fpm-alpine` ships `busybox crond` — no extra package,
  no separate worker container. If you ever add a second scheduled task,
  add another line to that same crontab file rather than spinning up a
  second cron mechanism.
  - **`checkAbsences()` sends one grouped Telegram message per run, not
    one per employee** — a deliberate change from the original
    one-message-per-employee behavior (the user explicitly asked for a
    single message listing everyone absent, after seeing 60+ separate
    Telegram notifications flood in). `AlertLog` still gets one row per
    employee (schema unchanged — `employee` stays `nullable: false`,
    keeps the per-employee audit trail useful elsewhere), but the
    dedup/exclusion check now happens **before** building the message:
    `missingToday()` results are filtered through
    `alreadySent($e, $date, 'absent')` first, and if that filtered list
    is empty, nothing is sent (no `dispatchToRecipients()` call at all —
    returns `0` immediately, same as `send()`'s early-exit pattern).
    Only if at least one employee makes it through does a single message
    go out (`"❌ Absences du {day} ({count}) :\n• Name (Department)\n..."`),
    and only then are all `AlertLog` rows for that batch persisted in one
    `flush()`. Practical effect: running the command twice the same day
    with no new absences sends zero messages the second time (verified
    live); if a new absence appears between two runs, only that new
    employee shows up in the next message, not the whole list again.

**`AttendanceEventMapper::map()` signature changed**: now returns
`array{AttendanceEvent, bool}` (`[$event, $wasNew]`) instead of just the
entity — `$wasNew` is `true` only on a genuine insert (no prior row for that
`device`+`serialNo`), `false` on an idempotent update. Both call sites
(`HikvisionWebhookController`, `AttendanceEventSyncService::sync()`) were
updated; the sync service just discards the bool (historical backfills
never alert). If you add a third caller, remember the return shape changed.

**Anti-duplicate, enforced at the DB level, not just in application code**:
`AlertLog` (`employee`, `date`, `type` — `late`|`absent`, `sentAt`) has a
`#[ORM\UniqueConstraint]` on `(employee_id, date, type)`. At most one alert
per employee/day/type, ever — checked via
`AlertLogRepository::alreadySent()` before sending, and the DB constraint
is the real backstop against a race or a bug reintroducing a duplicate.
Verified live: replaying the identical webhook event (same `serialNo`)
sends zero additional alerts (idempotent update, `$wasNew` false); sending
a **different** new event for an already-alerted employee the same day
also sends zero additional alerts (`alreadySent()` catches it independent
of event idempotency); running `check-absences` twice in a row sends one
grouped message the first time (67 employees in this deployment), zero
messages the second (see grouped-message note above — behavior changed
from one-message-per-employee to one-message-per-run since this was
first verified).

**Telegram, not email — a deliberate mid-implementation switch.** Alerts
were originally built on `symfony/mailer` (email); the user changed their
mind and asked for Telegram instead, so `symfony/mailer` was fully
**removed** (`composer remove symfony/mailer`) rather than left installed
unused, and `templates/emails/` was deleted — don't resurrect either
without the user asking again. Alerts now go through Symfony Notifier's
`Chatter` (`symfony/notifier` + `symfony/telegram-notifier`), injected as
`ChatterInterface` directly into `AttendanceAlertService` — **not** the
higher-level `Notifier`/`channel_policy`/`Notification` routing mechanism
Flex's recipe scaffolded by default (that machinery assumes email-first
routing by urgency, irrelevant here since there's exactly one channel and
one destination) — `config/packages/notifier.yaml` was trimmed down to
just the `chatter_transports.telegram` line, the `channel_policy` /
`admin_recipients` block Flex generated (pointing at a fake
`admin@example.com`) was deleted.

**Connected to a real bot, config split between `.env` and the database.**
`.env` holds only the bot token, no chat id:
`TELEGRAM_DSN=telegram://TOKEN@default` (no `?channel=` — see below for
why). Everything else — the list of recipients and the global on/off
switch — is managed at runtime from the `/telegram` web UI, not `.env`,
so it can be changed without a container restart. This was a deliberate
mid-implementation switch from the original design (single chat id baked
into `TELEGRAM_DSN`, `ALERTS_ENABLED` in `.env`): the user asked for
UI-driven config with multiple recipients.

- `TelegramRecipient` (`label`, `chatId`, `isActive`) — one row per
  destination, added manually through the UI (label + chat id typed in;
  no `getUpdates` auto-discovery). `TelegramRecipientRepository::findActive()`
  is what `AttendanceAlertService` reads at send time.
- `AlertSettings` — singleton row (`enabled` bool, default `false`),
  accessed via `AlertSettingsRepository::getOrCreate()` (lazy-creates the
  row on first access, no fixture/migration data needed). This replaces
  `ALERTS_ENABLED` — reading it from the DB on every send means the
  toggle takes effect immediately, unlike an `%env(bool:...)%` parameter
  which is baked in at container boot and needs `cache:clear`.
- **Why one DSN/token works for multiple recipients**: `TelegramTransport::send()`
  does `$options['chat_id'] ??= $message->getRecipientId() ?: $this->chatChannel`
  — so setting `TelegramOptions::chatId($id)` per-message overrides
  whatever channel the DSN has (or doesn't have). `AttendanceAlertService::dispatchToRecipients()`
  loops `findActive()` and sends one `ChatMessage` per recipient, each
  with its own `chatId()`. A `try/catch` wraps *each* send individually
  (not the whole loop) — one recipient having a stale/invalid chat id
  (`400 Bad Request: chat not found`) must never block delivery to the
  others; failures are logged as `app.WARNING` with the recipient id and
  the exact Telegram error, and the return shape
  `array{success: int, failed: int}` is what both `send()` and the UI's
  "Envoyer un test" button (`sendTest()`) report back. `AlertLog`
  (anti-duplicate) is only written if at least one recipient succeeded —
  a message that reached zero recipients isn't "sent" for dedup purposes.
- **UI** (`/telegram`, `TelegramController`): status card (enabled/disabled
  badge + toggle + "Envoyer un test" button), add-recipient form
  (`TelegramRecipientType` — plain `TextType` for `chatId`, no numeric
  constraint since group chat ids have a different format), recipients
  table (toggle active/inactive, delete). All mutating actions are
  CSRF-protected POSTs, same pattern as `HolidayController`/`LeaveController`.
  Nav link lives under the existing "Configuration" group in
  `base.html.twig`.
- Message text is still built inline in `AttendanceAlertService` (no Twig
  template — same one-liner-doesn't-need-an-abstraction reasoning as
  before), includes an emoji prefix (⏰ late / ❌ absent), the employee's
  name, the date, and department if set.

**Parse mode is HTML, not MarkdownV2 — and this isn't a style choice, it's
working around a real bug in `symfony/telegram-notifier`.** `TelegramTransport::doSend()`
applies its own escaping regex whenever `parse_mode` is absent or
`MarkdownV2`: `preg_replace('/([.!#>+-=|{}~])/', '\\\\$1', $text)`. Inside
that PCRE character class, `+-=` is **not** three literal characters —
it's a range from `+` (ASCII 43) to `=` (ASCII 61), which silently
includes every digit `0`-`9` and `/`. Confirmed directly:
`for ($i = ord("+"); $i <= ord("="); $i++) echo chr($i);` prints
`+,-./0123456789:;<=`. In practice, any message containing a date like
`26/08/2026` came out mangled (`\2\6\/\0\8\/\2\0\2\6`, a `\` injected
before every digit) — this happened *in addition to* whatever escaping
`AttendanceAlertService` did itself, since the vendor code re-escapes
regardless of what the caller already did. There's no clean way to work
around this while staying on MarkdownV2 — the fix was to stop using it
entirely: `AttendanceAlertService::escapeHtml()` (`htmlspecialchars($text,
ENT_NOQUOTES | ENT_HTML5, 'UTF-8')`) + `TelegramOptions::parseMode(TelegramOptions::PARSE_MODE_HTML)`.
Telegram's HTML mode only reserves `& < >`, so a plain `htmlspecialchars()`
is sufficient and there's no vendor-side re-escaping to collide with.
Verified live: a real late-alert message containing a name, parentheses,
and a `d/m/Y` date was received correctly formatted. If you ever add
bold/italic formatting to alert text, use HTML tags (`<b>`/`<i>`), not
Markdown syntax — and don't switch back to `MarkdownV2` without fixing
the upstream regex first (or filing it upstream).

## Web UI

Server-rendered Twig UI, all under `src/Controller/` (kept
separate from the JSON API controllers — don't mix HTML and JSON response
concerns in one controller):

- **Dashboard** (`/`, `DashboardController`) — today's presence overview,
  reuses `AttendanceService::dailySummaryForAll()`/`missingToday()`.
- **Historique** (`/historique`, `AttendanceHistoryController`) — same
  summary for an arbitrary `?date=` query param (plain GET form, no CSRF
  needed for a read-only query).
- **Devices** (`/devices`, `DeviceController`) — lists devices, triggers
  `WebhookRegistrar`/`EmployeeSyncService`/`AttendanceEventSyncService`
  actions via POST forms with CSRF tokens (`isCsrfTokenValid()`), catching
  `\Throwable` from the Hikvision HTTP calls and flashing an error instead
  of 500ing (terminals are often unreachable in dev). `sync-employees` is a
  two-step web flow: `GET .../sync-employees` previews unlinked
  `employeeNo`s as checkboxes, `POST` confirms the selection — this
  replaces the CLI's interactive `$io->confirm()` loop, which has no web
  equivalent. `sync-events` (single POST with `from`/`to` date inputs)
  backfills historical punches — see `app:hikvision:sync-events` above.
  `new`/`edit` (`src/Form/DeviceType.php`) — standard CRUD, but
  `adminPassword` needed special handling since it's the terminal's admin
  password: `mapped: false` on the form field (never round-trips through
  `data_class` binding, so it never gets pre-filled with the existing
  stored value on edit — nothing to leak into the rendered HTML), and the
  field's `required`/`constraints` are toggled via a form option
  (`is_edit`, passed from the controller) — required + `NotBlank` on
  create, optional on edit. Controller logic: on `new()`, always
  `$device->setAdminPassword($form->get('adminPassword')->getData())`
  (guaranteed non-empty by the constraint); on `edit()`, only call
  `setAdminPassword()` if the submitted value is non-empty — leaving it
  blank keeps whatever's already in the DB. `webhookToken` isn't on the
  form at all (auto-generated once in the constructor, immutable by
  design — regenerating it on edit would silently break an already-registered
  device until `register-webhook` is re-run). Access control needs no new
  `security.yaml` entry: `^/devices` is already `ROLE_ADMIN` on the whole
  route, so `/devices/new` and `/devices/{id}/edit` are covered by the
  existing rule.
- **Employés** (`/employees`, `EmployeeController`) — CRUD via
  `src/Form/EmployeeType.php` (`Assert\*` constraints live on the form's
  field options, not on the `Employee` entity — entities stay plain
  Doctrine mapping, consistent with the no-validation-on-entities style).
  No hard delete: `deactivate` just sets `Employee::$isActive = false`
  (matches the existing domain model, avoids FK/orphan concerns for
  `AttendanceEvent`/`DeviceEmployee`). `GET /employees/{id}` (`show`) also
  renders a per-employee day-by-day attendance history for an arbitrary
  `?from=&to=` date range (defaults to the last 30 days, swapped
  automatically if `from` > `to`), via
  `AttendanceService::employeeHistory()`. That method mirrors
  `monthlySummary()`'s bulk-preload pattern (events/leaves/holidays loaded
  once for the whole range, not per day) but returns per-day rows instead
  of aggregates — no range cap is enforced (confirmed acceptable with the
  user), since the cost is still just 3 bulk queries regardless of range
  length. Newest day first (`array_reverse` in the controller).

**Employee enrollment photos**: `Employee::$photoPath` (nullable, e.g.
`employee-photos/14.jpg`) stores a path relative to `public/uploads/`, not
a Hikvision URL — those expire/depend on LAN access, so the file is
downloaded once and kept locally. Source: `HikvisionDigestClient::listUsers()`
already returns a `faceURL` field per user (the *enrollment* photo, not a
punch-time capture — different from the `pictureURL` seen in
`AttendanceEvent.rawPayload`). `faceURL` is an **absolute URL** outside
`/ISAPI` (e.g. `http://192.168.1.11/LOCALS/pic/enrlFace/0/...jpg@...`), so
fetching it needed `HikvisionDigestClient::fetchBinary(string $absoluteUrl)`
— added alongside a `requestUrl()` refactor that the existing `/ISAPI`-relative
`request()` now delegates to; the Digest `uri` component must be computed
from the target URL's own path+query, not assumed to be under `/ISAPI`.
`EmployeePhotoService::store()` does the download + `file_put_contents` into
`public/uploads/employee-photos/{id}.jpg` (nginx serves it directly, no PHP
involved) and sets `photoPath`. Rendered with a plain `<img src="/uploads/...">`
— **not** Twig's `asset()` function, which requires `symfony/asset` (not
installed in this project; only `symfony/asset-mapper` is, and that's a
different pipeline for `assets/` source files, not arbitrary files already
sitting in `public/`). Reusable CSS: `.avatar`/`.avatar--lg`/`.avatar--sm` in
`assets/styles/app.css`.

Photo download is wired into the existing employee-linking flow, not a
separate action: `EmployeeSyncService::previewUnlinked()` now also returns
each candidate's `faceURL`, carried through `device/sync_preview.html.twig`
as a hidden `selected_face_url[{employeeNo}]` field per row (avoids a second
`listUsers()` round-trip at confirm time — robust if the terminal becomes
briefly unreachable between preview and confirm) and read back in
`DeviceController::syncEmployeesConfirm()`. `EmployeeSyncService::linkSelected()`
downloads the photo right after persisting each new `Employee` (needs a
per-employee `flush()` first to get an id for the filename) — wrapped in its
own `try/catch`; a photo download failure must never abort employee
creation, just skip that one photo silently (logged as a warning).

For employees linked *before* this feature existed, there's a one-off,
CLI-only backfill: `app:hikvision:sync-employee-photos {deviceId}`
(`src/Command/SyncEmployeePhotosCommand.php`) — walks every `DeviceEmployee`
already linked to the device, skips anyone with a `photoPath` already set or
no `faceURL` available, downloads the rest. No web UI button for this one
deliberately (a one-time catch-up action, not a recurring workflow) — run
it manually after the fact if new gaps appear. Already run once against the
real device in this deployment: 23 photos backfilled, 7 skipped (no
`faceURL` — likely enrolled by card/fingerprint only, no face capture).

Shared macro: `templates/attendance/_status_badge.html.twig` (`badges.badge(status, lateMinutes)`)
renders the present/late/absent/on_leave/holiday badge — used by
`_summary_table.html.twig` and the employee history table. Add new
attendance-status tables through this macro rather than re-copying the
`{% if row.status == ... %}` chain.

Shared partial: `templates/attendance/_summary_table.html.twig` (used by
both Dashboard and Historique). Design system: one hand-written
`assets/styles/app.css` (no framework/CDN — self-hosted via AssetMapper,
since the Docker image has no Node toolchain and the deployment may be
LAN-only/offline).

**Table search/sort** (`assets/data-table.js`, imported by `assets/app.js`):
generic, dependency-free client-side search+sort — add `data-table` to any
`<table class="table">` and `data-sort="text"` or `data-sort="number"` to
sortable `<th>`s (omit `data-sort` on action columns to keep them
unclickable). A search box is auto-injected above the table; clicking a
sortable header re-sorts the already-rendered `<tr>`s in place, no
request/reload. When a cell's display text isn't directly sortable (e.g.
`device.lastSeenAt` formatted as `d/m/Y H:i`, where naive string sort would
misorder across months), put the real sortable value in
`data-sort-value="..."` on that `<td>` — the script prefers it over
`textContent`. Used on: dashboard/historique summary table, `/employees`,
`/devices`, `/rapports/mensuel`. When adding a new table that should be
searchable/sortable, follow the same two attributes rather than writing
bespoke JS.

**Services extracted for CLI/web reuse** (`src/Service/`):
- `WebhookRegistrar::register(Device, ?string $baseUrlOverride)` — builds
  the webhook URL and calls `HikvisionClientFactory`.
- `EmployeeSyncService::previewUnlinked(Device)` /
  `::linkSelected(Device, array $selected)` — the "already linked?" filter
  and Employee-from-name-split creation logic, shared by
  `SyncDeviceEmployeesCommand` and `DeviceController`.

## Authentication & roles

`symfony/security-bundle` is installed; `config/packages/security.yaml` is
the single source of truth for access control (no attribute-based
`#[IsGranted]` in controllers — deliberate, keeps every route's required
role auditable in one file instead of scattered across ~9 controllers).

- **`User`** (`src/Entity/User.php`) — `email` (unique, login identifier),
  `password` (hashed), `roles` (json array), `isActive` (bool, default
  true — soft-disable pattern, same as `Employee::$isActive`).
  `getRoles()` always appends `ROLE_VIEWER` to whatever's stored
  (`array_unique([...$this->roles, 'ROLE_VIEWER'])`), so every
  authenticated account has at least read access even if `roles` only
  contains `ROLE_ADMIN`. Only two roles exist: `ROLE_ADMIN` (full access)
  and `ROLE_VIEWER` (read-only) — the `UserType` form only lets you pick
  one "highest" role, `ROLE_VIEWER` being implicit.
- **`access_control`** in `security.yaml` (first match wins, order
  matters): `/api/hikvision/webhook` is `PUBLIC_ACCESS` **and must stay
  first** — terminals have no session/cookie, this is the same
  token-in-URL auth described above, breaking this locks out every
  physical device. `/login` is also public. `/api` (i.e.
  `/api/attendance/*`) requires `ROLE_VIEWER` — same internal dashboard
  data as the UI, just consumed as JSON, no reason to leave it open when
  everything else is gated. `/users`, `/devices`, `/telegram` are
  `ROLE_ADMIN` on the whole route (no read-only carve-out — these are
  inherently admin surfaces). `/employees/new`, `/employees/{id}/edit`,
  `/employees/{id}/deactivate`, `/horaires/new`, `/horaires/{id}/edit`,
  `/horaires/{id}/delete`, `/jours-feries/new`, `/jours-feries/{id}/delete`,
  `/conges/new`, `/conges/{id}/delete` are `ROLE_ADMIN` individually —
  **their `index` pages are deliberately left under the catch-all
  `ROLE_VIEWER` rule**, so a viewer can browse `/employees`, `/horaires`,
  `/jours-feries`, `/conges` read-only but can't mutate anything. If you
  add a new mutating route under one of these prefixes, add its own
  `access_control` entry *before* the catch-all `^/ → ROLE_VIEWER` line,
  or it'll silently be readable/writable by viewers.
- **UI mirrors the server-side rule, doesn't replace it**: `access_control`
  is the actual enforcement; templates additionally hide mutation buttons
  (`{% if is_granted('ROLE_ADMIN') %}` around "Nouveau"/"Éditer"/
  "Supprimer"/"Sync" buttons) purely so a viewer doesn't click into a 403 —
  cosmetic only, never the source of truth. Same pattern for nav links in
  `base.html.twig` (Devices/Telegram/Utilisateurs hidden for non-admins;
  Horaires/Jours fériés/Congés/Employés stay visible since those have a
  viewable `index`).
- **Bootstrap**: no signup UI. The very first admin account is created via
  `app:user:create-admin {email} {password}` (`src/Command/CreateAdminUserCommand.php`),
  run once via `docker compose exec php php bin/console app:user:create-admin ...`.
  Every subsequent account (admin or viewer) is created from `/users`
  (`ROLE_ADMIN`-only), via `UserController`/`UserType` — plain-text
  password hashed through `UserPasswordHasherInterface`, never stored raw.
- **Self-lockout guard**: `UserController::toggle()`/`::delete()` both
  refuse to act on `$this->getUser()` (flash error instead) — can't
  deactivate or delete your own currently-logged-in account. The `/users`
  template also hides the toggle/delete buttons entirely for your own row
  (shows a "Vous" badge instead) — same reasoning as the access_control/UI
  split above, the controller check is the real guard.
- No password-reset/edit flow for existing accounts in this iteration — a
  known gap, not an oversight; only create/toggle-active/delete exist
  today.

## Conventions specific to this codebase

- Entities use terse single-line getters/setters (`public function getX():
  T { return $this->x; }`) — match this style rather than expanding them.
- Comments in this codebase are in French, matching the original author's
  convention; keep new comments in French unless told otherwise.
- No DTOs/value objects — controllers build response arrays inline and pass
  them to `$this->json(...)`.
- `Device::$adminPassword` is stored as plain `text` in the DB with a
  comment noting it should ideally be encrypted at the application level
  (custom Doctrine type) — this is a known gap, not an oversight to silently
  "fix" without flagging it.
