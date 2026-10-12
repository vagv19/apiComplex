# SKILLS.md --- Skill Catalog

This file is the human-readable index of the project skills.
**Copilot-compatible Agent Skills must be stored as individual
`SKILL.md` files in skill directories**; this index alone is not a
loadable skill.

## Project skill location

Skills are stored in `.github/skills/<skill-name>/SKILL.md`. GitHub
Copilot in VS Code also supports other project skill locations, but this
repository uses `.github/skills/` as the canonical location.

## Skills included

  -----------------------------------------------------------------------
  Skill                               Use when
  ----------------------------------- -----------------------------------
  `create-api-endpoint`               Adding or modifying a REST endpoint
                                      end-to-end.

  `php-pdo-database`                  Adding schema, queries,
                                      repositories, or transactional
                                      persistence.

  `tournament-domain-rules`           Implementing tournament lifecycle,
                                      registrations, and invariants.

  `schedule-generation`               Generating league, flash-group, or
                                      cup schedules and byes.

  `standings-and-qualification`       Calculating standings,
                                      tie-breakers, qualifiers, and
                                      brackets.

  `testing-and-validation`            Adding tests, validating a feature,
                                      or diagnosing failures.

  `security-review`                   Reviewing authentication,
                                      authorization, input handling, and
                                      data exposure.
  -----------------------------------------------------------------------

## General invocation examples

-   `Use the /create-api-endpoint skill to implement GET /api/v1/teams/{id}.`
-   `Use the /php-pdo-database skill to add the persistence required by this endpoint.`
-   `Use the /schedule-generation skill to implement the schedule generation endpoint for FLASH tournaments.`
-   `Use the /standings-and-qualification skill to implement GET /api/v1/groups/{id}/standings.`

## Skill authoring rules

-   Every skill directory must contain a file named exactly `SKILL.md`.
-   Each `SKILL.md` must have YAML frontmatter containing `name` and
    `description`.
-   The `name` must match the directory name, use lowercase
    letters/numbers/hyphens, and be unique.
-   Keep a skill focused on one repeatable workflow. Refer to
    `AGENTS.md` for repository-wide rules.
-   Skills provide task-specific steps; they do not override the
    technology constraints or domain invariants in `AGENTS.md`.
