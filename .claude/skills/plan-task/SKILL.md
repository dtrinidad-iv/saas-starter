---
name: plan-task
description: Write a task plan in docs/plans/NN-task-name.md with Status Proposed, add it to the index, and stop for approval. Use before building anything that touches more than 3 files, or whenever the user asks for a plan.
argument-hint: "[task description]"
---

# Plan a task

Task: $ARGUMENTS

## Steps
1. **Read first.** Read CLAUDE.md, `docs/plans/README.md`, and the most recent plans that relate to this task. Look at the real code you'll build on. Don't plan against code you haven't opened.
2. **Ask only about real forks**, where the user's answer changes the plan (for example email invitations vs. links). For anything with an obvious default, choose it and say so in the plan.
3. **Number and name.** The next `NN` is the highest in `docs/plans/` + 1, zero-padded. Name the file after the task, in kebab-case, e.g. `07-project-tasks.md`.
4. **Write the plan** using the template below. Keep it specific: name the actual files, routes, rules and tests.
5. **Add a row** to the table in `docs/plans/README.md`: `| NN | [Title](NN-task-name.md) | Proposed |`.
6. **Stop.** Summarize the plan for the user in a few lines and ask for approval. Build nothing until they say go. When they approve, change Status to **WIP** in both the plan and the README.

## Template
```markdown
# NN — Title

**Status:** Proposed

## Goal
What this adds and why, in 2-3 sentences. What is explicitly out of scope.

## Decisions
Choices made, with one line on why. Note any new dependency and why it's needed (it needs the user's OK).

## Backend (`api/`)
Migration, model (tenant-owned → `BelongsToOrganization`), policy and its permission table, actions, form requests, resource, routes (inside the `organization` middleware group?), events and jobs.

## Frontend (`web/`)
Types, pages, components, store changes, header or navigation.

## Tests
CRUD and validation, the permission table, and an **org A cannot read or write org B** test for every tenant-owned model.

## Verification
`verify` skill (Pint, Pest, lint, typecheck, build), plus `e2e-smoke` for the flow, plus reviewer subagents on the diff.

## Commits after approval
`feat(api): ...`, `feat(web): ...`, then this plan → Done.
```

## Rules
- Never put an organization id from the request in the design as proof of access.
- Never edit a migration that has been committed; plan a new one.
- New keys go in `.env.example` with a comment. Never edit `.env`.
