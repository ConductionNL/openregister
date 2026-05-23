# Strictness Modes

Four modes govern which findings to post, how to handle uncertainty, and what verdict to submit.

---

## Quick

**Intent**: Fast gate-check for hotfixes, trivial tweaks, and re-reviews with minimal new changes. Move fast; only block on clear regressions.

**Include**: 🔴 Blockers only (definite issues)  
**Exclude**: 🟡 Concerns, 🟢 Minors — skip silently  
**Uncertain findings**: Post as 🟡 Concern, not 🔴 Blocker. In doubt, approve.

**Comment body style**: One short paragraph maximum. Skip impact/fix sections for concerns.

**Verdict**:
- APPROVE unless there is at least one definite 🔴 Blocker
- Approve by default when uncertain

---

## Standard

**Intent**: Everyday review that balances thoroughness with turnaround time. Catches real problems without noise.

**Include**: 🔴 Blockers + 🟡 Concerns  
**Exclude**: 🟢 Minors — skip silently  
**Uncertain findings**: Post as 🟡 Concern, not 🔴 Blocker.

**Comment body style**: Concise — one paragraph per comment maximum. State the problem and the fix; skip extended impact analysis unless the risk is non-obvious.

**Verdict**:
- REQUEST_CHANGES if any 🔴 Blockers present
- APPROVE if only 🟡 Concerns (or nothing found)

---

## Thorough

**Intent**: Comprehensive review for large PRs or those touching many subsystems. Leave no finding behind.

**Include**: 🔴 Blockers + 🟡 Concerns + 🟢 Minors  
**Uncertain findings**: Post as 🟡 Concern, not 🔴 Blocker.

**Comment body style**: Full body — problem statement, Impact section, Suggested fix section. Provide concrete code examples or approaches where helpful.

**Verdict**:
- REQUEST_CHANGES if any 🔴 Blockers present
- APPROVE if only 🟡 Concerns or 🟢 Minors

---

## Strict

**Intent**: Security, auth, RBAC, payment, or high-stakes code. Every doubt is a potential vulnerability; err on the side of blocking.

**Include**: 🔴 Blockers + 🟡 Concerns + 🟢 Minors  
**Uncertain findings**: Escalate to 🔴 Blocker (not 🟡 Concern).

**Comment body style**: Full body — problem statement, Impact section, Suggested fix section. Name the exact risk (e.g., privilege escalation, data leak, null-coercion bypass). Provide concrete examples.

**Verdict**:
- REQUEST_CHANGES if any 🔴 Blockers OR any 🟡 Concerns
- APPROVE only when zero blockers and zero concerns

---

## Mode Selection Signals

| Signal | Recommended mode |
|--------|-----------------|
| Security, auth, RBAC, payment, or permission code touched | **Strict** |
| PR size >500 additions OR >15 files changed | **Thorough** |
| Re-review with only small new commits (< 50 additions) | **Quick** |
| Hotfix, config tweak, or < 50 additions total | **Quick** |
| Anything else | **Standard** |

When signals conflict (e.g., large diff that also touches auth), choose the stricter mode.

---

## Wording Guidance by Mode

| Mode | Blocker phrasing | Concern phrasing |
|------|-----------------|-----------------|
| Quick | "This will break X — must fix before merge." | (Not posted) |
| Standard | "This will break X — must fix before merge." | "Worth addressing: …" |
| Thorough | "This will break X — must fix before merge. **Impact:** … **Suggested fix:** …" | "Worth addressing: … **Impact:** … **Suggested fix:** …" |
| Strict | "This is a potential security risk — must fix before merge. **Impact:** … **Suggested fix:** …" | Same as Thorough; any 🟡 blocks the PR |
