---
description: Implement or modify a PHP-native REST API endpoint
  end-to-end, including route, request validation, authorization,
  controller, service, PDO persistence, response contract, tests, and
  documentation.
name: create-api-endpoint
---

# Create API Endpoint

Use this skill whenever asked to create, update, or repair an endpoint.

1.  Read `AGENTS.md` and inspect the existing
    route/controller/service/repository patterns.
2.  Identify the endpoint contract: HTTP method, path, authentication,
    path/query parameters, JSON body, success response, error responses,
    and side effects.
3.  Trace existing data models and schema before adding columns or
    tables. Do not invent parallel models for existing concepts.
4.  Implement the route and controller. The controller should parse the
    request, call validation and authorization, invoke the service, and
    return a response. Keep business logic out of the controller.
5.  Implement or reuse a service for business rules. Use a repository
    for PDO queries if the project follows that pattern.
6.  Validate JSON syntax, required fields, types, lengths, allowed
    values, IDs, and cross-entity consistency.
7.  Use prepared statements and transactions for multi-record changes.
8.  Return consistent JSON and correct HTTP status codes. Distinguish
    validation errors (422), missing resources (404), forbidden actions
    (403), and business conflicts (409).
9.  Add tests for success, invalid input, missing resources,
    authorization, and relevant domain edge cases.
10. Update endpoint documentation and run `php -l` plus relevant tests.

Do not consider the endpoint complete if it only returns mock data, has
an empty controller, bypasses domain validation, or has no persistence
where persistence is required.

When reporting completion, include the route, request example, response
shape, files changed, tests executed, and any unverified behavior.
