<?php
/**
 * Test script for bulk save error reporting
 *
 * This script tests various error conditions in the bulk save operation
 * to ensure that external services can properly detect and handle failures.
 *
 * @category Test
 * @package  OCA\OpenRegister\Examples
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

echo "=== Bulk Save Error Reporting Test ===\n\n";

echo "This script tests various error conditions to ensure external services\n";
echo "can properly detect when bulk save operations fail.\n\n";

echo "TEST SCENARIOS:\n";
echo "1. Missing register ID in objects\n";
echo "2. Missing schema ID in objects\n";
echo "3. Invalid schema ID that doesn't exist\n";
echo "4. Malformed object data\n";
echo "5. Mixed valid and invalid objects\n\n";

echo "EXPECTED BEHAVIOR:\n";
echo "- All errors should be captured in the result['errors'] array\n";
echo "- External services should be able to detect failures\n";
echo "- Invalid objects should be listed in result['invalid'] array\n";
echo "- Statistics should accurately reflect success/failure counts\n\n";

echo "ERROR REPORTING STRUCTURE:\n";
echo "{\n";
echo "  'saved': [],      // Successfully saved objects\n";
echo "  'updated': [],    // Successfully updated objects\n";
echo "  'unchanged': [],  // Objects that didn't need updates\n";
echo "  'invalid': [],    // Objects that failed validation/processing\n";
echo "  'errors': [],     // System errors and failures\n";
echo "  'statistics': {\n";
echo "    'totalProcessed': N,\n";
echo "    'saved': N,\n";
echo "    'updated': N,\n";
echo "    'unchanged': N,\n";
echo "    'invalid': N,\n";
echo "    'errors': N\n";
echo "  }\n";
echo "}\n\n";

echo "TESTING COMMANDS:\n";
echo "To test these scenarios, use curl commands like:\n\n";

echo "# Test 1: Missing register ID\n";
echo "docker exec -it -u 33 master-nextcloud-1 bash -c \"\n";
echo "curl -u 'admin:admin' \\\n";
echo "     -H 'Content-Type: application/json' \\\n";
echo "     -X POST \\\n";
echo "     -d '[{\\\"@self\\\": {\\\"schema\\\": 1}, \\\"title\\\": \\\"Test Object\\\"}]' \\\n";
echo "     'http://localhost/index.php/apps/openregister/api/objects/bulk'\n";
echo "\"\n\n";

echo "# Test 2: Missing schema ID\n";
echo "docker exec -it -u 33 master-nextcloud-1 bash -c \"\n";
echo "curl -u 'admin:admin' \\\n";
echo "     -H 'Content-Type: application/json' \\\n";
echo "     -X POST \\\n";
echo "     -d '[{\\\"@self\\\": {\\\"register\\\": 1}, \\\"title\\\": \\\"Test Object\\\"}]' \\\n";
echo "     'http://localhost/index.php/apps/openregister/api/objects/bulk'\n";
echo "\"\n\n";

echo "# Test 3: Invalid schema ID\n";
echo "docker exec -it -u 33 master-nextcloud-1 bash -c \"\n";
echo "curl -u 'admin:admin' \\\n";
echo "     -H 'Content-Type: application/json' \\\n";
echo "     -X POST \\\n";
echo "     -d '[{\\\"@self\\\": {\\\"register\\\": 1, \\\"schema\\\": 99999}, \\\"title\\\": \\\"Test Object\\\"}]' \\\n";
echo "     'http://localhost/index.php/apps/openregister/api/objects/bulk'\n";
echo "\"\n\n";

echo "# Test 4: Mixed valid and invalid objects\n";
echo "docker exec -it -u 33 master-nextcloud-1 bash -c \"\n";
echo "curl -u 'admin:admin' \\\n";
echo "     -H 'Content-Type: application/json' \\\n";
echo "     -X POST \\\n";
echo "     -d '[\n";
echo "       {\\\"@self\\\": {\\\"register\\\": 1, \\\"schema\\\": 1}, \\\"title\\\": \\\"Valid Object\\\"},\n";
echo "       {\\\"@self\\\": {\\\"register\\\": 1}, \\\"title\\\": \\\"Missing Schema\\\"},\n";
echo "       {\\\"@self\\\": {\\\"schema\\\": 1}, \\\"title\\\": \\\"Missing Register\\\"}\n";
echo "     ]' \\\n";
echo "     'http://localhost/index.php/apps/openregister/api/objects/bulk'\n";
echo "\"\n\n";

echo "WHAT TO LOOK FOR IN RESULTS:\n";
echo "- HTTP status should be 400 or 500 for critical errors (missing register/schema)\n";
echo "- Response should contain detailed error information\n";
echo "- 'errors' array should contain all system-level failures\n";
echo "- 'invalid' array should contain objects that couldn't be processed\n";
echo "- 'statistics.errors' should count total error occurrences\n";
echo "- No objects should be silently ignored without error reporting\n\n";

echo "RECENT FIXES APPLIED:\n";
echo "✅ Schema loading failures now throw exceptions instead of silent continue\n";
echo "✅ Object preparation failures are logged and tracked\n";
echo "✅ Object reconstruction failures are reported in result errors\n";
echo "✅ Write-back failures are logged (but don't fail main operation)\n";
echo "✅ All critical errors are now exposed to external services\n\n";

echo "=== End of Error Reporting Test Guide ===\n";
