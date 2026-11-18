# Football Tournament Manager API

Backend REST API for tournament administration built in native PHP and PDO.

## Features

- Catalog CRUD for categories, teams, and players
- Tournament lifecycle and registration management
- Round and match scheduling
- Results and standings calculations
- Bearer authentication with role capabilities and active-account checks
- Superadmin-only, audited player-registration overrides
- Administrative audit log
- SQLite-compatible test environment for local validation

## Setup

1. Copy `.env.example` to `.env` and set the existing MySQL database name and credentials.
2. For a new database, run `database/schema.sql` once; it creates the `football_tournaments` database and tables.
3. The SQLite schema at `database/schema.sqlite.sql` is used only for local tests.
4. For an existing tournament database, apply `database/migrations/005_assign_director_to_tournament_team.sql` and then `database/migrations/006_use_tournament_team_coaches.sql`; the latter migrates assignments from the earlier `director_user_id` column when present. Apply `database/migrations/007_player_registration_window.sql` to add the registration timestamps if they are not already present. Apply `database/migrations/002_match_details_and_discipline.sql`; if its match-report tables predate the current API schema, also apply `database/migrations/003_repair_existing_match_report_schema.sql` followed by `database/migrations/004_align_match_report_to_attached_schema.sql`. Apply `database/migrations/008_superadmin_authorization_audit.sql` and then `database/migrations/009_user_access_management.sql`. These migrations preserve existing accounts and do not promote anyone to superadmin.
5. Configure `JWT_SECRET` in `backend/.env` with at least 32 random characters; the API deliberately fails closed if it is missing or too short. Generate a value with `php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"`. Keep it out of source control. Configure `JWT_ISSUER`; set `JWT_AUDIENCE` only when the same value is configured for the API clients.
6. Create the first superadmin from the backend directory, without placing the password in shell history:

   ```powershell
   $securePassword = Read-Host "New superadmin password" -AsSecureString
   $env:SUPERADMIN_BOOTSTRAP_PASSWORD = [Net.NetworkCredential]::new("", $securePassword).Password
   php bin/create-superadmin.php admin@example.com "Tournament Superadmin"
   Remove-Item Env:SUPERADMIN_BOOTSTRAP_PASSWORD
   ```

   The command requires MySQL, hashes the password with `password_hash()`, and refuses to create another account while an active superadmin exists. Run it from a trusted host and clear the environment variable after use. New user accounts must be created with an explicit role; no role is assigned by default.
7. Optionally populate seed data from `database/seeders/initial_data.sql` in the SQLite test database.
8. With XAMPP running, access the API at:

   `http://127.0.0.1/apiComplex/backend/public/api/v1`

   For local development with PHP's built-in server, run these commands
   from the `backend` directory:

```bash
php -S 127.0.0.1:8000 public/index.php
```

## API base path

`/api/v1`

## HTTP methods and authorization

Functional API routes use only `GET` for reads and `POST` for all changes. The
API does not register `PUT`, `PATCH`, or `DELETE` operations; requests using an
unsupported method against a known route receive `405` with
`Allow: GET, POST, OPTIONS`. `OPTIONS` is handled only by CORS preflight.
Destructive actions remain soft deletes or domain-specific cancellation
operations where that is the existing business behavior.

Every route declares a capability enforced in the router. `superadmin` inherits
all `admin` capabilities and additionally receives
`players.registration.override_deadline`, `system.audit.read`, `users.manage`,
`roles.manage`, `modules.manage`, and `users.password.reset`. Administrators can
create admin and director accounts, but can manage director accounts only; only
superadmins can manage administrator/superadmin accounts or create a
superadmin. Only superadmins can change roles. Directors cannot administer
users, roles, or permissions.

Protected access resolves the account's current active status, role, deletion
state, forced-password-change flag, and `auth_version` from MySQL on each
request. Module assignment is an additional restriction and cannot expand a
role's capability ceiling. Superadmins can access all modules regardless of
individual assignments. Director tournament queries are limited to tournaments
with an active team assignment, and team/player lists are filtered to their
assigned team.

## Example routes

