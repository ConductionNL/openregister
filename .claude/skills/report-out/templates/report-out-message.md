# Report-Out Message Template (Dutch, Slack)

The end-of-skill output. Short, scannable, Dutch, with status emojis. The user pastes this into Slack.

## Final message skeleton

```
Report out:
- {2–4 high-level summary bullets describing the day's themes — NOT commit titles}

PR's:
- https://github.com/{owner}/{repo}/pull/{N}: {title} {status-emoji}
- ...

Issues:
- https://github.com/{owner}/{repo}/issues/{N}: {title}  (alleen als relevant)
- ...

{optional FINAL_NOTE from Step 9}
```

**Heading rules:**
- Heading is exactly `Report out:` — plain text, trailing colon. No emoji, no bold, no decorations. Slack is **not** Markdown; bold/emphasis markers like `*...*` and `**...**` should not be added by the skill — the user will style the text manually after pasting if they want.
- **No newline** between the heading and the first bullet.
- **No day name or date** in the heading — Slack already shows the timestamp.

**Link rules:**
- Paste the bare URL — Slack auto-unfurls it into a clickable preview.
- Do NOT use Slack's `<URL|label>` link syntax — that markup does NOT render after copy-paste from chat into the Slack composer; it stays visible as raw `<...|...>` text.
- Format: `https://github.com/{owner}/{repo}/pull/{N}: {title} {status-emoji}` (PRs) or `https://github.com/{owner}/{repo}/issues/{N}: {title}` (issues).
- Bare `org/repo #N` text also does NOT auto-link in Slack — always use the full URL.

**Bullet style — keep it scannable:**
- High-level outcomes only. No detail-suffixes (no method counts, no "status update geplaatst", no PR-volume tallies).
- Detail belongs in the linked PRs/issues, not in the Slack message.

## Status emoji legend

| Emoji | Meaning | Dutch |
|-------|---------|-------|
| ✅ | Merged today | gemerged |
| 🟡 | Open, awaiting first review | open |
| 🔵 | Open, addressed comments, awaiting re-review | wacht op re-review |
| 🔴 | Open, blocked or failing CI | blocked |
| 📝 | Draft PR | draft |
| 🆕 | Newly opened today | nieuw |

## Filled example

```
Report out:
- PR review comments verwerkt; merge conflict opgelost
- {repo-b}: early-exit gate aan re-review, ADR cross-references in learnings
- {repo-c}: 3 nieuwe specs gedraft + PR's geopend

PR's:
- https://github.com/{org}/{repo-a}/pull/22: fix review comments + merge conflict 🔵
- https://github.com/{org}/{repo-b}/pull/184: review-pr early-exit gate ✅
- https://github.com/{org}/{repo-c}/pull/1364: bucket coverage report ✅
- https://github.com/{org}/{repo-c}/pull/1394: reverse-spec A 🆕🟡
- https://github.com/{org}/{repo-c}/pull/1397: reverse-spec B 🆕🟡
- https://github.com/{org}/{repo-c}/pull/1400: reverse-spec C 🆕🟡

Issues:
- https://github.com/{org}/.github/issues/20: skill library tracking — comment posted
- https://github.com/{org}/.github/issues/27: retrofit tracking — comment posted
```

## Rules for filling

- Dutch language throughout.
- Heading is exactly `Report out:` — plain text, no bold, no emoji, no decorations. Slack is **not** Markdown, so the skill must not add bold (`*...*` / `**...**`) or other emphasis markers — the user styles the text manually after pasting if they want.
- **No day name or date** in the heading — Slack adds the timestamp.
- **No newline** between the heading and the first bullet.
- **Only include PRs the GitHub user authored or merged today.** PRs by team members in the same repos do not belong here.
- All issue/PR references are bare URLs (`https://github.com/...`). Do NOT wrap them in Slack's `<URL|label>` syntax — that syntax does not render via copy-paste from chat into Slack composer.
- Combine related commits into one bullet — never one bullet per commit.
- Lead with the **outcome**, not the activity. No method counts, PR-volume tallies, or "status update geplaatst" suffixes — detail belongs in the linked PR/issue.
- Maximum 6 high-level summary bullets. If a day has more, combine ruthlessly.
- PR list at the bottom — one line per PR with the bare URL, short title, and status emoji.
- Include `🆕` (new today) alongside the status emoji for PRs created on `{TARGET_DATE}`.
- Issues section is optional — include only when issues were tracked, commented, or closed today.
- The optional `{FINAL_NOTE}` appended last (blockers, plans for tomorrow).

## Output formatting

Wrap the final message in a fenced markdown block (triple backticks) so the user can copy it cleanly. Do not surround it with prose — the message itself is the deliverable. The pasted result in Slack will render: a plain `Report out:` line, followed by the bulleted summary and the auto-unfurled PR/issue links. The user adds their own emoji or text styling in Slack after pasting.
