# AGENTS.md --- Football Tournament Manager

## 1. Project purpose

This repository contains the backend API for **Football Tournament
Manager**, a system for managing amateur football tournaments from setup
through the final champion.

The API is consumed by an independent frontend. Keep the backend
API-first and return JSON.

## 2. Non-negotiable technology constraints

-   Use **native PHP 8.3+** and **MySQL 8.0+**.
-   Use PDO for database access.
-   Do **not** use Laravel, Symfony, Slim, CodeIgniter, an ORM, or a
    backend framework.
-   Composer may be used for autoloading and development/testing tools,
    but do not introduce runtime dependencies without a concrete need
    and explicit approval.
-   Use strict types where practical.
-   Use prepared statements for all user-provided SQL values.
-   Never commit secrets, real credentials, access tokens, or production
    data.
-   Keep code, classes, methods, variables, database objects, and API
    fields in English. User-facing validation messages may be in
    Spanish.

## 3. Work protocol for every request

When asked to create or modify an endpoint, feature, entity, query, or
service:

1.  Inspect the repository and read this file plus relevant skill files
    before editing.
2.  Find the existing route, controller, service, repository, model,
    schema, and test conventions. Do not assume the repository matches
    an example structure if the actual code differs.
3.  Trace the feature end-to-end: route → request parsing/validation →
    authentication/authorization → controller → service/domain rules →
    repository/PDO → response.
4.  Reuse existing patterns and helpers. Do not duplicate infrastructure
    or create parallel abstractions.
5.  Make the smallest coherent change that fully implements the
    requested behavior.
6.  Update or add tests and API documentation where applicable.
7.  Run PHP syntax checks and relevant tests when the environment
    permits. Report commands run and any checks that could not be run.
8.  Summarize files changed, endpoint contract, business rules
    implemented, tests, and known limitations.

Do not stop after creating a route or controller stub. An endpoint is
complete only when validation, authorization, business logic,
persistence, response handling, and relevant tests are implemented.

Do not ask the user to repeat project basics already documented here.
Ask a question only when an ambiguity materially changes the behavior or
data model. Otherwise, inspect the repository and choose the least
surprising implementation consistent with these rules.

## 4. Architecture principles

Follow the repository's existing structure. If the project is new and no
structure exists, use a simple modular structure such as:

-   `public/index.php`: HTTP entry point.
-   `src/Core/`: router, request, response, validation, and exception
    handling.
-   `src/Config/`: PDO configuration.
-   `src/Routes/`: API route registration.
-   `src/Controllers/`: HTTP orchestration only.
-   `src/Services/`: business rules and use cases.
-   `src/Repositories/`: PDO queries and persistence.
-   `src/Models/`: lightweight domain/data representations.
-   `src/Middleware/`: authentication, authorization, CORS.
-   `database/schema.sql`: schema definition.
-   `tests/`: unit and integration tests.

Avoid unnecessary layers and generic abstractions. Controllers should
not contain complex SQL or tournament algorithms. Repositories should
not decide qualification rules. Services should not directly parse HTTP
globals. Keep domain logic testable without an HTTP request.

## 5. API conventions

-   Base path: `/api/v1`.
-   JSON request and response bodies unless an endpoint explicitly
    requires another format.
-   Use appropriate HTTP methods: `GET` to read, `POST` to create or
    execute a command, `PUT` for full updates, `PATCH` for partial
    changes, and `DELETE` only when deletion is valid.
-   Use consistent response shapes. Follow the existing response helper
    if present; otherwise use:
    -   Success: `{"success":true,"data":...,"message":"..."}`
    -   Error: `{"success":false,"message":"...","errors":...}`
-   Use meaningful status codes: 200, 201, 204, 400, 401, 403, 404, 409,
    422, 500.
-   Validate path parameters, query parameters, JSON syntax, field
    types, allowed values, and business invariants.
-   Support pagination for collection endpoints. Bound page size and
    allowlist sortable columns; never interpolate arbitrary
    client-provided column names into SQL.
-   Keep API fields and SQL naming consistent with the existing
    repository.

## 6. Database and PDO rules

-   MySQL is the source of truth for persisted data.
-   Use PDO prepared statements for all values.
-   Bind values with appropriate types when practical.
-   SQL identifiers cannot be parameter-bound. Any dynamic identifier
    must come from a strict server-side allowlist.
-   Use transactions for multi-step operations such as tournament
    enrollment, schedule generation, result correction, and bracket
    advancement.
-   Roll back on failure and preserve the original exception for
    server-side logging without exposing internal details to clients.
-   Add foreign keys, unique constraints, and indexes for important
    invariants when feasible.
-   Do not rely on frontend validation for integrity.
-   Avoid N+1 query patterns and repeated per-row queries in standings
    generation.
-   Do not silently delete or overwrite matches/results that already
    exist.

## 7. Domain glossary and core entities

The system has a permanent catalogue and tournament-specific
participation records.

-   `Category`: a competition category.
-   `Team`: permanent team catalogue record.
-   `Player`: permanent player catalogue record.
-   `Tournament`: competition configuration and lifecycle.
-   `TournamentTeam`: a team's registration in a tournament.
-   `TournamentPlayer`: a player's assignment to a registered team in a
    tournament.
-   `TournamentStage`: a phase, such as group stage, league,
    quarter-final, semi-final, or final.
-   `TournamentGroup`: a group within a stage.
-   `GroupTeam`: membership of a registered team in a group.
-   `Round`: a numbered matchday/round within a stage and optionally a
    group.
-   `MatchGame`: a scheduled or completed match, or an explicitly
    represented bye.
-   `MatchResultHistory`: audit record for a result change.

A catalogue team/player is not the same thing as its registration in a
tournament. Preserve this separation.

