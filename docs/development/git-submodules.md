---
title: Git Submodules Development Guide
sidebar_position: 1
---

# Development Guide for Multi-Repository Setup

This guide explains how to work with the multi-repository structure for Conduction Nextcloud apps.

## Quick Start

1. **First time setup:**
   ```bash
   git clone <this-repository>
   cd apps-extra
   ./setup-submodules.sh
   ```

2. **Update all apps to latest:**
   ```bash
   git submodule update --remote --recursive
   git add .
   git commit -m "Update all submodules to latest"
   ```

## Working with Individual Apps

### Making changes to an app:

1. Navigate to the app directory:
   ```bash
   cd openregister
   ```

2. Make your changes and commit normally:
   ```bash
   git add .
   git commit -m "Your changes"
   git push origin main
   ```

3. Update the main repository to reference the new commit:
   ```bash
   cd ..
   git add openregister
   git commit -m "Update openregister to latest version"
   git push
   ```

### Switching to a different branch in a submodule:

```bash
cd openregister
git checkout feature-branch
cd ..
git add openregister
git commit -m "Switch openregister to feature-branch"
```

## Repository Structure

```
apps-extra/                    # Main repository
├── .gitignore                 # Ignores non-Conduction apps
├── .gitmodules               # Submodule configuration (auto-generated)
├── README.md                 # Documentation
├── DEVELOPMENT.md            # This file
├── setup-submodules.sh       # Setup script
├── docudesk/                 # Submodule -> ConductionNL/DocuDesk
├── larpingapp/               # Submodule -> ConductionNL/LarpingNextApp
├── opencatalogi/             # Submodule -> ConductionNL/opencatalogi
├── openconnector/            # Submodule -> ConductionNL/OpenConnector
├── openregister/             # Submodule -> ConductionNL/openregister
├── softwarecatalog/          # Submodule -> ConductionNL/softwarecatalog
├── zaakafhandelapp/          # Submodule -> ConductionNL/ZaakAfhandelApp
└── [ignored apps]/           # Non-Conduction apps (gitignored)
```

## Useful Commands

### Submodule Management

```bash
# Clone repository with all submodules
git clone --recursive <repository-url>

# Update all submodules to latest
git submodule update --remote --recursive

# Initialize submodules after cloning without --recursive
git submodule update --init --recursive

# Check status of all submodules
git submodule status

# Run command in all submodules
git submodule foreach 'git status'
```

### Development Workflow

```bash
# Pull latest changes from main repo and all submodules
git pull --recurse-submodules

# Push changes including submodule updates
git push --recurse-submodules=on-demand
```

## Troubleshooting

### Submodule is in detached HEAD state:
```bash
cd <submodule>
git checkout main
git pull origin main
cd ..
git add <submodule>
git commit -m "Update <submodule> to latest main"
```

### Reset a submodule to match the main repository:
```bash
git submodule update --init <submodule>
```

### Remove a submodule:
```bash
git submodule deinit <submodule>
git rm <submodule>
rm -rf .git/modules/<submodule>
```

## ⚠️ Docker Development Environment Warnings

### DO NOT Use `occ upgrade` in Development

**Critical**: Running `php occ upgrade` in the Docker development environment can break Nextcloud.

**Why**: Docker volumes and custom app paths (custom_apps, apps-extra) don't always sync properly during upgrade, causing app file inconsistencies.

**What to do instead**:
```bash
# If you need to update Nextcloud:
docker-compose down
docker-compose pull
docker-compose up -d

# If something breaks:
docker-compose down
docker-compose up -d --build
```

**Safe commands**:
- ✅ `php occ app:enable <app>`
- ✅ `php occ app:disable <app>`
- ✅ `php occ background-job:execute`
- ✅ `php occ maintenance:repair`
- ❌ `php occ upgrade` (DO NOT USE)

See `openregister/DEVELOPMENT_NOTES.md` for more details.

---

## Best Practices

1. **Always commit submodule changes first**, then update the main repository
2. **Use descriptive commit messages** when updating submodule references
3. **Keep submodules on main branch** unless specifically working on a feature
4. **Regularly update submodules** to stay current with latest changes
5. **Test the entire system** after updating multiple submodules
6. **Never use `occ upgrade`** in Docker development environment (see warning above)

## CI/CD Considerations

When setting up CI/CD pipelines, remember to:
- Use `--recursive` flag when cloning
- Update submodules before running tests
- Consider separate pipelines for individual apps vs. integration tests