- `GET /api/v1/dashboard`
- `GET /api/v1/categories`
- `POST /api/v1/teams`
- `POST /api/v1/teams/{id}/logo`
- `POST /api/v1/teams/{id}/delete`
- `POST /api/v1/players/{id}/photo`
- `POST /api/v1/players/{id}/delete`
- `GET /api/v1/tournaments`
- `POST /api/v1/tournaments`
- `GET /api/v1/tournaments/logical`
- `POST /api/v1/tournaments/logical`
- `GET /api/v1/tournaments/logical/available-categories`
- `GET /api/v1/tournaments/logical/{logical_id}`
- `POST /api/v1/tournaments/logical/{logical_id}/details`
- `POST /api/v1/tournaments/logical/{logical_id}/rules`
- `POST /api/v1/tournaments/logical/{logical_id}/categories`
- `POST /api/v1/tournaments/logical/{logical_id}/categories/remove`
- `POST /api/v1/tournaments/logical/{logical_id}/retire`
- `POST /api/v1/tournaments/{id}/teams`
- `GET /api/v1/tournaments/{id}/teams`
- `POST /api/v1/tournaments/{id}/teams/{team_id}/director`
- `GET /api/v1/tournaments/{id}/my-team`
- `POST /api/v1/tournaments/{id}/my-team/players`
- `GET /api/v1/tournaments/{id}/players`
- `POST /api/v1/tournaments/{id}/players`
- `POST /api/v1/tournaments/{id}/players/batch`
- `DELETE /api/v1/tournaments/{id}/players/{registration_id}`
- `POST /api/v1/matches/{id}/result`
- `POST /api/v1/matches/{id}/schedule`
- `GET /api/v1/matches/by-date?date=YYYY-MM-DD`
- `GET /api/v1/public/matches/first?date=YYYY-MM-DD`
- `GET /api/v1/matches/{id}/report`
- `POST /api/v1/matches/{id}/report`
- `POST /api/v1/tournaments/{id}/status`
- `POST /api/v1/tournaments/{id}/player-registration`
- `POST /api/v1/tournaments/{id}/groups/cancel`
- `POST /api/v1/expulsion-reasons/{id}`
- `POST /api/v1/expulsion-reasons/{id}/delete`
- `POST /api/v1/players/registration-override`
- `GET /api/v1/audit` (superadmin)
- `GET /api/v1/users` (admin: admin accounts read-only and manageable directors; superadmin: all)
- `GET /api/v1/users/{id}`
- `GET /api/v1/users/{id}/modules`
- `GET /api/v1/users/{id}/teams`
- `GET /api/v1/users/{id}/audit`
- `GET /api/v1/roles`
- `GET /api/v1/modules`
- `GET /api/v1/permissions`
- `GET /api/v1/auth/me`
- `POST /api/v1/users/create`
- `POST /api/v1/users/update`
- `POST /api/v1/users/change-role` (superadmin)
- `POST /api/v1/users/soft-delete`
- `POST /api/v1/users/restore`
- `POST /api/v1/users/assign-modules`
- `POST /api/v1/users/assign-teams`
- `POST /api/v1/users/remove-team`
- `POST /api/v1/users/change-password`
- `POST /api/v1/users/reset-password`
- `GET /api/v1/public/tournaments`
- `GET /api/v1/public/facilities/images`
- `GET /api/v1/public/facilities/images/{filename}`
- `GET /api/v1/public/tournaments/{id}/top-scorers`
- `GET /api/v1/public/tournaments/{id}/images`
- `GET /api/v1/public/tournaments/{id}/images/{filename}`

### Dashboard summary

`GET /api/v1/dashboard` requires an administrator or superadmin bearer token
with the dashboard module enabled. It returns the total number of tournaments,
the number of active categories, teams, and players, and up to five most
recently created tournaments. A count is `null` when the corresponding module
is not available to the user; `recent_tournaments` is `null` without tournament
module access.

```json
{
  "success": true,
  "data": {
    "counts": {
      "tournaments": 3,
      "categories": 2,
      "teams": 8,
      "players": 24
    },
    "recent_tournaments": [
      {
        "id": 12,
        "category_id": 2,
        "category_name": "Libre",
        "name": "Torneo de verano",
        "format": "LEAGUE",
        "status": "REGISTRATION",
        "created_at": "2026-11-16 12:00:00"
      }
    ]
  },
  "message": "Resumen consultado."
}
```

### Upload a player photo

