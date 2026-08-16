# OpenRegister Issues

This folder contains documented issues, feature requests, and technical debt items for the OpenRegister app.

## 📁 Structure

Each issue is documented in a separate Markdown file with the naming convention:
```
XXX-short-descriptive-name.md
```

Where `XXX` is a zero-padded issue number (e.g., `001`, `002`, etc.).

## 🏷️ Issue Template

Each issue should include:

- **Status:** 📋 Open / 🔄 In Progress / ✅ Closed / ⏸️ On Hold
- **Priority:** 🔴 High / 🟡 Medium / 🟢 Low
- **Effort:** ⏱️ Estimated hours/days
- **Created:** Date
- **Target:** Goal or success criteria

Sections:
1. **Problem Statement** - What needs to be solved?
2. **Current Situation** - What's the status now?
3. **Proposed Solution(s)** - How can we fix it?
4. **Implementation Plan** - Step-by-step approach
5. **Testing Strategy** - How to verify the fix
6. **References** - Links to docs, PRs, etc.
7. **Status Updates** - Timeline of work
8. **Discussion** - Comments and findings

## 📋 Open Issues

| # | Title | Priority | Status | Effort |
|---|-------|----------|--------|--------|
| 001 | [Magic Mapper Cross-Table Search Performance Optimization](001-magic-mapper-performance-optimization.md) | 🟡 Medium | 📋 Open | ⏱️ 2-4h |
| 002 | [Magic Mapper Feature Completeness Verification](002-magic-mapper-feature-completeness-verification.md) | 🔴 High | 📋 Open | ⏱️ 4-6h |
| 003 | [Magic Mapper CSV Object Reference Import](003-magic-mapper-csv-object-reference-import.md) | 🔴 High | 📋 Open | ⏱️ 4-6h |
| 004 | [OpenCatalogi Magic Mapper Integration](004-opencatalogi-magic-mapper-integration.md) | 🟡 Medium | 📋 Open | ⏱️ 6-8h |
| 005 | [PHPMD Suppressions Technical Debt](005-phpmd-suppressions-technical-debt.md) | 🟢 Low | 📋 Open | ⏱️ 8-16h |
| - | [Security Settings UI](feature-security-settings-ui.md) | 🟡 Medium | 📋 Open | ⏱️ 4-6h |
| - | [Security Blocked List UI](feature-security-blocked-list-ui.md) | 🟡 Medium | 📋 Open | ⏱️ 4-6h |

## ✅ Closed Issues

None yet.

## 🎯 Issue Lifecycle

1. **📋 Open** - Issue identified and documented
2. **🔄 In Progress** - Actively being worked on
3. **🧪 Testing** - Implementation complete, testing in progress
4. **✅ Closed** - Resolved and verified
5. **⏸️ On Hold** - Paused for specific reason

## 🤖 Automated Issue Creation

This folder supports **automatic GitHub Issue creation** via GitHub Actions.

### How It Works

1. Create a markdown file with frontmatter (see template below)
2. Commit and push to `main`, `master`, or `development`
3. GitHub Actions automatically creates the issue
4. The markdown file is then deleted from the repository

### Frontmatter Template

```markdown
---
title: "Issue Title Here"
labels: ["enhancement", "frontend"]
assignees: []
milestone: ""
---

## Description

Your issue description here...
```

### AI/Claude Integration

When working with Claude or other AI tools, they can create follow-up tasks by adding markdown files to this folder. This enables:
- Offline issue creation
- Batch issue creation in a single commit
- Issue review before creation (via PR)
- Version-controlled issue history

## 💡 Contributing

When creating a new issue:

1. Use the next available issue number OR use the frontmatter format for auto-creation
2. Create a descriptive filename (e.g., `feature-security-ui.md` or `006-new-feature.md`)
3. Follow the template structure
4. Add entry to "Open Issues" table above (for numbered issues)
5. Link to related PRs or commits when applicable

## 🔍 Issue Categories

Issues can be tagged with categories:

- **🐛 Bug** - Something is broken
- **⚡ Performance** - Optimization opportunity
- **✨ Feature** - New functionality
- **🔧 Technical Debt** - Code quality improvement
- **📚 Documentation** - Docs need updating
- **🔒 Security** - Security concern
- **♿ Accessibility** - A11y improvement

## 📞 Contact

For questions about issues, contact the development team or create a discussion in the appropriate channel.

---

**Last Updated:** 2026-01-05

