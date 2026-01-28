# Claude Instructions for OpenRegister

## Project Overview

OpenRegister is a Nextcloud app for managing registers, schemas, and objects with AI capabilities.

## Follow-up Tasks and Issues

When working on tasks that require follow-up work, create markdown files in the `issues/` folder. These files will automatically be converted to GitHub Issues when code is pushed.

### Creating Issue Files

1. Create a new markdown file in `issues/` with a descriptive name:
   - `feature-*.md` for new features
   - `bug-*.md` for bug fixes
   - `enhancement-*.md` for improvements
   - `docs-*.md` for documentation

2. Use the template format:

```markdown
---
title: "Issue Title"
labels: ["enhancement", "frontend"]
assignees: []
milestone: ""
---

## Description

Clear description of the task.

## Acceptance Criteria

- [ ] Criterion 1
- [ ] Criterion 2

## Technical Details

Implementation notes and related files.
```

### When to Create Issues

Create issue files when:
- A task is identified but cannot be completed in the current session
- New features are needed to support current work
- Bugs are discovered that are out of scope
- Documentation needs to be written
- UI needs to be created for new backend functionality

## Code Style

- Follow PSR-12 for PHP code
- Use TypeScript for frontend code
- Run `composer phpcs:fix` before committing PHP changes

## Testing

- Run `./run-tests.sh` for PHP tests
- Backend is at `http://localhost:8080`
- UI dev server is at `http://localhost:3000`

## Docker Commands

```bash
# Check containers
docker ps

# Execute commands in Nextcloud
docker exec nextcloud php occ [command]

# Clear APCu cache (useful for rate limit issues)
docker exec nextcloud apachectl -k graceful
```