`POST /api/v1/players/{id}/photo` requires an administrator or director
technician bearer token and a `multipart/form-data` file field named `photo`.
Directors may upload only photos for players actively registered to a team
assigned to them; administrators may upload for any player. The endpoint
accepts JPEG, PNG, and WEBP images up to 5 MB. It is intended for players
without an existing photo; attempts to upload a second photo return `409`.
The stored filename is saved to the player's `photo_url` and the file is served
from `/backend/img/players/{filename}`.

```bash
curl -X POST http://127.0.0.1/apiComplex/backend/public/api/v1/players/42/photo \
  -H "Authorization: Bearer <admin-token>" \
  -F "photo=@player.jpg"
```

Success returns HTTP `200` with the updated player in `data`. Invalid or missing
images return `422`; unauthenticated/unauthorized requests return `401`/`403`;
unknown players return `404`; players that already have a photo return `409`.

### Upload or replace a team logo

`POST /api/v1/teams/{id}/logo` requires an administrator or director
technician bearer token and a `multipart/form-data` file field named `logo`.
Directors may update only teams actively assigned to them; administrators may
update any active team. The endpoint accepts JPEG, PNG, and WEBP images up to
5 MB. The stored filename is saved to `teams.logo_url` and served from
`/backend/img/teams/{filename}`. Replacing a logo removes the previous
application-managed image after the new filename is persisted.

```bash
curl -X POST http://127.0.0.1/apiComplex/backend/public/api/v1/teams/12/logo \
  -H "Authorization: Bearer <director-token>" \
  -F "logo=@team-logo.png"
```

Success returns HTTP `200` with the updated team in `data`. Invalid or missing
images return `422`; unauthenticated/unauthorized requests return `401`/`403`;
unknown teams return `404`.

### Register a tournament player

Create a player in the permanent catalogue with `POST /api/v1/players`
(`first_name`, `last_name`, and optional `birth_date`, `phone`, and `email`),
then register that player to an active tournament team with
`POST /api/v1/tournaments/{id}/players`. Use
`GET /api/v1/tournaments/{id}/teams` to list selectable teams. The registration
request requires an authenticated admin or superadmin with
`players.registration.manage`, plus `team_id` and `player_id`; the optional
`jersey_number` must be an integer from 0 to 65535 and must be unique within
that tournament team.
The registration endpoint enforces `player_registration_enabled`, configured
opening/closing timestamps, and `player_registration_deadline_round`. The round
cutoff uses actual scheduled-for-today, in-progress, or completed real matches;
generated future rounds and byes do not advance it. The endpoint returns the
tournament roster in `data`.

```json
{
  "team_id": 12,
  "player_id": 42,
  "jersey_number": 10
}
```

```http
POST /api/v1/tournaments/7/players
Content-Type: application/json
```

Success returns HTTP `201` with the standard
`{"success":true,"message":"...","data":[...]}` response; each roster record
includes the registration, team, and player profile fields. Re-registering an
already registered player returns HTTP `200` and the current roster.

Invalid fields return `422`; a closed registration, duplicate jersey, or other
business-rule conflict returns `409`; a missing tournament or player returns
`404`. Player creation and tournament registration run in a single transaction.
Superadmins can use this same endpoint outside the configured registration
window or cutoff, including when registration is disabled. Their authenticated
identity and the exception are recorded in the audit log in the registration
transaction. This does not bypass active-player status, tournament-team
membership, duplicate registration, or unique jersey-number constraints;
administrators remain subject to the configured registration rules.

To register several existing bank players to the same team atomically, use
`POST /api/v1/tournaments/{id}/players/batch` with `team_id` and a distinct
`player_ids` array containing 1 to 100 active player IDs:

```json
{
  "team_id": 12,
  "player_ids": [42, 43, 44]
}
```

The endpoint requires `players.registration.manage` and applies the same
registration-window and authorization rules as individual registration.
Registration of the full selection is transactional: if one player is invalid
or already registered, the request returns an error and does not partially
register the others. Success returns HTTP `201`, the updated tournament roster
in `data`, and a message with the number of registered players. Invalid or
duplicate IDs return `422`; registration policy or roster conflicts return
`409`. Superadmin registrations retain the existing audited deadline override.

### Director-to-team assignment

The `tournament_team_coaches` relation stores `id`, `tournament_team_id`,
`user_id`, `is_active`, and `assigned_at`. Each active tournament team can have
one active account with the `director_tecnico` role, and each director can
manage at most one team per tournament. An administrator assigns the director
after the team is enrolled:

