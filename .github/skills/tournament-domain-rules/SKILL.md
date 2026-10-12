---
description: Implement tournament creation, state transitions,
  team/player registration, registration cutoffs, phases, groups, and
  cross-entity business rules.
name: tournament-domain-rules
---

# Tournament Domain Rules

Use for tournament lifecycle, enrollment, roster, phase, and group
behavior.

## Core concepts

-   A `Team` or `Player` is a permanent catalogue record.
-   `TournamentTeam` registers a team in one tournament.
-   `TournamentPlayer` assigns a player to a registered team in one
    tournament.
-   `TournamentStage` represents a phase.
-   `TournamentGroup` represents a group inside a phase.
-   `Round` represents a matchday/round.
-   `MatchGame` represents a real match or a specifically modeled bye.

## Invariants

1.  A team can be registered only once per tournament.
2.  A player may be registered to only one team within the same
    tournament at a time.
3.  The assigned team must belong to the same tournament as the player
    registration.
4.  Player registrations are enabled by default, with a default cutoff
    at round 4.
5.  Registration must be checked on the server for every relevant
    operation.
6.  Administrative overrides must require authorization and be
    auditable.
7.  Do not treat a generated future round as the current round. Use
    actual tournament state and match status according to the project's
    existing rule.
8.  Do not change the tournament's format after dependent rounds/results
    exist unless a safe explicit workflow supports it.
9.  Do not physically delete participation records with meaningful
    history unless the existing policy expressly allows it.

## Tournament formats

-   `LEAGUE`: one regular group, ten regular rounds by default, top
    eight qualify for quarter-finals, semi-finals, and final.
-   `CUP`: direct elimination; winners advance, tied matches require a
    configured resolution.
-   `FLASH`: one or more groups; separate group schedules/standings;
    qualification rules create an eight-team bracket by default.

Use transactions for operations that modify a tournament and multiple
related registrations. Add tests for duplicate enrollment,
cross-tournament mismatches, registration lock behavior, and invalid
state transitions.
