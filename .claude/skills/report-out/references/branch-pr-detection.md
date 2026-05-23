# Detecting Branches That Need a PR

For each repo with today's commits, determine whether the active branch already has an open PR. If not, surface it as a suggestion in Step 5.5.

## Detection logic

```bash
# For a single repo:
BRANCH=$(git -C "$repo" branch --show-current)
[ -z "$BRANCH" ] && return  # detached HEAD — skip

REMOTE_URL=$(git -C "$repo" remote get-url origin 2>/dev/null)
[ -z "$REMOTE_URL" ] && return  # no remote — skip
OWNER_REPO=$(echo "$REMOTE_URL" | sed -E 's|.*github\.com[:/]([^/]+/[^/.]+)(\.git)?$|\1|')

# Skip default/integration branches
case "$BRANCH" in
  main|master|development|beta|staging|production) return ;;
esac

# Has the branch been pushed?
PUSHED=$(git -C "$repo" rev-parse --verify --quiet "origin/$BRANCH")

# Open PR with this branch as head?
PR_COUNT=$(gh pr list --repo "$OWNER_REPO" --head "$BRANCH" --state open --json number 2>/dev/null \
  | jq length)
```

## Flagging criteria

A branch is added to `{BRANCHES_WITHOUT_PR}` when ALL of:

- Has at least one commit by `{GIT_AUTHOR}` on `{TARGET_DATE}`
- Branch name does NOT match the default-branch list above
- `gh pr list --head $BRANCH --state open` returns 0 results

## Don't suggest re-creation if a PR was just merged today

Before flagging, check whether a PR for this branch was merged today (the user may have just landed it, and the branch is hanging around in the local checkout):

```bash
RECENT_MERGED=$(gh pr list --repo "$OWNER_REPO" --head "$BRANCH" --state merged \
  --search "merged:>=$TARGET_DATE" --json number,mergedAt --limit 1)

if [ "$(echo "$RECENT_MERGED" | jq length)" -gt 0 ]; then
  return  # PR was merged today — branch is post-merge cleanup, no new PR needed
fi
```

## Computing context for the suggestion

When surfacing the suggestion to the user in Step 5.5 / 8.5, include:

| Field | How to compute |
|-------|----------------|
| Commits today on this branch | `git log --since="$SINCE" --until="$UNTIL" --author="$GIT_AUTHOR" --oneline "$BRANCH"` |
| Latest commit subject | `git log -1 --pretty='%s' "$BRANCH"` |
| Likely target branch | `feature/*`/`bugfix/*` → `development`; `hotfix/*` → `main`; otherwise `main` |
| Ahead of target | `git rev-list "$TARGET..$BRANCH" --count` |
| Behind target | `git rev-list "$BRANCH..$TARGET" --count` |
| Pushed status | `origin/$BRANCH` exists vs. not |

## Conventional Commits prefix detection

When drafting the PR title in the "quick PR" path (Step 8.5), check whether the latest commit subjects already follow Conventional Commits:

```bash
LATEST=$(git -C "$repo" log -1 --pretty='%s' "$BRANCH")
case "$LATEST" in
  feat:*|feat\(*\):*|fix:*|fix\(*\):*|docs:*|chore:*|refactor:*|test:*|perf:*|ci:*|style:*)
    PR_TITLE="$LATEST"  # already conventional, use as-is
    ;;
  *)
    # Infer from branch name prefix
    case "$BRANCH" in
      feature/*) PR_TITLE="feat: $LATEST" ;;
      bugfix/*|fix/*) PR_TITLE="fix: $LATEST" ;;
      hotfix/*) PR_TITLE="fix: $LATEST" ;;
      docs/*) PR_TITLE="docs: $LATEST" ;;
      *) PR_TITLE="chore: $LATEST" ;;
    esac
    ;;
esac
```

For the body, list commits since the target branch divergence:

```bash
git -C "$repo" log "$TARGET..$BRANCH" --pretty='- %s' --no-merges
```

## When to delegate to `/create-pr` instead of using gh api directly

The skill offers two paths:

1. **Recommended**: tell the user to run `/create-pr` next. That skill handles CI detection, branch protection, lock-file checks, push authorization, target validation, etc.
2. **Quick path**: bare `gh api` POST. Use only when the user explicitly opts in. Skip CI checks, target validation, etc. Faster but less safe.

Default to the recommended path. Only use the quick path when the user has explicitly said "yes, quick PR via API" — don't make this the default offering.

## Anti-patterns

| Anti-pattern | Why wrong |
|--------------|-----------|
| Suggesting a PR for a default branch | `main`/`master`/`development` shouldn't have PRs targeted at themselves |
| Pushing the branch silently before asking | Violates the project's git-push policy; needs explicit authorization phrase |
| Reusing a closed PR's title verbatim | The branch may have new commits since the close — draft fresh from current commit log |
| Suggesting a PR for a branch with 0 unpushed AND 0 ahead-of-remote commits | Nothing new to PR; the user is just sitting on the branch |
