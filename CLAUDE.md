# CLAUDE.md

This file is read by Claude Code on session start.

**Primary source of truth: [AGENTS.md](AGENTS.md).** Read it first, every session. CLAUDE.md only adds Claude-specific operational notes.

---

## Claude-specific operational notes

### Skills to prefer

Invoke via the `Skill` tool when relevant:

- **`simplify`** — review changes for reuse / quality before committing.
- **`review`** — review a pull request before opening it.
- **`security-review`** — **always run before any release**, and before any PR that touches REST routes, file paths, settings, or alerts.
- **`commit-commands:commit`** — create commits (enforces Conventional Commits style from AGENTS.md §4).
- **`commit-commands:commit-push-pr`** — when the user wants the full commit → push → PR flow.
- **`planning-with-files`** — for any task expected to need more than ~5 tool calls.
- **`fewer-permission-prompts`** — if permission prompts are getting noisy.

### Subagents

- **`Explore`** — open-ended codebase research. Always prefer this over `general-purpose` when the task is "find / understand X in this repo."
- **`Plan`** — architectural design for features touching multiple layers (PHP + REST + React).
- **`general-purpose`** — reserve for research that spans beyond this repo (docs, external APIs).

### When to use plan mode

Enter plan mode for:

- Any feature that touches PHP + REST + React together.
- Any change to `PathGuard`, alert dispatch, or REST auth (security-sensitive).
- Any refactor affecting more than 3 files.
- Any task with more than ~5 expected tool calls.

### Memory

Persist in memory:

- User preferences (commit style, review bar, testing philosophy).
- Validated approaches the user has confirmed.
- Non-obvious project facts (deadlines, stakeholders, constraints).

Do **not** persist:

- Code patterns or architecture (derivable from the repo).
- File paths, naming conventions (they're in AGENTS.md).
- Ephemeral task state, in-progress work.

### Git safety (reiterated from AGENTS.md)

- Never `--no-verify`, `--no-gpg-sign`, or similar unless the user explicitly asks.
- Never `reset --hard`, `push --force`, or branch deletion without confirmation.
- Always `git add <path>` with specific paths — never `git add .` or `git add -A`.
- Create new commits; never amend a published commit.

### Ask first, don't assume

The four open questions in [AGENTS.md §11](AGENTS.md#11-open-questions-decide-when-relevant-dont-assume) are not yet resolved. If the work touches any of them, surface the question to the user before deciding.
