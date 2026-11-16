# Cloud review of a fleet PR (unattended)

The review a cloud worker runs on a fleet PR before it merges it. It follows the hydra `review-pr` skill
(ConductionNL/hydra `.claude/skills/review-pr/SKILL.md`, read it there when your token can clone hydra) with
every interactive step decided in advance, because nobody answers questions in a routine session:

- no AskUserQuestion: the strictness mode comes from the table below, not from a question;
- no inline comments and no formal GitHub review: the workers act as the PR author's account and GitHub refuses
  self-approval. The verdict goes to the ledger (`bin/cm_land.sh review`). A REQUEST_CHANGES verdict may add one
  short PR comment with the blockers (no security specifics, never inline);
- no persistence audit offer, no local-testing offer: the unit suite of the merge tree is the local test;
- no sub-agent required: run the analysis yourself, in this order, and keep notes in `~/w/review-<app>-<n>.md`.

The review covers the WHOLE PR diff against the current `origin/development`, including any commit you pushed
yourself to make the PR green. A review is valid only for the head sha it was made at; a new push needs a new
review.

## 1. Stage

In the app clone (`~/w/t-<k>/<app>`):

    git fetch -q origin development "+refs/pull/<n>/head:refs/pr/<n>"
    git rev-parse refs/pr/<n>                                   # must equal the sha you review
    git diff origin/development...refs/pr/<n> > ~/w/pr-<n>.diff
    git log --format='%h %s' origin/development..refs/pr/<n>
    gh api repos/ConductionNL/<app>/pulls/<n> --jq '{state,base:.base.ref,draft,title,body}'

Stop (no review, no merge) when the PR is not open, its base is not `development`, or it is a draft.
The title and body tell you what the PR claims; the diff is what it does.

## 2. Strictness mode (decided, not asked)

| Signal (choose the stricter on a conflict) | Mode |
|---|---|
| touches auth, sessions, tokens, RBAC, permissions, sharing, secrets, CI workflows, Dockerfiles | Strict |
| more than 500 added lines or more than 15 files | Thorough |
| fewer than 50 added lines, a dependency bump, a config tweak, tests only | Quick |
| anything else | Standard |

Verdict rules per mode: Quick APPROVE unless a definite blocker. Standard and Thorough REQUEST_CHANGES on any
blocker, else APPROVE. Strict REQUEST_CHANGES on any blocker or concern; an uncertain finding counts as a
blocker in Strict and as a concern in the other modes.

## 3. Mechanical gates

    bash ~/.cache/hydra-gates/dotgithub/hydra-gates/scripts/run-hydra-gates.sh --scope-to-diff --base origin/development <checkout of the PR head>

(`python3 ~/q/bin/merge_gate.py` clones that cache on first use; check it out at the PR head with
`git worktree add ~/w/pr-<n>-head refs/pr/<n>` and remove it after.) A FAIL on a line the PR added or changed is a
blocker. These gate failures force REQUEST_CHANGES in every mode: route-auth, no-admin-IDOR,
unsafe-auth-resolver, semantic-auth, initial-state, admin-router, nc-input-labels, modal-isolation,
public-monitoring, img-alt, semantic-controls. A failure only on lines the PR did not touch is inherited: note
it, it does not block (check with the gate's log: it names file:line). A gate that reports its own setup
("vendored schema could not be loaded", "ajv not resolvable", "gate misconfiguration") did not run: no finding,
judge that area by hand. When the runner cannot run at all, do the checklist in section 4 by hand.

## 4. Analysis

Read the diff and every source file it needs; verify each concern in the code, never from the description.

1. Surface enumeration first, for a diff that touches a Controller, Service, Mapper, Handler or Listener:
   - every controller method in the diff: verb, path, auth attribute, the service-level access check it calls
     (or "none"), READ endpoints included. `@NoCSRFRequired` is the deliberate org convention: never a finding;
   - every newly persisted field: grep every other write path of it; each must strip, compare or allowlist it;
   - every new catch block: does the fallback fail open (null, [] or false read as "allowed")? Fail-open on a
     deny check is a blocker;
   - every rule like "X wins over Y" or "immutable after create": enforced on create, update, batch, background
     job and CLI paths alike;
   - every new test: does it assert required behaviour, or lock in a permissive one?
2. Regression risk: deleted tests or assertions and whether their cases are still covered; a migration that
   leaves one call site behind; behaviour changes (return values, messages, HTTP codes, defaults, nulls, order,
   removed access checks).
3. Completeness: a fix applied to one member of a group (operators, mappers, handlers) is applied to all; what
   the description lists is all in the diff; the same bad pattern does not reappear elsewhere in the diff.
4. Blast radius: a changed constructor or method signature, or a new precondition in a service method: grep
   `tests/` for `new <Class>(` and the stubs of the new collaborator call, and `lib/` for every caller.
   Published contracts (openregister `lib/Contract/**`, `lib/Api/**`, anything another fleet app calls) keep
   their signatures.
5. Fleet rules: procedures are configuration and the code is generic; pages are manifest configuration over
   the shared widgets; no `/settings/user/<app>` pages; data in OpenRegister objects, no own tables; CSS
   variables only; SPDX headers on new files; frontend rules (no DOM dataset reads for server data, no admin
   settings in the vue-router, NcSelect has `inputLabel`, each modal or dialog in its own file under
   `src/modals/` or `src/dialogs/`).
6. A PR whose commits are `feat(<app>): build <change>` implements `openspec/changes/<change>`: behaviour that
   change's specs ask for is expected, so judge it against those specs.
7. Every blocker carries one deterministic verification (a grep, ls, wc or git show line and its result).
   Re-run it before you count the blocker; a claim the command refutes is dropped. Never invent findings:
   APPROVE with an empty list when the PR is clean.

## 5. Verdict and ledger

Record it at once, from `~/q`:

    bash bin/cm_land.sh review <app> <n> <full head sha> APPROVE|REQUEST_CHANGES $W "<mode>; <blockers or 'no blockers'>; <concerns, short>"

REQUEST_CHANGES also writes a BLOCK verdict at that sha, so a fix task follows. You may fix the blockers
yourself on the PR branch (see WORKER.md, merge) and review the new head again; otherwise leave it.
