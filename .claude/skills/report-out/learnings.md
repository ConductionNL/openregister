# Learnings — report-out

Dated, atomic observations from executions of this skill. One insight per bullet.

The **Capture Learnings** step in `SKILL.md` appends here directly for high-confidence observations. Consolidation trigger: review and consolidate when this file exceeds ~80–100 entries — merge duplicates, remove outdated items, promote validated principles to SKILL.md guardrails.

## Patterns That Work

- 2026-05-04 — `gh search prs --author "@me" --updated ">=$DATE"` is the cleanest way to find user-authored PRs touched today; faster and more reliable than scanning the events feed.
- 2026-05-04 — The 24h comment-window check (read recent comments, compare to current draft, offer edit-vs-new) prevents tracking-issue threads from accumulating near-duplicate daily posts. Editing a comment posted 2 hours ago to add this afternoon's progress is cleaner than two near-identical posts.
- 2026-05-04 — Asking for additional context **at the start** (before any drafting) is more useful than asking only at the end. Context like "today was mostly review work, no new code" changes the entire framing of the report-out.
- 2026-05-04 — `git config user.name` + `gh api user --jq '.login'` reliably resolves the user identity dynamically — no hardcoded usernames needed.
- 2026-05-04 — Using `$HOME` for repo discovery instead of `/home/<name>` makes the skill portable across machines and users without changes.

## Mistakes to Avoid

- 2026-05-04 — Filtering `git log --author="..."` with a partial name (e.g. a first name when the recorded author is `FirstLast` or `First Middle Last`) silently drops commits. Always use the value from `git config user.name` verbatim.
- 2026-05-04 — `grep -v nextcloud-docker-dev` skips ALL nested apps-extra repos under that path — too broad. Match the parent ROOT path exactly with `[ "$repo" = "$HOME_DIR/nextcloud-docker-dev" ]` so children remain visible.
- 2026-05-04 — `gh pr edit` fails on repos that previously used Projects (classic) with "Projects (classic) is being deprecated...". Use `gh api repos/.../pulls/N -X PATCH -f body="..."` instead.
- 2026-05-04 — Top-level summary bullets that re-state detail-section bullets in the same comment created visible duplication. The user explicitly asked to remove duplicates. Pick one or the other for each fact.
- 2026-05-04 — Including a PR in a tracking-issue comment that the user did NOT author or touch (e.g. a teammate's PR in the same repo) is wrong. Filter strictly by author = `{GH_LOGIN}`.

## Domain Knowledge

- 2026-05-04 — The user's daily Dutch report-out follows: `🗓️ Report out — {dag} {datum}` heading, 2–4 summary bullets, a "PR's:" section with status emojis, an optional "Issues:" section.
- 2026-05-04 — Status emoji conventions: ✅ merged, 🟡 open/needs-review, 🔵 open/wacht-op-re-review, 🔴 blocked, 📝 draft, 🆕 created today.
- 2026-05-04 — Tracking issues are typically in an org-level `.github` repo. Successor issues replace them when the work area closes — the closing-comment trailer references the follow-up issue.
- 2026-05-04 — `gh search` results lag the timeline by a few minutes; PRs opened in the last ~3 minutes may not appear yet. The user-events feed is more current but limited to ~90 days.
- 2026-05-04 — When a tracking issue's last comment includes the closing-trailer phrase _"Verdere of toekomstige werkzaamheden worden bijgehouden in #N"_, the issue is no longer the active tracker — switch to the linked successor for new daily updates instead of posting on the closed-out tracker.
- 2026-05-04 — `gh pr create` truncates long titles at ~70 chars by inserting a Unicode ellipsis (`…`) and pushing the remainder into the body (which then starts with `…rest`). Always check the resulting title/body and offer to PATCH them clean. Title 76 chars was acceptable to PATCH back to.
- 2026-05-04 — The same author can use different status-comment header conventions across tracking issues (`## Status update — DATE` vs `### DD-MM-YYYY`). Always read at least one prior comment on the target issue before drafting; do not assume a single house style.

## Open Questions

- 2026-05-04 — Should the skill auto-write a saved tracking-issue mapping at `$HOME/.claude/report-out/tracking-issues.json` after the user manually selects issues, or always require explicit confirmation? Decide after first 3 real runs.
- 2026-05-04 — When the user has multiple recent comments (within 24h) on the same issue, should we always offer the most recent one to edit, or list them all? Most recent seems right but unverified.
- 2026-05-04 — Should the final report-out also reference the tracking-issue COMMENT URLs (deep links) or just the issue URLs? Deep links are better but uglier in Slack.

## Consolidated Principles

Validated after 3+ confirmations or after resolving a measured eval failure. These are candidates for promotion to SKILL.md guardrails.

_(none yet — this is a freshly-created skill; principles will accumulate after real runs.)_
