<!-- Example output — opsx-pipeline for openregister + pipelinq -->

> User: /opsx-pipeline openregister pipelinq

Scanning for open proposals...

Found 3 changes:
- openregister: `add-webhook-support`
- pipelinq: `sla-tracking`
- pipelinq: `routing`

---

## Pipeline Plan

| # | App | Change | GitHub Repo | Issue |
|---|-----|--------|-------------|-------|
| 1 | openregister | add-webhook-support | ConductionNL/openregister | #142 |
| 2 | pipelinq | sla-tracking | ConductionNL/pipelinq | #79 |
| 3 | pipelinq | routing | ConductionNL/pipelinq | #81 |

Total: 3 changes across 2 repositories
Max parallel agents: 5 (browser-2 through browser-5, browser-7)

> Process these 3 changes? Each will get a feature branch, full implementation, and PR to development.
> User: Yes, start the pipeline

---

> How should models be assigned across the 3 changes?
> User: Auto-select per change

Reading proposals...
- `add-webhook-support`: new service + background job + CRUD API → **Sonnet**
- `sla-tracking`: new SLA entity + dashboard widget + alert logic → **Sonnet**
- `routing`: config-only change, no new code → **Haiku**

> Auto-assigned models — proceed or adjust?
> User: Proceed

---

> Nextcloud is running at nextcloud.local. Include browser testing in the pipeline?
> User: Yes, but only for UI changes

UI check:
- add-webhook-support: touches only PHP files → browser-skipped
- sla-tracking: touches `.vue` and `controller/*.php` → browser-2 assigned
- routing: config only → browser-skipped

---

### Step 3: Preparing worktrees...

```bash
# openregister
git worktree add /tmp/worktrees/openregister-add-webhook-support \
  -b feature/142/add-webhook-support origin/development ✓

# pipelinq sla-tracking
git worktree add /tmp/worktrees/pipelinq-sla-tracking \
  -b feature/79/sla-tracking origin/development ✓

# pipelinq routing
git worktree add /tmp/worktrees/pipelinq-routing \
  -b feature/81/routing origin/development ✓
```

GitHub issue comments added: "🚀 Pipeline started" on #142, #79, #81 ✓

---

### Step 4: Launching agents...

3 agents launched in parallel:
- Agent 1: openregister/add-webhook-support (Sonnet, no browser)
- Agent 2: pipelinq/sla-tracking (Sonnet, browser-2)
- Agent 3: pipelinq/routing (Haiku, no browser)

---

## Pipeline Progress (live)

| # | App | Change | Status | Tasks | Quality | Browser | Docs |
|---|-----|--------|--------|-------|---------|---------|------|
| 1 | openregister | add-webhook-support | ✓ Complete | 6/6 | All pass | Skipped (PHP only) | ✓ Updated |
| 2 | pipelinq | sla-tracking | ✓ Complete | 5/5 | All pass | ✓ 4 scenarios | ✓ Created |
| 3 | pipelinq | routing | ✓ Complete | 2/2 | All pass | Skipped (config only) | ✓ Updated |

---

### Step 6: Creating Pull Requests...

**PR 1:** ConductionNL/openregister #143
- Title: `feat(openregister): webhook subscription support`
- Branch: feature/142/add-webhook-support → development
- Tasks: 6/6, Quality: PASS, Tests: 14 added
- Browser: Skipped (PHP only)
- Feature docs: docs/features/webhook-subscriptions.md created

**PR 2:** ConductionNL/pipelinq #84
- Title: `feat(pipelinq): SLA tracking and deadline alerts`
- Branch: feature/79/sla-tracking → development
- Tasks: 5/5, Quality: PASS, Tests: 8 added
- Browser: ✓ 4 scenarios passed (SLA dashboard, alert triggers, settings, list view)
- Feature docs: docs/features/sla-tracking.md created

**PR 3:** ConductionNL/pipelinq #85
- Title: `feat(pipelinq): routing configuration`
- Branch: feature/81/routing → development
- Tasks: 2/2, Quality: PASS, Tests: 3 added
- Browser: Skipped (config only)
- Feature docs: docs/features/README.md updated

---

### Step 7: Cleaning up worktrees...

```bash
git worktree remove /tmp/worktrees/openregister-add-webhook-support ✓
git worktree remove /tmp/worktrees/pipelinq-sla-tracking ✓
git worktree remove /tmp/worktrees/pipelinq-routing ✓
```

---

## Pipeline Complete

### Results

| # | App | Change | Branch | PR | Tasks | Quality | Browser | Docs | Status |
|---|-----|--------|--------|-----|-------|---------|---------|------|--------|
| 1 | openregister | add-webhook-support | feature/142/add-webhook-support | #143 | 6/6 | ✓ | Skipped | ✓ | Merge-ready |
| 2 | pipelinq | sla-tracking | feature/79/sla-tracking | #84 | 5/5 | ✓ | ✓ 4 | ✓ | Merge-ready |
| 3 | pipelinq | routing | feature/81/routing | #85 | 2/2 | ✓ | Skipped | ✓ | Merge-ready |

### Summary

- Changes processed: 3
- Successful: 3
- Failed: 0
- PRs created: 3 (#143, #84, #85)
- Total tasks implemented: 13
- Total tests written: 25
- Browser scenarios tested: 4 (sla-tracking only — others skipped by ui-only policy)
- Feature docs created/updated: 3
