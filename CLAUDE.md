# CLAUDE.md

This file is read by Claude Code on session start.

**Primary source of truth: [AGENTS.md](AGENTS.md).** Read it first, every session. CLAUDE.md only adds Claude-specific operational notes.

---

## Session working rules — READ EVERY SESSION

Non-negotiable working rules for this project. Override default Claude Code behavior.

### 1. One roadmap step at a time

Do not batch multiple steps. After each step, update the relevant docs (CHANGELOG, ROADMAP, version fields) before moving to the next.

### 2. Discuss before acting

Before starting any step, surface: proposed approach, any improvements or deviations from the roadmap, open questions, trade-offs. Get **explicit approval** before running tools that write files, install dependencies, or execute git.

Read-only tools (Read, Grep, Glob, version checks) don't need pre-approval. Writing files, installers, or `git` does.

### 3. Suggest, don't execute, git operations

Agent drafts commit messages and lists follow-up actions. The **user runs** `git add`, `git commit`, `git push`, `git tag`, `git checkout -b`, `git merge`. Never invoke them yourself.

### 4. Branch per roadmap step

Every roadmap step gets its own branch, named per [AGENTS.md §4](AGENTS.md) conventions and prefixed with the step number for traceability:

- `feat/<step>-<short-name>` — features
- `fix/<step>-<short-name>` — fixes
- `chore/<step>-<short-name>` — tooling, release infra
- `refactor/<step>-<short-name>` — refactors
- `docs/<step>-<short-name>` — docs-only

Example: `chore/1.5-husky-lint-staged`, `feat/2.1-plugin-bootstrap`.

Flow:
1. Agent suggests branch name; user creates it and confirms.
2. Agent does step work on that branch.
3. Agent drafts commit message; user commits + pushes + opens PR.
4. User merges (squash preferred) once CI is green.

No direct commits to `main` for roadmap-step work. Steps 1.1–1.4 landed on `main` before this rule existed — not retroactively rebranched.

### Why these rules exist

User wants control over pacing, git-writing, and branch hygiene. Pause-to-confirm is cheap; unwanted commits, batched steps, or force-push accidents are expensive to unwind.

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

The four original design questions are now **resolved** in [AGENTS.md §11](AGENTS.md#11-resolved-design-decisions) (soft-delete, neutral webhook payload, configurable tail, server-side regex). Do not relitigate unless you have a concrete reason.

### Keep docs in sync with code — every commit

[AGENTS.md §13](AGENTS.md#13-docs-you-must-update-with-every-change) lists the docs that must move with code. In short:

- **Every** behavioral commit → tick the box in [ROADMAP.md](ROADMAP.md) + add a bullet to [CHANGELOG.md](CHANGELOG.md) `[Unreleased]`.
- **🏷️ version-bump steps** → also bump `Version:` in [logscope.php](logscope.php), roll `[Unreleased]` to a dated heading, refresh the `Status:` line in [README.md](README.md), and tag `vX.Y.Z`.
- **Post-v1.0 releases** → also mirror the changelog entry into `readme.txt` and re-capture screenshots if the UI changed.
- **🔒 security-sensitive** commits → run the `security-review` skill before committing; use the `### Security` heading in CHANGELOG.

Never land a code change and leave CHANGELOG stale. If a change genuinely warrants no docs update (purely internal refactor), say so in the commit body — don't skip silently.
