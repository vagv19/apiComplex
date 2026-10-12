---
description: Build, modify, test, or debug automatic and manual football
  schedules for league, flash group-stage, and cup tournaments,
  including balanced byes and duplicate-generation protection.
name: schedule-generation
---

# Schedule Generation

Use this skill whenever generating rounds, pairings, byes, or knockout
brackets.

## General procedure

1.  Read `AGENTS.md` and inspect the schema and existing schedule
    service.
2.  Load the active registered teams and relevant group membership.
3.  Validate minimum and maximum participant counts required by the
    selected format.
4.  Generate the schedule in memory first and validate all invariants
    before persistence.
5.  Persist rounds and matches inside a transaction.
6.  Prevent duplicate generation and never overwrite completed results.
7.  Add tests for even/odd team counts and repeat execution.

## League

-   Generate ten regular-season rounds by default.
-   Avoid self-play and duplicate pairings where mathematically
    possible.
-   For odd team counts, use a bye placeholder/state, not a fake
    registered team.
-   Balance the number of byes across teams as evenly as possible.
-   Validate that every expected round has valid pairings and each team
    appears at most once per round.

## Flash

-   Generate schedules separately for each group.
-   Never pair teams from different groups during the group stage.
-   Balance byes within each group.
-   Keep group and stage references correct on every round and match.

## Cup

-   Use direct elimination.
-   Create the next round only when its participants are known or a
    valid automatic advance can be represented.
-   A bye/automatic advance is not a completed match and does not award
    goals, league points, wins, or played-match statistics.
-   A tied knockout match cannot produce a winner until the configured
    resolution is recorded.

## Manual changes and safety

-   Allow manual match date, kickoff time, venue, and permitted pairing
    changes.
-   Validate team eligibility and avoid conflicting simultaneous
    fixtures.
-   If schedule generation is repeated, return a conflict or an
    idempotent existing result; do not duplicate records.
-   If any played match exists, reject destructive regeneration unless a
    dedicated authorized workflow resolves dependent results and phases.
-   Keep the algorithm independent of HTTP and PDO so it can be
    unit-tested.
