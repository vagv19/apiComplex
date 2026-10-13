# Football Tournament Manager API

Backend REST API for tournament administration built in native PHP and PDO.

## Features

- Catalog CRUD for categories, teams, and players
- Tournament lifecycle and registration management
- Round and match scheduling
- Results and standings calculations
- Basic auth with signed bearer tokens
- SQLite-compatible test environment for local validation

## Setup

1. Copy `.env.example` to `.env` and set the existing MySQL database name and credentials.
2. For a new database, run `database/schema.sql` once; it creates the `football_tournaments` database and tables.
3. The SQLite schema at `database/schema.sqlite.sql` is used only for local tests.
4. Apply `database/migrations/001_create_users.sql` and create an administrator account before using the admin application. For an existing tournament database, apply `database/migrations/002_match_details_and_discipline.sql`; if its match-report tables predate the current API schema, also apply `database/migrations/003_repair_existing_match_report_schema.sql` followed by `database/migrations/004_align_match_report_to_attached_schema.sql`.
5. Optionally populate seed data from `database/seeders/initial_data.sql` in the SQLite test database.
6. With XAMPP running, access the API at:

   `http://127.0.0.1/apiComplex/backend/public/api/v1`

   For local development with PHP's built-in server, run these commands
   from the `backend` directory:

```bash
php -S 127.0.0.1:8000 public/index.php
```

## API base path

`/api/v1`

## Example routes

- `GET /api/v1/categories`
- `POST /api/v1/teams`
- `GET /api/v1/tournaments`
- `POST /api/v1/tournaments`
- `POST /api/v1/tournaments/{id}/teams`
- `POST /api/v1/matches/{id}/result`
- `POST /api/v1/matches/{id}/schedule`
- `GET /api/v1/matches/by-date?date=YYYY-MM-DD`
- `GET /api/v1/public/matches/first?date=YYYY-MM-DD`
- `GET /api/v1/matches/{id}/report`
- `POST /api/v1/matches/{id}/report`
- `POST /api/v1/tournaments/{id}/status`
- `POST /api/v1/tournaments/{id}/player-registration`
- `POST /api/v1/expulsion-reasons/{id}`
- `GET /api/v1/public/tournaments`
- `GET /api/v1/public/tournaments/{id}/top-scorers`
- `GET /api/v1/public/tournaments/{id}/images`
- `GET /api/v1/public/tournaments/{id}/images/{filename}`

### Torneos públicos activos

`GET /api/v1/public/tournaments` devuelve la lista de torneos públicos activos,
ordenados por estado y fecha de inicio. Por defecto incluye únicamente los
registros con `status` en `REGISTRATION` o `IN_PROGRESS`; la respuesta usa la
misma forma de paginación que el listado administrativo y es pública, sin
requiere token.

```http
GET /api/v1/public/tournaments?page=1&limit=12
```

```json
{
  "success": true,
  "message": "Torneos públicos consultados.",
  "data": {
    "items": [
      {
        "id": 12,
        "category_id": 1,
        "name": "Liga de otoño",
        "format": "LEAGUE",
        "status": "IN_PROGRESS",
        "start_date": "2026-10-20",
        "end_date": "2026-12-15"
      }
    ],
    "page": 1,
    "limit": 12,
    "total": 1
  }
}
```

### Primer partido público del día

`GET /api/v1/public/matches/first?date=YYYY-MM-DD` es una consulta pública sin
autenticación. Devuelve el primer partido real de la fecha en torneos con
inscripciones abiertas o en curso, ordenado por hora de inicio. Excluye
descansos, partidos pospuestos y cancelados. Si no hay encuentros aplicables,
`data` es `null`. La fecha es obligatoria y debe ser válida; de lo contrario,
responde `422`.

```http
GET /api/v1/public/matches/first?date=2026-10-13
```

```json
{
  "success": true,
  "message": "Primer partido de la fecha consultado.",
  "data": {
    "id": 24,
    "match_date": "2026-10-13",
    "kickoff_time": "16:30:00",
    "venue": "Cancha 1",
    "status": "SCHEDULED",
    "home_score": null,
    "away_score": null,
    "home_team_name": "Equipo local",
    "away_team_name": "Equipo visitante",
    "tournament_name": "Liga de otoño"
  }
}
```

### Consultas públicas de goleadores e imágenes