```http
POST /api/v1/tournaments/7/teams/12/director
Authorization: Bearer <admin-token>
Content-Type: application/json

{"director_user_id": 5}
```

The director portal does not send a team or director ID when loading or adding
players. It uses the authenticated token's subject to resolve the assigned team
for the selected tournament:

- `GET /api/v1/tournaments/{id}/my-team` accepts `director_tecnico` and `admin`
  tokens and returns `data.team` and that team's `data.players`. A director's
  team is derived from the token; an administrator must pass `team_id` as a
  query parameter.
- `POST /api/v1/tournaments/{id}/my-team/players` accepts the same roles and
  player profile fields plus `jersey_number`. `first_name`, `paternal_surname`,
  and `maternal_surname` are required; the two surnames are combined into the
  catalog's `last_name` field. Catalog creation and tournament registration run
  in one transaction. A director's team is derived from the token and ignores
  any submitted `team_id`; an administrator must send the target `team_id` in
  the JSON body.
- `DELETE /api/v1/tournaments/{id}/players/{registration_id}` requires the
  `players.registration.manage` capability. It removes only the
  `tournament_players` registration; the catalogue record in `players` is
  retained. Both path parameters must be positive integers; no request body is
  required. Success returns `200` with
  `{"success":true,"data":{"registration_id":42},"message":"..."}`. Invalid
  identifiers return `422`, a registration missing from that tournament
  returns `404`, and match attendance, goals, or disciplinary events referencing
  the registration return `409` to preserve match history.

Example request body:

```json
{
  "first_name": "Luis Fernando",
  "paternal_surname": "García",
  "maternal_surname": "Martínez",
  "birth_date": "2000-01-02",
  "jersey_number": 8
}
```

An unassigned director receives `404`; a closed registration, duplicate jersey,
or conflicting assignment receives `409`. Invalid or missing admin team IDs
and invalid player fields return `422`; callers without the required role
receive `401` or `403`.

### Superadmin registration override

`POST /api/v1/players/registration-override` requires a superadmin bearer token.
It registers an existing active catalogue player to an active team in the
specified tournament even when the ordinary registration window, enabled flag,
or round cutoff would reject the registration. The reason is mandatory and the
registration plus audit record are committed in the same transaction. The
override does not bypass tournament-team membership, active-player status,
duplicate registration, or unique jersey-number constraints.

```http
POST /api/v1/players/registration-override
Authorization: Bearer <superadmin-token>
Content-Type: application/json

{
  "tournament_id": 7,
  "team_id": 12,
  "player_id": 42,
  "jersey_number": 10,
  "reason": "Autorización excepcional del comité."
}
```

Missing or invalid IDs and missing/blank reasons return `422`; insufficient
permissions return `403`; missing tournament/player records return `404`;
duplicate or inconsistent registrations return `409`. The actor is always
derived from the validated bearer token, never from the request body.

### Audit history

`GET /api/v1/audit?page=1&limit=50` requires `system.audit.read` (currently
superadmin only). It returns paginated administrative audit records, including
the actor, role, action, entity, tournament, reason, before/after values, IP
address, and timestamp. The API exposes no update or delete operation for audit
records. Result changes and registration overrides are written to the audit
table within their domain transaction; result changes are also retained in
`match_result_history`.

### Applying and rolling back migration 008

Apply the migration after the existing migrations using the MySQL CLI:

```bash
mysql --database=football_tournaments < database/migrations/008_superadmin_authorization_audit.sql
```

It adds `users.auth_version`, removes the privileged default role, installs a
trigger that increments the version when a role or active flag changes, and
creates `admin_audit_log`. It does not modify existing roles or passwords.
There is intentionally no destructive rollback for the audit table or version
column: to roll back application code, restore the previous application while
retaining these additive schema changes and audit records. Tokens created by
the new JWT format require users to sign in again after deployment.

### User, role, and module administration

Apply migration 009 after migration 008:

```bash
mysql --database=football_tournaments < database/migrations/009_user_access_management.sql
```

The migration adds `users.deleted_at` for logical deletion and
`users.must_change_password` for temporary password resets. It reuses the
existing `users` table, `password_hash`, and `auth_version`; it does not create
another identity table or remove records. It creates `system_modules` and
`user_modules`, seeds the modules represented by the frontend, and grants
existing active admins/directors role-appropriate initial modules. The
`tournament_team_coaches` relation remains the source of director-team
assignments. Module edits do not grant capabilities excluded by the role.

