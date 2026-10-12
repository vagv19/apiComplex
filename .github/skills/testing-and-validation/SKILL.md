---
description: Add or run PHP unit/integration tests, validate endpoint
  behavior, diagnose test failures, and verify tournament edge cases
  before declaring work complete.
name: testing-and-validation
---

# Testing and Validation

1.  Inspect the test setup and follow existing conventions. If PHPUnit
    is already configured, use it. Do not introduce a new test framework
    without approval.
2.  Separate pure domain tests from database/HTTP integration tests.
3.  For changed PHP files, run `php -l path/to/file.php`.
4.  Run focused tests first, then the full suite if practical.
5.  For API endpoints, test success, malformed JSON, missing fields,
    invalid IDs, unauthenticated access, forbidden access, not-found
    resources, and domain conflicts.
6.  For persistence, test transactions, foreign keys, unique
    constraints, and rollback after failure.
7.  Do not claim tests passed unless the commands were executed and the
    results observed.
8.  If MySQL or dependencies are unavailable, still run static syntax
    checks and pure unit tests; report what remains unverified.

Required tournament edge cases include: - Odd and even team counts. - A
team appearing at most once per round. - Balanced byes. - No cross-group
fixtures during group stage. - No duplicate schedule on repeated
requests. - Player registration cutoff and manual enable/disable. -
Three/one/zero scoring. - Goal difference and goals-for sorting. -
Multiple teams tied on points. - Six group winners plus two best
runners-up. - A corrected result affecting already-generated
qualification. - Knockout draws without a resolved winner.
