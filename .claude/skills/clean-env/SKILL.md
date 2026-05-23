---
name: clean-env
description: Reset the OpenRegister development environment (stop, remove volumes, restart, install apps)
---

# Clean Environment

Run the `clean-env.sh` script to fully reset the OpenRegister development environment.

This will:
1. Stop all containers from the OpenRegister docker-compose
2. Remove all containers and volumes (full data reset)
3. Start containers fresh
4. Wait for Nextcloud to become ready
5. Install core apps: openregister, opencatalogi, softwarecatalog, nldesign, mydash

**Model check — only apply when this skill is run standalone. Skip this section entirely if this skill was called from within another skill — the calling skill is responsible for model selection.**

**Check the active model** from your system context (it appears as "You are powered by the model named…").

- **On Haiku**: proceed normally — this is the right model for this task.
- **On Sonnet**: inform the user and ask using AskUserQuestion:
  > "⚠️ You're on Sonnet. This skill runs a shell script — purely mechanical, no reasoning required. Haiku is a better fit and conserves quota for heavier tasks. Switch with `/model haiku`, or proceed with Sonnet."
  Options: **Proceed with Sonnet** / **Switch to Haiku first** (stop here if switching)
- **On Opus**: stop immediately:
  > "You're on Opus. This skill runs a shell script — purely mechanical, no reasoning required. Opus is overkill here and will waste quota unnecessarily. Please switch to Haiku (`/model haiku`) and re-run."

## Instructions

Run the clean-env script:

```bash
bash .claude/scripts/clean-env.sh
```

**Important:** This is a destructive operation — it removes all database data and volumes. Only run when a full reset is intended.

After the script completes, verify the environment:
1. Check that Nextcloud is accessible at http://nextcloud.local
2. Log in with admin/admin
3. Confirm apps are listed and enabled

If any app fails to enable, try running manually:
```bash
docker exec nextcloud php occ app-enable <appname>
```

> 💡 If you switched models to run this command, don't forget to switch back to your preferred model with `/model <name>` (e.g. `/model default` or `/model sonnet`).
