# Setup

Copy `AGENTS.md` and `SKILLS.md` into the repository root. Copy the
`.github/skills/` directory into the same location in your repository.

`AGENTS.md` defines always-on project conventions. `SKILLS.md` is a
human-readable index. Copilot discovers actual skills from
`.github/skills/<skill-name>/SKILL.md`; the index alone does not
activate skills.

Suggested first prompt after copying:

> Read AGENTS.md and SKILLS.md, inspect the repository, verify which
> skills are available under .github/skills, and report the current
> architecture and conventions. Do not modify files yet.

Example task prompt:

> Use the /create-api-endpoint skill to implement GET
> /api/v1/teams/{id}. Follow AGENTS.md, inspect existing patterns first,
> implement the endpoint end-to-end, add tests and update endpoint
> documentation. Do not introduce a framework or new runtime dependency.
