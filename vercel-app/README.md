HTCCC Vercel app
=================

This folder contains a minimal Next.js app you can deploy to Vercel as a replacement frontend/backend for the PHP app.

Quick start (local)

1. Copy environment variables from `.env.example` to `.env.local`.
2. Install dependencies and run dev server:

```bash
cd vercel-app
npm install
npm run dev
```

3. Open http://localhost:3000 and verify the database status.

Deploy to Vercel

- In the Vercel dashboard, create a new project from this repository.
- Set the project Root Directory to `vercel-app`.
- Add the following Environment Variables in the Vercel project settings:
  - `DB_HOST` — your external MySQL host
  - `DB_USERNAME` — DB user
  - `DB_PASSWORD` — DB password
  - `DB_NAME` — database name (e.g. `htccc-data-base`)
- If you don't have a remote MySQL, you can use a managed MySQL provider (PlanetScale, Amazon RDS, ClearDB, etc.).

Notes
- Vercel cannot host MySQL; your database must be reachable from Vercel (allowlist Vercel IPs or use a cloud DB).
- This scaffold is minimal — you'll need to port PHP logic (forms, auth, certificates) into API routes and pages.
