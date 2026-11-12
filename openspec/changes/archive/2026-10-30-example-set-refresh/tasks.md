# Tasks: example-set-refresh

- [x] `ExampleSetRewriter`: `keep()` picks the refresh or the references-only path, and `rewrite()` writes the declared values over the stored row
- [x] `ExampleSetInstaller`: a kept object goes through the rewriter; the plan's `refresh` decides
- [x] `ExampleSetInstallCommand`: `--refresh`, and the refreshed count per type
- [x] Tests: a date moves to the new day, an undeclared field stays, an object the set did not write is untouched; the command reports the count