All endpoints below require a bearer token. User responses never contain
`password_hash` or `auth_version`.

`GET /api/v1/users?page=1&limit=20&search=ana&role=director_tecnico&is_active=1&sort_by=name&sort_order=asc`
returns a paginated directory. `search`, `role`, and `is_active` are optional;
`sort_by` is allowlisted. Admins see admin accounts in read-only mode and can
manage director accounts; superadmins see all roles.

Create a user with `POST /api/v1/users/create`:

```json
{
  "name": "Ana López",
  "email": "ana@example.com",
  "password": "use-a-unique-12-character-password",
  "confirm_password": "use-a-unique-12-character-password",
  "role": "director_tecnico",
  "is_active": true,
  "modules": ["dashboard", "teams", "rosters"],
  "assignments": [
    {"tournament_id": 12, "tournament_team_id": 35}
  ]
}
```

The endpoint hashes the password and transactionally stores the user,
role-limited module assignments, optional director-team assignments, and audit
record. Admins may create admin and director accounts; only superadmins may
create superadmins. Team assignments are accepted only for `director_tecnico`;
admin accounts are created without team assignments. Duplicate email returns
`409`, invalid fields return `422`, and forbidden role creation returns `403`.

`POST /api/v1/users/update` accepts `user_id`, `name`, `email`, and an optional
`modules` array. Use `POST /api/v1/users/change-role` with `user_id` and `role`
to change a role (superadmin only). This invalidates previous tokens and keeps
only modules allowed by the new role. `POST /api/v1/users/assign-modules`
accepts `user_id` and the complete replacement `modules` array; an empty array
removes all non-superadmin module access.

`POST /api/v1/users/soft-delete` accepts `user_id` and a required `reason`.
It sets `is_active = 0`, sets `deleted_at`, increments `auth_version`, and
preserves the account and all relations. `POST /api/v1/users/restore` accepts
`user_id`; it clears `deleted_at`, activates the account, and invalidates old
tokens. The last active superadmin cannot be deactivated, deleted, or demoted.
No API route physically deletes a user.

`POST /api/v1/users/reset-password` accepts `user_id`. It returns one
cryptographically generated temporary password once, sets
`must_change_password`, increments `auth_version`, and excludes the password
from audit data. The user must sign in with that password and change it through
`POST /api/v1/users/change-password` (`current_password`, `new_password`,
`confirm_password`) before accessing other protected routes. The change
endpoint returns a replacement token after invalidating prior sessions.

Director assignments use `POST /api/v1/users/assign-teams` with
`user_id` and `assignments` (`tournament_id`, `tournament_team_id` pairs).
`POST /api/v1/users/remove-team` accepts `user_id`, `tournament_team_id`, and a
required reason. These operations reuse `tournament_team_coaches`, enforce the
existing one-team-per-director-per-tournament rule, and write audit records.
`GET /api/v1/users/{id}/teams` and `GET /api/v1/users/{id}/audit` are read-only.

Role capabilities remain defined centrally in `RolePermissions`; `GET
/api/v1/permissions` exposes that matrix for the interface. The backend checks
capability and module access independently on each protected route. A module
assignment therefore never grants a write permission that the role does not
have. `GET /api/v1/auth/me` returns the current account, assigned modules, and
role capability list for menu construction and route guards.

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

### Galería pública de instalaciones

Ambas rutas son públicas, no requieren token y no reciben cuerpo JSON.

`GET /api/v1/public/facilities/images` no requiere parámetros. Devuelve las
imágenes permitidas de `backend/img/facilities/`, ordenadas por nombre.
Acepta JPG, JPEG, PNG, WEBP y GIF; excluye archivos ocultos, enlaces
simbólicos y archivos con otras extensiones.

```json
{
  "success": true,
  "message": "Galería de instalaciones consultada.",
  "data": [
    {
      "filename": "cancha.jpg",
      "url": "/backend/public/api/v1/public/facilities/images/cancha.jpg"
    }
  ]
}
```

Un directorio legible pero vacío devuelve `200` con `"data": []`. Un fallo al
enumerar la carpeta devuelve `500` con la forma estándar de error JSON.