Estas consultas son públicas y no requieren bearer token. Los identificadores
deben ser enteros positivos; un torneo inexistente responde `404` y un
identificador inválido responde `422`.

`GET /api/v1/public/tournaments/{id}/top-scorers` devuelve jugadores con goles
individuales registrados en `match_goals` de partidos `COMPLETED`. Cuenta los
tipos `REGULAR` y `PENALTY`, excluye `OWN_GOAL`, verifica que la inscripción
pertenezca al torneo y a uno de los equipos participantes en el partido. Los
empates comparten posición competitiva; el nombre solo determina su orden de
presentación. `limit` es opcional, predeterminado a 20 y acepta valores de 1
a 100.

```http
GET /api/v1/public/tournaments/15/top-scorers?limit=10
```

```json
{
  "success": true,
  "message": "Tabla de goleadores consultada.",
  "data": [
    {
      "position": 1,
      "player_id": 23,
      "player_name": "Juan Pérez",
      "tournament_team_id": 7,
      "team_name": "Deportivo",
      "total_goals": 5
    }
  ]
}
```

Un torneo existente sin goles elegibles responde `200` con `"data": []`.
Un parámetro `limit` inválido responde `422`.

`GET /api/v1/public/tournaments/{id}/images` enumera imágenes de
`backend/img/tournament/{id}/`. Extensiones aceptadas (sin distinguir
mayúsculas/minúsculas): JPG, JPEG, PNG, WEBP y GIF. Los archivos ocultos,
temporales, enlaces simbólicos y archivos que no sean imágenes se excluyen.
Un directorio inexistente o vacío responde `200` con una colección vacía.
Cada `url` es una ruta pública relativa al mismo host y apunta al recurso de
lectura controlada por la API:

```json
{
  "success": true,
  "message": "Galería consultada.",
  "data": [
    {
      "tournament_id": 15,
      "filename": "final.JPG",
      "url": "/v2/backend/public/api/v1/public/tournaments/15/images/final.JPG"
    }
  ]
}
```

`GET /api/v1/public/tournaments/{id}/images/{filename}` sirve exclusivamente
un archivo de imagen permitido de la galería de ese torneo. No acepta rutas
físicas ni nombres de archivo con separadores. Los fallos de lectura responden
`500` sin exponer detalles internos.
Además de los archivos ubicados en `backend/img/tournament/{id}/`, acepta
`backend/img/tournament/{id}.jpg` cuando el nombre del archivo coincide
exactamente con el identificador del torneo.

### Match scheduling and matches by day

Both match scheduling and match-day query endpoints require an administrator
bearer token.
`POST /api/v1/matches/{id}/schedule` schedules or reschedules one existing
fixture; it updates only `matches.match_date` and `matches.kickoff_time`.
The round, stage, tournament, category, teams, score, venue, and match status
are not changed.

```json
{
  "match_date": "2026-11-05",
  "kickoff_time": "18:30"
}
```

The date must be a real `YYYY-MM-DD` date and the time must use 24-hour
`HH:MM` format. A missing fixture returns `404`; a bye, cancelled, in-progress,
or completed fixture (including one with an existing score), or a time conflict
with any other non-bye, non-cancelled match in the system on the same date and
within 50 minutes of its start returns `409` with an unavailable-slot message.
For example, a match starting at 07:00 occupies the 07:00–07:50 slot, so the
next match may start at 07:50 but not earlier. Invalid fields return `422`.
Scheduling is saved in a transaction.

`GET /api/v1/matches/by-date?date=2026-11-05` returns all non-bye matches
programmed for that date, across every tournament and category. Each row
includes tournament/category, stage, group/round, team, kickoff time, score,
venue, and status fields. Results sort by kickoff time ascending, with matches
without a time last and stable category, tournament, stage, round, match, and
ID tie-breakers. A valid date with no matches returns an empty `data` array.

### Create a tournament

`POST /api/v1/tournaments` accepts JSON. `name` and an active `category_id`
are required; format and competition settings are optional and default to a
league in draft status.

```json
{
  "name": "Liga de otoño",
  "category_id": 1,
  "format": "LEAGUE",
  "status": "REGISTRATION",
  "start_date": "2026-10-20",
  "end_date": "2026-12-15",
  "rounds_count": 10,
  "qualified_teams": 8,
  "player_registration_deadline_round": 4,
  "points_for_win": 3,
  "points_for_draw": 1,
  "points_for_loss": 0
}
```

