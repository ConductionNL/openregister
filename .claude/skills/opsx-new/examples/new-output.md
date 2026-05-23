<!-- Example output — opsx-new for change: add-webhook-support -->

Creating new change: **add-webhook-support**

```bash
openspec new change "add-webhook-support"
```

Change created at `openspec/changes/add-webhook-support/`

```bash
openspec status --change "add-webhook-support"

add-webhook-support (spec-driven)
  → proposal    — ready (no dependencies)
  ○ specs       — blocked (needs proposal)
  ○ design      — blocked (needs specs)
  ○ tasks       — blocked (needs design)

0/4 artifacts complete.
```

**Schema:** spec-driven
**Artifact sequence:** proposal → specs → design → tasks

**First artifact template (proposal):**

```markdown
# Proposal: add-webhook-support

## Why
{Problem statement — what gap does this change address?}

## What Changes
{What will be built or modified at a high level}

## Capabilities
### New Capabilities
- {capability-name}: {description}

### Modified Capabilities
- {capability-name}: {description}

## Impact
- Apps affected: {list}
- Breaking changes: {yes/no, details}
- Migration needed: {yes/no, details}
```

---

**Change `add-webhook-support` is ready. How would you like to proceed?**

- **Generate all artifacts at once** (`/opsx-ff add-webhook-support`) — fastest path to implementation; I'll create proposal, specs, design, and tasks in one go
- **Create artifacts one at a time** (`/opsx-continue add-webhook-support`) — more control; you review and approve each artifact before the next
- **Think through the problem first** (`/opsx-explore add-webhook-support`) — explore ideas, constraints, and approach before committing to a direction
- **Done for now** — come back later with `/opsx-continue add-webhook-support`