`GET /api/v1/public/facilities/images/{filename}` recibe el nombre de archivo
en la ruta y devuelve sus bytes con el tipo MIME correspondiente. No acepta
rutas físicas, separadores de directorio ni extensiones no permitidas; esos
casos y los archivos inexistentes responden `404`. Los fallos de lectura
responden `500` sin exponer detalles internos.

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
  "player_registration_start_at": "2026-10-01 00:00:00",
  "player_registration_end_at": "2026-10-20 23:59:59",
  "rounds_count": 10,
  "qualified_teams": 8,
  "points_for_win": 3,
  "points_for_draw": 1,
  "points_for_loss": 0
}
```

The endpoint responds with HTTP `201` and the tournament under `data`, using
the API field names (`format`, `points_for_win`, and similar). Invalid fields
return `422`; an unknown or inactive category returns `404`.
If both player-registration timestamps are omitted, the opening defaults to
the tournament creation time and the closing defaults to 20 days later. The
same default applies when adding a category competition to a logical
tournament. Supplying both timestamps explicitly overrides the default.

The PDO repository maps these API names to the installed MySQL tournament
columns (`tournament_type`, `points_win`, `regular_rounds`, and related
columns), so API names remain consistent for the frontend.

### Logical multi-category tournaments

These authenticated management routes are available to `admin` and
`superadmin` users. They do not change the existing numeric
`/api/v1/tournaments` routes: those routes still identify one category-specific
competition used by scheduling, registration, results, and standings.

The logical API uses collision-free string IDs: `group-<id>` identifies a row
in `tournament_masters`, while `single-<id>` represents an older competition
whose `master_tournament_id` is `NULL`. Neither ID is the numeric
`tournament_id` of a category competition. Each category in a logical response
includes its actual numeric `tournament_id` for existing sports modules.

`GET /api/v1/tournaments/logical?page=1&limit=15` returns a paginated
collection containing grouped tournaments and standalone legacy competitions.
The page size is limited to 100. `GET /api/v1/tournaments/logical/{logical_id}`
returns one logical entity.
`GET /api/v1/tournaments/logical/available-categories` returns active
categories; optionally pass `logical_id=group-<id>` to exclude categories
already linked to that group. Pagination uses the same `page` and `limit`
parameters.

```json
{
  "success": true,
  "message": "Torneo lógico consultado.",
  "data": {
    "id": "group-12",
    "name": "Copa de otoño",
    "description": null,
    "season": "2026",
    "start_date": "2026-10-20",
    "end_date": "2026-12-15",
    "status": "ACTIVE",
    "categories": [
      {
        "tournament_id": 45,
        "category_id": 1,
        "category_name": "Senior",
        "tournament_name": "Copa de otoño-Senior",
        "status": "REGISTRATION",
        "player_registration_start_at": "2026-01-15 12:00:00",
        "player_registration_end_at": "2026-02-04 12:00:00"
      }
    ],
    "category_count": 1,
    "rules_consistent": true,
    "rules": {
      "format": "LEAGUE",
      "status": "REGISTRATION",
      "points_for_win": 3,
      "points_for_draw": 1,
      "points_for_loss": 0,
      "rounds_count": 10,
      "qualified_teams": 8,
      "player_registration_deadline_round": 4,
      "allow_player_registration": true,
      "player_registration_start_at": "2026-01-15 12:00:00",
      "player_registration_end_at": "2026-02-04 12:00:00",
      "tie_breakers": null
    },
    "created_at": "2026-01-15 12:00:00"
  }
}
```

`POST /api/v1/tournaments/logical` creates the logical record and one
category-specific competition for every distinct active `category_id`, in one
transaction. General fields are `name`, optional `description`, `season`,
`start_date`, `end_date`, and master `status` (`DRAFT`, `ACTIVE`, `COMPLETED`,
or `CANCELLED`). Each child competition is named
`{name}-{category_name}`. The combined name must fit the existing 150-character
competition name field. Rules are supplied in a nested object using the
existing tournament API fields:

```json
{
  "name": "Copa de otoño",
  "description": "Competencia anual",
  "season": "2026",
  "start_date": "2026-10-20",
  "end_date": "2026-12-15",
  "status": "DRAFT",
  "category_ids": [1, 2],
  "rules": {
    "format": "LEAGUE",
    "status": "REGISTRATION",
    "points_for_win": 3,
    "points_for_draw": 1,
    "points_for_loss": 0,
    "rounds_count": 10,
    "qualified_teams": 8,
    "player_registration_deadline_round": 4,
    "allow_player_registration": true,
    "tie_breakers": null
  }
}
```

When the timestamps are omitted during creation, each category competition
starts its registration window at creation and closes it 20 days later. The
logical response exposes the current window and actual competition name for
each category. Window dates are managed per category; updating shared rules
without timestamp fields preserves each category's existing window. If either
timestamp is explicitly included in a logical rules update, the resulting
window is applied to all categories.

`POST /api/v1/tournaments/logical/{logical_id}/details` updates only the
provided general fields and propagates name and dates to every category
competition. `POST /api/v1/tournaments/logical/{logical_id}/rules` accepts
`{"rules":{...}}`, merges the supplied values with the current settings, and
updates every associated competition. A format change is rejected once any
category has stages. `rules_consistent` reports differences in shared
competition settings, not per-category registration windows; updating shared
rules synchronizes those settings without changing category windows unless
timestamps were explicitly supplied. For grouped tournaments,
the master status follows the competition status: `DRAFT` remains `DRAFT`,
`REGISTRATION` and `IN_PROGRESS` map to `ACTIVE`, and `COMPLETED` or
`CANCELLED` map directly. Updating the logical tournament name also updates
each child name using the current category name.

`POST /api/v1/tournaments/logical/{logical_id}/categories` accepts
`{"category_id":3}` and creates a competition named
`{tournament_name}-{category_name}` using the logical tournament's shared
settings. `POST /api/v1/tournaments/logical/{logical_id}/categories/remove`
accepts the same
body, requires at least one other category to remain, and refuses removal if
the category has registrations, phases, rounds, matches, or match-related
activity. A successful removal preserves the competition row, marks it
`CANCELLED`, and detaches it as a standalone record; it does not delete data.
The category's numeric tournament ID remains valid for old sports references.

`POST /api/v1/tournaments/logical/{logical_id}/retire` accepts an optional
`{"reason":"..."}`. It marks the logical tournament and its category
competitions `CANCELLED` without deleting data, but only when there are no
registered teams/players, phases, rounds, matches, results, goals, attendance,
disciplinary events, or suspensions. Repeating retirement is safe and does
not create duplicate audit events.
State-changing creates, edits, category changes, and retirements are written to
the existing administrative audit log in the same transaction.

Invalid IDs, fields, dates, rules, or duplicate category IDs return `422`;
missing or inactive categories return `404`; attempted changes that conflict
with existing sports activity or lifecycle rules return `409`. Older
single-category rows remain independent and are not automatically migrated or
rewritten. They can be read, have supported name/date and competition rule
fields edited, and be retired through the logical API, but cannot be converted
into a multi-category group or have categories attached to them. All these
operations use the existing tables and columns; no schema change or migration
is required. The deployed MySQL schema was verified to contain these objects;
the repository's SQL/SQLite test schemas are intentionally unchanged, so a
fresh database without these existing objects is not supported by this
no-DDL implementation.

Player enrollment uses `player_registration_start_at` and
`player_registration_end_at`, in the `YYYY-MM-DD HH:MM:SS` format. Each
non-null timestamp is enforced as a boundary; if both are `null`, there is no
date-based restriction. New tournaments default to an opening at creation and
a closing 20 days later. Tournament status,
`allow_player_registration`, and `player_registration_deadline_round` do not
affect enrollment. The create response and tournament listings include both
timestamps and a computed `player_registration_open` boolean.

The application currently preserves the database selected by an existing
`.env`; it does not create, switch, or migrate a live database automatically.
Do not change an existing `DB_NAME` to `football_tournaments` unless that
database has been created and its data has been verified.

### Update tournament settings

Tournament status and player-registration settings are updated with `POST`:

- `POST /api/v1/tournaments/{id}/status` accepts `{"status":"IN_PROGRESS"}`.
- `POST /api/v1/tournaments/{id}` accepts the tournament fields, including
  `name` and either or both registration timestamps. When only one timestamp
  changes, the resulting start must not be later than the resulting end.
- `POST /api/v1/tournaments/{id}/player-registration` accepts
  `{"allow_player_registration":false}`; omitting the field toggles the setting.

The player-registration setting route remains for compatibility, but its
legacy flag no longer opens or closes enrollment. Only the configured time
window is enforced. An enrollment attempt outside that window returns `409`.
Invalid date formats or a reversed range return `422`.

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
