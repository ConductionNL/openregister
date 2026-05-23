# Git Repository Discovery

Locate all relevant git repositories on the user's machine using **dynamic paths** — never hardcode `/home/<name>`.

## Discovery command

```bash
HOME_DIR="${HOME:-$(getent passwd "$(id -un)" | cut -d: -f6)}"

find "$HOME_DIR" -maxdepth 6 -name ".git" -type d 2>/dev/null \
  | grep -v '/.local/share/' \
  | grep -v '/.cache/' \
  | grep -v '/node_modules/' \
  | grep -v '/.nvm/' \
  | grep -v '/vendor/' \
  | sort \
  | sed 's|/.git$||'
```

## Why these specific filters

| Filter | Reason |
|--------|--------|
| `-maxdepth 6` | Required to reach deeply nested repos (e.g. `nextcloud-docker-dev/workspace/server/apps-extra/<plugin>` lives at depth 5–6). Lower depth misses these. |
| `grep -v '/.local/share/'` | Skips per-user app data (VS Code, Claude Code internals) which contain unrelated `.git` checkouts. |
| `grep -v '/.cache/'` | Skips package manager and tool caches. |
| `grep -v '/node_modules/'` | Skips vendored npm packages. |
| `grep -v '/.nvm/'` | Skips Node Version Manager installations. |
| `grep -v '/vendor/'` | Skips PHP `composer` vendored packages. |

## Post-filter: apply user's exclude list

After the `find` produces the raw list, apply the user-provided exclude list. Implement in shell, NOT in `grep` (which would over-match nested paths):

```bash
# {EXCLUDED_REPOS} is a bash array passed in by the orchestrator.
# The Hydra variant does NOT self-exclude — Hydra is the user's primary work
# repo, so it must always be scanned. If exclusion of the current repo is
# desired (e.g. wordpress-docker variant), put it in {EXCLUDED_REPOS} explicitly.

while IFS= read -r repo; do
  base=$(basename "$repo")
  skip=0

  for ex in "${EXCLUDED_REPOS[@]}"; do
    # nextcloud-docker-dev: match ROOT only, not children
    if [ "$ex" = "nextcloud-docker-dev" ]; then
      if [ "$repo" = "$HOME_DIR/nextcloud-docker-dev" ]; then
        skip=1
      fi
      continue
    fi
    # Other excludes: match by basename
    [ "$base" = "$ex" ] && skip=1
  done

  [ "$skip" -eq 0 ] && echo "$repo"
done < <(find "$HOME_DIR" -maxdepth 6 -name ".git" -type d 2>/dev/null \
            | grep -v '/.local/share/' | grep -v '/.cache/' | grep -v '/node_modules/' \
            | grep -v '/.nvm/' | grep -v '/vendor/' \
            | sort | sed 's|/.git$||')
```

## Critical rule: nested-repo handling

When a parent path is itself a git repo AND contains nested git repos (e.g. `nextcloud-docker-dev` with `workspace/server/apps-extra/<plugin>` children), excluding the parent must NOT exclude the children. Match the parent path **exactly** (`[ "$repo" = "$HOME_DIR/$EXCLUDE" ]`), not by basename or substring.

A naive `grep -v nextcloud-docker-dev` skips everything under that path — that is wrong.

## Author filter

Always derive the author dynamically:

```bash
GIT_AUTHOR=$(git config --global user.name 2>/dev/null || git config user.name)
GIT_EMAIL=$(git config --global user.email 2>/dev/null || git config user.email)

git -C "$repo" log --since="..." --author="$GIT_AUTHOR" ...
```

If `--author="$GIT_AUTHOR"` returns 0 commits in a repo where commits exist today (i.e. `git log --since=... --pretty=oneline` returns rows but the filtered version doesn't), fall back to `--author="$GIT_EMAIL"`. Some repos record commits by email-only or by a different recorded name. Never broaden the filter to "any author".

## Timezone-correct date filter

The system timezone offset (`+0200` in CEST, `+0100` in CET) is needed so commits made just after midnight aren't misclassified.

```bash
TZ_OFFSET=$(date +%z)                     # auto-detects DST
SINCE="${TARGET_DATE}T00:00:00${TZ_OFFSET}"
UNTIL="${TARGET_DATE}T23:59:59${TZ_OFFSET}"
git -C "$repo" log --since="$SINCE" --until="$UNTIL" ...
```

Without `$TZ_OFFSET`, `git log` interprets the date in the local timezone of the executing process — usually correct, but breaks when the process runs in UTC (e.g. inside some Docker setups).
