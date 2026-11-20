# Wave 3 addendum

`BRIEF.md` applies in full. Wave 3 differs in these points:

1. **Package ids are W01 to W30**, files in `wave2/packages/`. Your branch is `cloud/mod-W<nn>-<topic>`.
2. **Many references in your package survived waves 1 and 2.** Before you change a file, look at what earlier waves said about it: `gh api "search/issues?q=repo:ConductionNL/openregister+is:pr+head:cloud/mod-+<ClassName>" -q '.items[] | "\(.number) \(.state) \(.title)"'`, then read the bodies of those PRs (`gh api repos/ConductionNL/openregister/pulls/<n> -q .body`). If an earlier wave left a reference with a reason, don't repeat the forbidden move it rejected. Find an allowed one (an event, a port owned by the lower area, an id or Kernel value object), or leave it with a sharper reason.
3. **Open every PR ready for review, never as a draft:** add `-F draft=false` to the `gh api .../pulls` call.
4. **Prefer independent PRs, each from fresh `origin/main`.** Stack only when the second change cannot exist without the first, and then say so in the body.
5. **Main moves every few minutes.** Run `git merge origin/main` (never rebase) right before you push each PR, and rerun the modularity check after the merge.
6. The coordinator merges with an automatic check: a PR that adds a reference against the area order is refused. Gone references are what counts. Put "references against the order before/after" in the body.
