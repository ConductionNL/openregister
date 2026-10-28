# Tasks: example-set-refresh-skips-unchanged

- [x] Tests (failing first): a same-day second refresh writes nothing and counts `unchanged`; control: a moved date and an instance edit are still written
- [x] `ExampleSetRewriter::rewrite()` compares the writable update with the stored row and skips the update when they match; owner hand-over and `written` stay
- [x] `ExampleSetInstallCommand` prints the `unchanged` count after `--refresh`
- [x] Existing refresh tests follow: an unchanged object is no longer counted as refreshed
