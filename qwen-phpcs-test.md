# PHPCS Fix Task (Test Batch)

You are working in `/home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister`.

## Task

Fix the PHPCS errors in these 5 files ONLY:

1. `lib/Command/SolrManagementCommand.php` (1 error)
2. `lib/Controller/ConfigurationsController.php` (1 error)
3. `lib/Controller/FileTextController.php` (1 error)
4. `lib/Controller/AgentsController.php` (1 error)
5. `lib/Controller/ChatController.php` (3 errors)

## How to fix

### "must use named parameters" errors
Find the function call, look at the method signature to get the parameter name, and add it:
- BEFORE: `$this->setName('value')` where signature is `setName(string $name)`
- AFTER: `$this->setName(name: 'value')`

### "Inline comments must end in full-stops" errors
Add a period `.` at the end of the comment line.

## Process

1. Run `./vendor/bin/phpcs --standard=phpcs.xml --no-colors lib/Command/SolrManagementCommand.php lib/Controller/ConfigurationsController.php lib/Controller/FileTextController.php lib/Controller/AgentsController.php lib/Controller/ChatController.php` to see current errors
2. Read each file, find the error line, fix it
3. Run PHPCS again on all 5 files to verify zero errors
4. Report results

Do NOT touch any other files. Only fix these 5 files.
