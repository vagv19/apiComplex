# Sport Complex La Pasión — frontend

React 19 + Vite + TypeScript + Tailwind CSS v4 administration interface.

## Project structure

- `src/main.tsx` mounts the application and imports global styles.
- `src/App.tsx` contains navigation, catalog screens, tournament workflows,
  match results, and calculated standings.
- `src/lib/api.ts` contains the typed API models, response-envelope handling,
  and HTTP client.
- `src/index.css` contains the global theme and responsive application styles.
- `vite.config.ts` configures Vite and proxies development API calls to XAMPP.

## Backend connection

The API is expected at:

`http://127.0.0.1/apiComplex/backend/public/api/v1`

During Vite development, requests use `/backend-api/api/v1` and are proxied to
the XAMPP Apache server. This avoids browser CORS issues. To use another API
host, define `VITE_API_BASE_URL` in a local Vite environment file, for example:

```dotenv
VITE_API_BASE_URL=http://127.0.0.1/apiComplex/backend/public/api/v1
```

For a production build, the frontend assets are built with base path `/v2/`
and the API uses the same-origin URL `/v2/backend/public/api/v1`.

## Run and build

Run these commands from `frontend/`:

```bash
pnpm install
pnpm dev
pnpm exec tsc --noEmit
pnpm build
```

The interface consumes category, team, player, tournament, roster, group,
round, match, result, and standings endpoints. League schedules remain on the
existing league workflow. Once all real regular-season matches have results,
the calendar enables a control to explicitly start the configured league
knockout phase; it then displays the current liguilla round and
winner-advancement controls.

The administrator interface requires an account with the `admin` role. It
stores the signed bearer token in `sessionStorage` for the active browser
session and sends it to the protected report and discipline endpoints. On the
**Resultados** page, each match can load and edit attendance, individual goals,
and disciplinary events together with the score in one atomic report request.
Goal and event rows use client-generated stable keys to avoid duplicates on
retries; match minutes are not part of the forms or API payload.

The **Motivos de expulsión** page manages reason descriptions, suspension
lengths, and active state. Used reasons are deactivated rather than deleted.
The **Calendario** page shows a disabled **Descargar cédula** action on each
match. It retains the match ID in the button context, but there is no download
endpoint or simulated file yet.
The calendar page also manages the complete
competition-specific flows:

- **Copa:** create a seeded single-elimination bracket, automatically advance
  byes, record penalty shootouts for tied knockout matches, and advance
  winners through the final.
- **Relámpago:** configure and create balanced groups, generate an independent
  round robin inside each group, review group standings, select qualifiers per
  group and a total qualifier count, then create and progress the final bracket.
  Before generating the group-stage schedule, the setup can be cancelled;
  this removes group assignments and group records without removing tournament
  team registrations.

Flash standings are read from the backend's group standings endpoint. League
standings continue to be calculated in the frontend from registered teams and
completed league matches.
