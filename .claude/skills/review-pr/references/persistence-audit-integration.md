# Persistence Audit Integration Protocol

This file contains the full protocol for Step 4b of the `review-pr` skill — offering and
integrating a `/persistence-audit` run into a security-sensitive PR review.

---

## Batch mode note

For batch mode: skip unless at least one PR has `{IS_SECURITY_SENSITIVE}=true`.
If multiple security-sensitive PRs exist, ask once listing all of them.
Run and attach findings per PR as separate inline comment batches.

---

## Offer question

Ask with AskUserQuestion using the matched `{SECURITY_SIGNALS}` in the question text:

**"This PR touches [auth/RBAC/CI-CD/…] — do you also want me to run `/persistence-audit` on
it? That skill checks for persistence vectors (OAuth grants, service accounts, CI/CD secrets,
IdP trust, audit trails) that an attacker could exploit even after credential rotation.

What would you like to do with the results if I run it?"**

Offer these options:

- **Yes — post audit findings as inline PR comments** *(recommended)*: translate audit findings
  to 🔴/🟡/🟢 inline comments using the severity mapping below, and include them in the same
  review payload as the code-review findings.
- **Yes — post the full audit report as a PR comment**: post the raw markdown audit report as a
  single top-level PR comment (not inline), separate from the code-review inline comments.
- **Yes — show me the results first**: run the audit, present findings in chat, then ask again
  what to post.
- **No — skip**: proceed without the audit.

Store the choice as `{PERSISTENCE_AUDIT_ACTION}` (inline | top-level | show-first | skip).

---

## If running the audit

Invoke `/persistence-audit {PR_URL}` (passing the PR URL so the skill focuses on the diff).
Store the full audit report as `{PERSISTENCE_AUDIT_REPORT}`.

---

## Severity mapping (COVERED/PARTIAL/MISSING → 🔴/🟡/🟢)

| Audit status | Severity | When |
|--------------|----------|------|
| MISSING | 🔴 Blocker | Vector is critical (auth, token, RBAC, CI/CD secret injection) |
| MISSING | 🟡 Concern | Vector is lower-risk (audit trail gaps, non-critical service accounts) |
| PARTIAL | 🟡 Concern | Always |
| COVERED | — | No comment — skip |
| N/A | — | No comment — skip |

Post these findings using the same body format from
[comment-format.md](comment-format.md), on the **nearest changed line in the relevant
file**. If no diff line applies (e.g., a missing config that isn't in the diff), post as a
top-level PR comment instead.

---

## If `{PERSISTENCE_AUDIT_ACTION}` is `top-level`

Post the full `{PERSISTENCE_AUDIT_REPORT}` markdown as a PR-level comment:

```bash
gh api repos/{OWNER}/{REPO}/issues/{PR_NUMBER}/comments \
  -X POST \
  -f body="{PERSISTENCE_AUDIT_REPORT}"
```
