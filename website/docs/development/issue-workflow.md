# Issue Workflow

OpenRegister uses a markdown-based issue workflow that allows developers and AI assistants to create GitHub Issues by adding markdown files to the repository.

## Overview

The `issues/` folder in the repository contains markdown files that represent tasks, bugs, and feature requests. When these files are pushed to the repository, a GitHub Action automatically creates corresponding GitHub Issues.

## Benefits

- **Version Controlled**: Issues are tracked in git history
- **Offline Friendly**: Create issues without internet access
- **Review Process**: Issues can be reviewed in PRs before creation
- **AI-Friendly**: Claude and other AI tools can easily create follow-up tasks
- **Batch Creation**: Multiple issues can be created in a single commit

## Creating Issues

### Method 1: Frontmatter Format (Auto-Created)

Create a markdown file with YAML frontmatter for automatic issue creation:

```markdown
---
title: "Add user authentication"
labels: ["enhancement", "security"]
assignees: ["username"]
milestone: "v1.0"
---

## Description

Description of the feature or bug.

## Acceptance Criteria

- [ ] Criterion 1
- [ ] Criterion 2

## Technical Details

Implementation notes here.
```

When this file is pushed to `main`, `master`, or `development`, the GitHub Action will:
1. Parse the frontmatter
2. Create a GitHub Issue with the title, labels, and assignees
3. Delete the markdown file from the repository

### Method 2: Numbered Format (Manual Tracking)

For issues that need to be tracked locally before conversion:

```
issues/006-feature-name.md
```

These files use a more detailed template with status tracking:

```markdown
# Issue Title

**Status:** 📋 Open
**Priority:** 🟡 Medium
**Effort:** ⏱️ 4-6h

## Problem Statement

What needs to be solved?

## Proposed Solution

How can we fix it?

## Implementation Plan

1. Step 1
2. Step 2
```

## File Naming Conventions

| Pattern | Purpose |
|---------|---------|
| `feature-*.md` | New features |
| `bug-*.md` | Bug fixes |
| `enhancement-*.md` | Improvements |
| `docs-*.md` | Documentation |
| `NNN-*.md` | Numbered issues (manual tracking) |

## Workflow Integration

### With Claude/AI Assistants

When working with AI assistants like Claude, they can create follow-up tasks by:

1. Identifying work that needs to be done later
2. Creating a markdown file in the `issues/` folder
3. Including all necessary context and acceptance criteria

Example prompt:
> "Create an issue for implementing the UI for the new security endpoints"

The AI will create a file like `issues/feature-security-ui.md` with all the details.

### In Pull Requests

Issues can be created as part of a PR:

1. Add issue markdown files to your branch
2. Include them in your PR for review
3. When merged, issues are automatically created

## GitHub Action

The workflow file is located at `.github/workflows/issues-from-markdown.yml`.

### Triggers

The action runs when:
- Files are added to `issues/*.md`
- Push is to `main`, `master`, or `development` branches
- Files are not `README.md` or `.template.md`

### Process

1. **Detect Changes**: Find new markdown files in `issues/`
2. **Parse Frontmatter**: Extract title, labels, assignees
3. **Check Duplicates**: Skip if issue with same title exists
4. **Create Issue**: Call GitHub API to create issue
5. **Cleanup**: Delete processed markdown files

### Permissions Required

The workflow needs:
- `issues: write` - To create issues
- `contents: write` - To delete processed files

## Labels

Common labels used:

| Label | Description |
|-------|-------------|
| `bug` | Something isn't working |
| `enhancement` | New feature or request |
| `documentation` | Documentation improvements |
| `frontend` | Frontend/UI related |
| `backend` | Backend/API related |
| `security` | Security related |
| `good first issue` | Good for newcomers |

## Best Practices

1. **Be Specific**: Include clear acceptance criteria
2. **Add Context**: Reference related files and documentation
3. **Use Labels**: Help with issue triage and filtering
4. **Link Related Work**: Reference PRs, issues, or docs
5. **Include Technical Details**: Help implementers understand the scope

## Template File

A template is available at `issues/.template.md`:

```markdown
---
title: "Issue Title Here"
labels: ["enhancement"]
assignees: []
milestone: ""
---

## Description

A clear description of the feature, bug, or task.

## Acceptance Criteria

- [ ] Criterion 1
- [ ] Criterion 2
- [ ] Criterion 3

## Technical Details

Any technical notes, implementation hints, or related files.

## Related

- Related issues or PRs
- Links to documentation
```

## Troubleshooting

### Issue Not Created

- Check that frontmatter is valid YAML
- Ensure file is in `issues/` folder
- Verify branch is `main`, `master`, or `development`
- Check GitHub Actions logs for errors

### Duplicate Issues

The workflow skips files if an issue with the same title already exists. Check existing issues if your file wasn't processed.

### Labels Not Applied

Labels must exist in the repository before they can be applied. Create missing labels in GitHub first.
