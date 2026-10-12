---
description: Review or implement PHP API authentication, authorization,
  input validation, PDO security, CORS, secrets management, error
  handling, and audit safety.
name: security-review
---

# Security Review

Use whenever changing authentication, authorization, database access,
request parsing, file access, or administrative operations.

## Checklist

-   Are protected routes authenticated?
-   Is the user authorized for this action and tournament?
-   Does the server determine the user identity and role rather than
    trusting request JSON?
-   Are all SQL values bound through PDO prepared statements?
-   Are dynamic SQL identifiers selected from a server-side allowlist?
-   Are JSON parsing and content types validated?
-   Are path IDs and query parameters validated?
-   Are error responses free of stack traces, SQL, secrets, and
    filesystem paths?
-   Are passwords hashed using `password_hash()` and checked with
    `password_verify()`?
-   If tokens are used, are signatures, expiration, and relevant claims
    verified using a reviewed implementation?
-   Is CORS restricted to known origins?
-   Are state-changing operations transactional and auditable?
-   Are credentials, tokens, and sensitive data excluded from logs?
-   Are `.env`, logs, source internals, and configuration files
    inaccessible from the public web root?
-   Are destructive schedule/result operations protected against
    accidental or unauthorized use?

Do not invent cryptographic algorithms. If a secure token implementation
requires a dependency, explain the reason and request approval before
adding it under the project's no-framework constraint.

Report findings by severity, affected files/endpoints, concrete exploit
scenario, and the smallest safe remediation. Do not claim security
certification based on a checklist alone.