## 8. Tournament formats and rules

Tournament type values:

-   `LEAGUE`: one regular-season group, ten regular-season rounds by
    default, then the top eight qualify for quarter-finals, semi-finals,
    and final.
-   `CUP`: direct elimination; one knockout round per matchday/round;
    winners advance until the final.
-   `FLASH`: one or more groups; each group has its own group-stage
    schedule and standings; qualification rules create an eight-team
    final bracket by default.

Default points: win = 3, draw = 1, loss = 0. These values are
configurable per tournament.

Default standings tie-breakers: 1. Points. 2. Goal difference. 3. Goals
scored. 4. Head-to-head where the configured rules support it and it
yields an unambiguous comparison. 5. An explicitly configured
administrative rule.

Do not use insertion order, database row order, or an unstable sort as a
tie-breaker. Document the behavior for multi-team ties.

## 9. Team and player registration invariants

-   A team can participate in many tournaments through `TournamentTeam`.
-   A team must not be registered twice in the same tournament.
-   A player can participate in different tournaments and categories
    when allowed by the competition rules.
-   A player must not be assigned to two teams in the same tournament at
    the same time.
-   The assigned `TournamentTeam` must belong to the same tournament as
    the `TournamentPlayer`.
-   Player registration is enabled by default, with a default cutoff of
    round 4.
-   Administrators can enable or disable registration.
-   Enforce registration policy in the backend on every relevant
    request; hiding a frontend button is not enforcement.
-   Do not infer the current matchday from the maximum generated round
    number. Determine the current/registration round from actual
    tournament state and completed/scheduled matches, following existing
    project rules.
-   Any administrative override must be explicit, authorized, and
    auditable.

## 10. Schedule and bye invariants

-   League: generate ten regular-season rounds by default. Avoid
    duplicate pairings when mathematically possible. With an odd number
    of teams, distribute byes as evenly as possible.
-   Flash: generate schedules independently for each group. Group-stage
    matches must not cross group boundaries.
-   Cup: generate knockout rounds and use byes/automatic advances only
    when needed for the bracket size and permitted by the tournament
    rules.
-   A bye is not a real match result. It must not add goals, points,
    played matches, wins, or losses to standings.
-   Represent byes consistently with the schema already used in the
    repository. If using `is_bye`, validate that exactly one real team
    is attached.
-   Schedule generation must be deterministic for the same inputs unless
    an explicit random draw is requested.
-   Re-running generation must not duplicate rounds or matches.
-   Do not overwrite existing results. If a schedule already has played
    matches, reject destructive regeneration unless a specifically
    authorized workflow handles the consequences.
-   Manual edits to date, time, venue, and pairings must preserve
    tournament/group consistency.

## 11. Results, standings, and qualification

-   Only eligible completed matches contribute to standings.
-   Pending, postponed, cancelled, and bye records do not count as
    completed matches.
-   Score correction must be audited and must trigger recalculation of
    affected standings.
-   If a changed result affects already-created knockout rounds,
    identify the affected downstream matches and require a safe,
    explicit resolution instead of silently changing completed matches.
-   A knockout match cannot advance a winner while tied unless the
    configured resolution (penalties, extra time, or another explicit
    rule) has been recorded.
-   League qualification defaults to the top eight, seeded 1 vs 8, 2 vs
    7, 3 vs 6, 4 vs 5, with configurable seeding if the project supports
    it.
-   Flash qualification rules must support configurable qualifiers per
    group and comparison of teams across groups. Example: six group
    winners plus the two best runners-up.
-   Do not generate a final bracket unless the configured qualification
    rules produce the expected number of unique qualified teams.
-   The champion is final only after the final has a definitive result.

## 12. Security

-   Use `password_hash()` and `password_verify()` for passwords.
-   Authenticate protected endpoints and enforce roles/permissions on
    the server.
-   Never trust a user ID, role, tournament ID, team registration, or
    authorization claim supplied only in the request body.
-   If using tokens, verify signatures and expiration with a reviewed
    implementation; do not invent cryptography.
-   Restrict CORS to configured origins.
-   Validate content type and JSON parsing.
-   Protect against SQL injection using PDO prepared statements.
-   Avoid leaking stack traces, SQL, file paths, secrets, or sensitive
    data in API errors.
-   Log useful operational details server-side without logging
    passwords, bearer tokens, or other secrets.
-   Keep production-only files and logs outside the public web root.

## 13. Testing and verification

For every feature: - Add tests for normal behavior, validation failures,
authorization failures, and domain edge cases. - Test the business rule,
not merely the HTTP status. - Prefer unit tests for
schedule/standings/qualification algorithms and integration tests for
PDO persistence and endpoints. - Run `php -l` against changed PHP
files. - Run the available test suite when possible. - If a test cannot
run because a service or dependency is missing, state the exact
limitation instead of claiming success.

Important scenarios include odd team counts, bye balancing, duplicate
registrations, disabled player registration, the round-4 cutoff,
multi-group qualification, tie-breakers, result corrections, and
duplicate schedule generation.

## 14. Documentation

Update endpoint documentation whenever an endpoint contract changes.
Include method, path, authentication requirements, query/path
parameters, request JSON, success response, error responses, and
relevant business rules.

Keep `.env.example` free of real secrets. Keep the README's installation
and run instructions aligned with the actual code.

## 15. Definition of done

A requested endpoint or feature is complete only when: - Route is
registered. - Input is validated. - Authentication/authorization is
applied where required. - Business rules are enforced in a service or
equivalent domain layer. - PDO persistence uses prepared statements. -
Multi-step changes are transactional where necessary. - Responses and
error statuses follow project conventions. - Relevant tests and
documentation are updated. - PHP syntax checks and available tests have
been run, with results reported.
