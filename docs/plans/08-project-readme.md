# 08 — Project README and docs rule

**Status:** WIP (approved in chat: "do the README task with it")

## Goal
Add a root `README.md` so that someone who clones the repo can set it up and run it. Write down when docs get updated, so they stay current. This is docs only, with no app code changes.

## Changes
- **`README.md` (new):**
  - what the project is;
  - stack and requirements (PHP 8.4, Node 22.22.3+, PostgreSQL);
  - setup (API, database, web);
  - how to run it (API, queue worker for invitation emails, web);
  - tests;
  - the features so far;
  - project layout;
  - links to `docs/plans/` and `CLAUDE.md`.
- **`api/README.md` and `web/README.md`:** replace the Laravel/Nuxt boilerplate with short pointers to the root README.
- **`CLAUDE.md`:** a workflow rule saying docs change in the same commit as the code they describe.
- **`.claude/skills/ship-plan/SKILL.md`:** a "Docs check" step before committing (`.env.example`, CLAUDE.md, README, follow-ups).

## Verification
Check every command in the README against the repo: `.env.example` keys, `phpunit.xml`, `package.json` scripts, `nuxt.config.ts` and routes. Nothing to build.

## Commits after approval
`docs: add project README and keep docs updated with code`, then this plan → Done.