The endpoint responds with HTTP `201` and the tournament under `data`, using
the API field names (`format`, `points_for_win`, and similar). Invalid fields
return `422`; an unknown or inactive category returns `404`.

The PDO repository maps these API names to the installed MySQL tournament
columns (`tournament_type`, `points_win`, `regular_rounds`, and related
columns), so API names remain consistent for the frontend.

The application currently preserves the database selected by an existing
`.env`; it does not create, switch, or migrate a live database automatically.
Do not change an existing `DB_NAME` to `football_tournaments` unless that
database has been created and its data has been verified.

### Update tournament settings

Tournament status and player-registration settings are updated with `POST`:

- `POST /api/v1/tournaments/{id}/status` accepts `{"status":"IN_PROGRESS"}`.
- `POST /api/v1/tournaments/{id}/player-registration` accepts
  `{"allow_player_registration":false}`; omitting the field toggles the setting.

These routes keep their existing validation and domain rules; only the HTTP
method is `POST`.

## Current format support

Catalogs, tournament creation, and team/player registration use the supplied
schema. Automatic schedule generation and standings are implemented for
`LEAGUE` tournaments; the format-specific endpoints below add single
elimination for `CUP` and group play plus qualification for `FLASH`. These
operations use the existing `tournament_stages`, `tournament_groups`,
`group_teams`, `rounds`, and `matches` tables; no schema migration is required.

### Liga

- The regular calendar uses `POST /api/v1/tournaments/{id}/schedule/generate`.
- After every generated regular-season round has matches and all real matches
  have a completed result (byes are excluded), call
  `POST /api/v1/tournaments/{id}/league/qualification/generate` with an empty
  JSON body to start the knockout phase. The configured top `qualified_teams`
  advance, capped at the number of registered teams; the bracket uses
  standings order for seeding. The operation returns `201` and the generated
  stage and first round. A `409` response means the regular phase is unfinished
  or the liguilla has already started.
- The first phase is named `Liguilla`. Continue the tournament with
  `POST /api/v1/tournaments/{id}/knockout/advance`, the same advancement route
  used by Copa and Relámpago. A completed regular-season result cannot be
  corrected after the liguilla has been created.
- The league standings endpoint and frontend table continue to show only
  regular-season results after the knockout matches begin.

### Copa

- `POST /api/v1/tournaments/{id}/cup/generate` creates the first seeded
  elimination round. The JSON body is empty. Team registrations are ordered by
  `seed_number`, then registration ID. If the count is not a power of two,
  the highest seeds receive automatic advances represented as `BYE` matches.
  League and Flash brackets use their qualification standings to seed the
  teams, so teams in the highest positions receive the byes.
- `POST /api/v1/tournaments/{id}/knockout/advance` advances winners after every
  real match in the current round is resolved. Repeated calls cannot duplicate
  a round; unresolved or tied matches return `409`.
- A tied knockout result must include distinct `home_penalties` and
  `away_penalties` in `POST /api/v1/matches/{id}/result`. Penalties are
  rejected on non-knockout matches and on non-tied scores.

### Relámpago

- `POST /api/v1/tournaments/{id}/groups/generate` with
  `{"group_count": 4}` creates balanced, serpentine team assignments. At least
  two groups with at least two active teams each are required.
- `GET /api/v1/tournaments/{id}/groups` returns groups and assigned teams.
- `DELETE /api/v1/tournaments/{id}/groups` has no request body and cancels
  group setup before the group-stage schedule is generated. A `200` response
  returns `tournament_id`, `groups_deleted`, and `teams_unassigned`. The
  operation removes group assignments, groups, and the group-stage record
  transactionally, while preserving tournament team registrations. It returns
  `404` if the tournament does not exist and `409` if rounds or another
  tournament stage already exist; generated matches and results are never
  deleted by this action.
- `POST /api/v1/tournaments/{id}/group-stage/generate` creates a separate
  round-robin schedule per group. Odd-sized groups receive balanced `BYE`
  records and no cross-group matchups are generated.
- `GET /api/v1/groups/{group_id}/standings` returns that group's table.
- `POST /api/v1/tournaments/{id}/qualification/generate` accepts
  `{"qualifiers_per_group": 2, "total_qualifiers": 8}`. Every group must have
  enough completed results and eligible teams. Qualification prioritizes
  each group's first-place teams, then subsequent positions; runners-up are
  compared by points, goal difference, goals scored, group order, team name,
  then team ID. The requested count must include at least one team from each
  group and produce unique participants.
