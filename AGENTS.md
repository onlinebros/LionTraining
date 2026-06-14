# Agent Instructions

Read this file before making changes. Use the project memory-bank before editing code. Do not read, print, copy, or modify secrets such as `.env`, `.env.*`, private keys, database dumps, or production credentials unless a human explicitly approves.

## Standard Workflow

1. Read `CLAUDE.md` and `memory-bank/*.md`.
2. Create a concise implementation plan.
3. Work on a branch or worktree when possible.
4. Follow existing code style and architecture.
5. Run lint, typecheck, tests, and build before marking complete.
6. Update `memory-bank/activeContext.md` and `memory-bank/progress.md`.
7. Document unresolved risks.

## Safety Rules

AI may build and test. AI may propose deployment. AI must not silently deploy to production, expose secrets, or alter production infrastructure without approval.
