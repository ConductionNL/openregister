# Round 10b reviewer brief (additions to REVIEW-BRIEF.md)
Follow REVIEW-BRIEF.md in full, including its Wilco review-pr section (steps by heading name, Thorough for `[access]`, Standard otherwise; actually open `references/review-checklist.md` and `references/constructor-safety-checks.md` from a fresh hydra clone and say which you read) and its REST-budget section. `gh search code` returns nothing here; use the REST search API or local clones for cross-app callers.
- Interface lanes: one capability per interface, no god ports.
- Parameter lanes: no option arrays, variadics, IRequest below a controller; a method value object names a real concept and serves at least 2 methods (constructor dependency bundles are not value objects; access guards stay direct constructor parameters).
- Constructor or signature changes: check every registrar, factory table and test that builds the class.
- Leave `@SuppressWarnings` in place when moving code unless the warning really went away.
- Goldens must not depend on the machine timezone or clock.
- Move to a shared component such as `CnDataTable`: compare with the library source (unslotted text cut at 100 chars, `nowrap`, a dash for null); a visible change is a finding.
Own new clone at ~/sig-lanes/<your dir>, copy vendor (never symlink). No monitors or `tail -f`, kill only by PID. Narrow filters plus the brief's guard suites merged with current origin/development. Record each verdict in verdicts.txt as the brief says, at the head sha re-checked with `git ls-remote`. Post nothing on GitHub. Security specifics only in ~/memcap-work/sig/PRIVATE-FIXES.md. Report once (per PR a verdict with reason, findings, conflicts, what you did not read), then remove your clone with `cd ~/sig-lanes && rm -rf <your dir>`.