- The generated knockout stage uses the same `knockout/advance` endpoint as
  Copa. Completed group results cannot be changed after the final bracket is
  generated; completed knockout results cannot be changed after a later round
  exists. This prevents silent changes to qualified teams or downstream
  pairings.

All schedule and bracket operations are transactional and reject duplicate
generation. The existing `LEAGUE` route and generation algorithm are
unchanged.

## Match reports, discipline, and suspensions

The following endpoints require a valid bearer token for an account whose
role is `admin`. Sign in using `POST /api/v1/auth/login`; the React
administration application provides the corresponding login screen.

There is intentionally no public user-registration endpoint. After applying
`001_create_users.sql`, generate a password hash locally with PHP's
`password_hash($password, PASSWORD_DEFAULT)` and insert the account through
the trusted database administrator:

```sql
INSERT INTO users (name, email, password_hash, role, is_active)
VALUES ('Tournament Admin', 'admin@example.local', '<locally-generated-hash>', 'admin', 1);
```

Replace the example name, email, and hash; never store a plaintext password
in the database or commit a real administrator credential.

### Read a match report

`GET /api/v1/matches/{id}/report` returns the match score, active tournament
players from both participating teams (including team and shirt number),
saved attendance, goal details, disciplinary events with their sanctions,
and active expulsion reasons. Bye records are rejected.

### Save a match report atomically

`POST /api/v1/matches/{id}/report` saves the score and optional penalty shootout
in one transaction. When active players are registered for the match, also send
attendance, goals, and disciplinary events to save the complete report
atomically. When there are no active registered players, send only the result
fields; existing player details, if any, are left unchanged. The three detail
arrays must either all be provided or all be omitted. A failed validation rolls
back all changes, including the result history record. The request carries no
user ID; the authenticated token identifies the administrator. This endpoint
is protected by the administrator bearer token.

Score-only request:

```json
{
  "home_score": 2,
  "away_score": 1,
  "home_penalties": null,
  "away_penalties": null,
  "reason": "Resultado capturado sin jugadores registrados."
}
```

When active players are present, submit the complete report as shown below.

```json
{
  "home_score": 2,
  "away_score": 1,
  "home_penalties": null,
  "away_penalties": null,
  "attendance": [
    {"tournament_player_id": 31, "attendance_status": "PRESENT"},
    {"tournament_player_id": 44, "attendance_status": "ABSENT"}
  ],
  "goals": [
    {
      "client_key": "4b911af0-759b-4cb5-bf2c-24afac87fb03",
      "tournament_player_id": 31,
      "credited_to_team_id": 101,
      "goal_type": "REGULAR",
      "notes": null
    },
    {
      "client_key": "783972b2-66b8-4766-8da0-8f68e2cbd999",
      "tournament_player_id": 31,
      "credited_to_team_id": 102,
      "goal_type": "OWN_GOAL",
      "notes": "Autogol"
    }
  ],
  "disciplinary_events": [
    {
      "client_key": "6b58e52f-85a0-4761-b62b-1f7af2d8e5bc",
      "tournament_player_id": 31,
      "event_type": "DIRECT_RED",
      "expulsion_reason_id": 3,
      "notes": null
    }
  ],
  "reason": "Resultado capturado desde el panel de administración."
}
```

`credited_to_team_id` is the participating team's `tournament_teams.id`
registration ID (not the permanent `teams.id` catalogue ID). `attendance_status`
is `PRESENT` or `ABSENT`. Goal types are `REGULAR`,
`PENALTY`, and `OWN_GOAL`; a regular or penalty goal is credited to the
player's team and an own goal to the opposing team. When goal details are
present, their per-team totals must match the score. Each goal/event uses a
stable per-match `client_key` so retrying a report does not add duplicates.
For a player with multiple goals, send one `goals` entry per goal. The result
form represents its numeric goal count this way, using `REGULAR` with `notes`
set to `null`. Attendance should include each active roster player, marked `PRESENT`
or `ABSENT`; omitted attendance rows are removed/replaced with no attendance
record. The server validates that every player is actively registered to one
of the teams in that match. No minute field exists or is accepted.

On success the endpoint returns HTTP 200 with the saved result and refreshed
report:

