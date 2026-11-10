# Woo build rules

Every change under `openspec/changes/` that came from the Woo capability programme points here. Read this
file before the first command. It is written for an agent building the change unattended, in a fresh clone.

## Branch and PR

- Work on the branch the issue's `## Branch` section names. Without one, use
  `feature/<issue-number>/<change-name>`, cut with `git checkout --no-track -b <branch> origin/development`.
  Without `--no-track` the branch tracks `development` and the push is refused.
- Open one PR with `--base development`. Never `main`, never `beta`.
- Merge `development` into your branch to catch up. Never rebase a pushed branch.
- No `Co-Authored-By` trailer on any commit.
- Done means: merged on `development` with CI green. A row only reads `production` once a store release
  carries it. Say in the PR which matrix rows the change closes.

## What a task needs before you tick it

- The test the task names exists, failed before your change and passes after it. A test that would pass on
  today's code proves nothing.
- The new code has a caller: a route, listener, job or component that reaches it, and a test through that
  caller. A class with a full test suite and no caller is not done.
- Where data could leak or a legal duty could be claimed falsely, the code fails closed. Unpublished,
  unredacted, unsent and unarmed are the safe states, never the fallback.
- A cross-app call names the method, its arguments and its return keys, with a test on each side. A
  dynamic call compiles even when the other side renamed it.
- When another app the change depends on is not installed, the behaviour the spec names happens. Woo
  requests require dossiq and have no fallback.

## Doubles and test traps

- Check the real class before you mock it. OpenRegister entities use magic getters (`Schema::getId()`
  comes from `Entity::__call`), and mappers throw `DoesNotExistException` instead of returning null. Use
  the `environmentAwareDouble()` pattern where the repo has one, and read the real signature on
  `ConductionNL/openregister` branch `development` or in `vendor/`.
- Construct the real event class in a listener test. A faked event hides a wrong accessor.
- A test that executes a class outside its `@covers` set is marked risky, and PHPUnit then discards that
  test's coverage without saying so.

## Verification, in this order

While building, run only what your diff touches: `php -l`, phpcs and phpstan on changed files, the unit
tests of the classes you changed, eslint and stylelint on changed frontend files.

Before pushing, once:

```
COMPOSER_PROCESS_TIMEOUT=0 composer check:strict
npm run lint
```

Then every other leg the repo's `code-quality.yml` requires. Read `package.json` and the workflow rather than
trusting a list. In opencatalogi they are `format`, `check:l10n`, `check:l10n-js`, `check:manifest` and
`check:schema-l10n`.

Read results by content, not by exit code:

- **A green PHPUnit suite exits 1 without a coverage driver.** Use `--no-coverage`, or read the `Tests:`
  line for `Failures:` and `Errors:`.
- **`check:strict` can exit 0 while skipping the test suite** when the clone is not inside a Nextcloud
  tree. It prints that the tests did not run. Get a real verdict from `./vendor/bin/phpunit`.
- **A filtered PHPUnit run cannot see a central regression.** If you touched a shared service, run the full
  suite.
- **The Hydra gates need a base.** `run-hydra-gates.sh --base origin/development`, then count how many gates
  ran. Without `--base` they read not applicable, which is not a pass.
- **phpmd takes arguments positionally**, `<source> <format> <ruleset>`, with sources comma separated.
- Set `TMPDIR` to a directory beside the clone, never inside it. Some tests assert behaviour outside a git
  repository.

A finding on a line you did not touch is inherited. Name it in one sentence in the PR body and leave it,
unless it sits in a file you are editing and takes under fifteen minutes.

Do not run `generate_mock_register.py` to satisfy a demo-data gate. Add demo rows by hand and test that the
app admits them.

## Writing

Every text a user reads follows the Conduction voice: no em-dashes, no Title Case, sentence case headings,
one claim per sentence. That covers labels, page titles, schema descriptions and the PR body.
