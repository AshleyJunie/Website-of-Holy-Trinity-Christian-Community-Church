# Vercel rewrite notes

This repository now includes a small Next.js frontend so the public church site can be deployed on Vercel.

## Local setup

1. Install Node.js 18 or newer.
2. Run `npm install` in the repository root.
3. Start the app with `npm run dev`.

## Deploy to Vercel

1. Push the repository to GitHub.
2. Import the repo in Vercel.
3. Let Vercel detect Next.js from `package.json`.
4. Add database and email environment variables later if you migrate dynamic features.

## What this rewrite covers

- Public homepage layout
- Responsive navigation and sections
- A deployable Vercel target

## What still needs migration

- PHP admin pages
- MySQL-backed live content
- Uploads, auth, and mail flows