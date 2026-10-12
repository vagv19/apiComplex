---
description: Design or modify MySQL schema, PDO queries, repositories,
  database constraints, transactions, indexes, and persistence in this
  native PHP project.
name: php-pdo-database
---

# PHP PDO and Database Work

1.  Read `AGENTS.md` and inspect `database/schema.sql`, related SQL, and
    current repository conventions.
2.  Preserve the distinction between permanent catalogue records
    (`teams`, `players`) and tournament participation records
    (`tournament_teams`, `tournament_players`).
3.  Prefer database constraints for durable invariants: foreign keys,
    unique keys, check constraints where supported, and indexes for
    query patterns.
4.  Use PDO prepared statements for every external value. Never
    concatenate raw user input into SQL.
5.  For dynamic sorting or identifiers, use a fixed allowlist;
    placeholders cannot bind SQL identifiers.
6.  Use explicit transactions for operations that span multiple
    inserts/updates, including enrollment, schedule creation, result
    correction, and bracket propagation.
7.  Roll back on exceptions. Do not swallow exceptions or return raw SQL
    errors to the client.
8.  Avoid N+1 queries and repeated queries for each team when
    calculating standings.
9.  Ensure cross-table invariants are checked, such as a tournament
    player's assigned team belonging to that same tournament.
10. Update schema documentation and provide a safe migration/upgrade
    path if the database already contains data. Never casually drop or
    recreate tables.
11. Test both valid persistence and constraint violations.

Before changing schema, explain any breaking change and update all
affected SQL queries, services, tests, and API documentation.
