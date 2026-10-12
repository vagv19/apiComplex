---
description: Implement standings, points, goals, tie-breakers,
  qualification, best runners-up comparisons, playoff seeding, knockout
  brackets, and champion progression.
name: standings-and-qualification
---

# Standings and Qualification

Use for standings endpoints, scoring, group comparisons, and final
phases.

## Standings calculation

Calculate each team's: - Played - Won - Drawn - Lost - GoalsFor -
GoalsAgainst - GoalDifference - Points - Position

Default scoring is win = 3, draw = 1, loss = 0, configurable per
tournament.

Only eligible completed real matches count. Exclude pending, postponed,
cancelled, unresolved, and bye records. Never count a bye as a match or
award invented goals/points.

## Tie-breakers

Default order: 1. Points. 2. Goal difference. 3. Goals scored. 4.
Head-to-head only when the configured rules define a valid comparison.
5. Explicit administrative criterion.

Use deterministic sorting. Define what happens when head-to-head leaves
a multi-team tie unresolved. Never rely on database insertion order.

## League qualification

-   Default to the top eight after the regular phase is complete.
-   Default quarter-finals: 1 vs 8, 2 vs 7, 3 vs 6, 4 vs 5.
-   Generate semi-finals and final with traceable winner-to-next-match
    references.
-   Do not create the same bracket twice.

## Flash qualification

Support configurable qualifiers per group, total qualifiers, first-place
priority, and best runners-up. Example: six group winners plus two best
runners-up.

When comparing teams across groups, use the configured criteria and do
not assume group size or strength is identical. Ensure the result
contains the expected number of unique teams before generating the
bracket.

## Corrections and downstream matches

When a group result changes after qualification or a bracket exists: -
Recalculate affected standings. - Determine whether qualified teams or
seeding changed. - Do not silently replace teams in played or completed
knockout matches. - Return a conflict or require an explicit
administrative correction workflow for downstream matches.

A champion is final only after the final has a definitive result. Add
unit tests for scoring, sorting, multi-team ties, cross-group
comparisons, duplicate qualification, and bracket advancement.
