---
description: Run the PHP unit tests and the Jest tests, optionally filtered
argument-hint: "[filter]"
---
Run from the repository root.

PHP unit tests (the "Unit Tests" suite in `phpunit.xml`, no Nextcloud needed):

```bash
composer test:unit
```

With a filter in $ARGUMENTS, narrow PHPUnit to it:

```bash
./vendor/bin/phpunit --testsuite="Unit Tests" --filter "$ARGUMENTS"
```

JavaScript tests (Jest, `jest.config.js`), all or by path pattern:

```bash
npm test
npx jest --silent $ARGUMENTS
```

Report the pass, fail and skip counts and the first failure in full. Do not change a test to make it pass unless the test itself is wrong; say so when it is.
