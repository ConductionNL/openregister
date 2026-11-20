---
description: Run OpenRegister's quality gate (the same checks CI runs)
argument-hint: "[quick]"
---
Run from the repository root, with `composer install` and `npm ci` done.

With no argument, run the authoritative gate once (it takes a while; it runs every PHPUnit suite, and the Integration, Database and Service suites skip themselves outside a Nextcloud container):

```bash
COMPOSER_PROCESS_TIMEOUT=0 composer check:strict
npm run lint
npm run stylelint
npm run check:specs
```

With `quick` in $ARGUMENTS, run the faster loop instead:

```bash
composer check
npm run lint
```

Report each tool's result. For a failure, give the file, line and rule. Tell apart findings on lines this branch changed from ones already on `main` (`git diff origin/main...HEAD`). Never add baseline entries, suppressions or exemptions to make a check green.