```json
{
  "success": true,
  "message": "Marcador del partido guardado.",
  "data": {
    "result": {
      "match_id": 12,
      "previous": {"home_score": null, "away_score": null},
      "current": {"home_score": 2, "away_score": 1}
    },
    "report": {
      "match": {},
      "players": [],
      "attendance": [],
      "goals": [],
      "disciplinary_events": [],
      "active_expulsion_reasons": []
    }
  }
}
```

Invalid fields or values return HTTP 422; a missing match returns HTTP 404;
business conflicts such as sending only the score while active players are
registered, a mismatched goal total, or an inactive expulsion reason return
HTTP 409; unauthenticated and non-admin requests return HTTP 401 and 403
respectively. Database errors are logged server-side and returned as a generic
HTTP 500 response.

### Generate an arbitral match sheet PDF

`GET /generar_cedula.php?match_id={id}` generates an inline Letter portrait PDF
for a scheduled match. Send the administrator bearer token in the
`Authorization` header. For example:

```http
GET /apiComplex/backend/public/generar_cedula.php?match_id=1
Authorization: Bearer <admin-token>
```

The endpoint reads the registered teams, tournament, category, round, venue,
date, kickoff time, active players, player goal counts, cautions, and
expulsions from the existing database. It returns `application/pdf` for a
valid match. A missing or invalid `match_id` returns `422`, a missing match
returns `404`, a bye returns `409`, and missing/invalid authentication returns
`401` or `403`. Unexpected generation or database failures return a generic
`500` JSON response and are logged server-side. No request body or database
schema changes are required.

Disciplinary event types are `YELLOW_CARD`, `SECOND_YELLOW_RED`, and
`DIRECT_RED`. Yellow cards require no expulsion reason. Both expulsion types
require an active reason. A second-yellow dismissal is recorded explicitly;
the application does not synthesize yellow-card events.

### Expulsion reason administration

- `GET /api/v1/expulsion-reasons` lists active and inactive reasons.
- `POST /api/v1/expulsion-reasons` creates a reason using `name`,
  `description`, `suspension_matches`, and optional `is_active`.
- `GET /api/v1/expulsion-reasons/{id}` reads one reason.
- `POST /api/v1/expulsion-reasons/{id}` updates any supplied reason fields.
- `DELETE /api/v1/expulsion-reasons/{id}` physically deletes an unused reason;
  a reason already referenced by a disciplinary event is deactivated instead.

`suspension_matches` is an integer from 0 to 65535. When a dismissal event is
first recorded, the system snapshots the reason name and match count into one
`player_suspensions` row. Later reason edits do not change that snapshot.

### Player suspension history

`GET /api/v1/players/{id}/suspensions` returns active and historical
sanctions, including their tournament, match, snapshotted reason, assigned
matches, served matches, remaining matches, and status. A zero-match sanction
is stored as `COMPLETED`; a positive sanction starts as `ACTIVE` with zero
served matches.

The system does not decrement suspensions automatically. No existing,
unambiguous rule determines when a player has served a suspension match (for
example, how to treat postponed or cancelled matches), so `served_matches`
remains zero until an explicit administrative completion workflow is defined.

All new administrative endpoints return `401` without a valid token and `403`
for a non-admin role. Validation errors return `422`, missing records `404`,
and domain conflicts `409`.

### Database migration

For an existing MySQL database, apply `database/migrations/002_match_details_and_discipline.sql`.
The migration adds the five tables: `match_player_attendance`,
`match_goals`, `match_disciplinary_events`, `expulsion_reasons`, and
`player_suspensions`. Goal and disciplinary rows include a per-match
`client_key` used to make full report replacements retry-safe. The migration
does not alter or delete existing tournament tables or records.

If the report/discipline tables already existed before migration 002 was
applied, `CREATE TABLE IF NOT EXISTS` does not bring those tables up to date.
Apply migration 003 to add retry keys and historical reason snapshots, then
apply migration 004 to align goal credits with the supplied schema's
`tournament_teams` foreign key. Migration 004 translates credited catalogue
team IDs back to the corresponding tournament registration using each
match's tournament. Both migrations preserve existing rows and can be rerun.

## Tests

```bash
php vendor/bin/phpunit --configuration phpunit.xml
```

In XAMPP, the complete categories URL is:

`http://127.0.0.1/apiComplex/backend/public/api/v1/categories?page=1&limit=20`
