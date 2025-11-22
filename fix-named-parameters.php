<?php
/**
 * Script to automatically fix named parameter warnings in PHP files
 * 
 * This script fixes common patterns that PHPCS flags for named parameters:
 * - Logger method calls (info, warning, error, debug)
 * - JSONResponse constructor calls
 * - Parent constructor calls
 * - Common built-in functions
 * 
 * Usage: php fix-named-parameters.php [--dry-run] [--file=path/to/file.php]
 */

declare(strict_types=1);

class NamedParameterFixer
{
    private bool $dryRun;
    private array $stats = [
        'files_processed' => 0,
        'files_modified' => 0,
        'replacements' => 0,
    ];

    public function __construct(bool $dryRun = false)
    {
        $this->dryRun = $dryRun;
    }

    /**
     * Fix a single file
     */
    public function fixFile(string $filePath): void
    {
        if (!file_exists($filePath)) {
            echo "File not found: $filePath\n";
            return;
        }

        $content = file_get_contents($filePath);
        $originalContent = $content;
        $replacements = 0;

        // Fix logger method calls: $logger->info('message', ['context'])
        $content = preg_replace_callback(
            '/\$(\w+)->(info|warning|error|debug|critical|alert|emergency)\s*\(\s*([^,]+?)\s*,\s*(\[[^\]]+\])\s*\)/s',
            function ($matches) use (&$replacements) {
                $replacements++;
                $var = $matches[1];
                $method = $matches[2];
                $message = trim($matches[3]);
                $context = trim($matches[4]);
                return "\${$var}->{$method}(message: {$message}, context: {$context})";
            },
            $content
        );

        // Fix logger method calls with single string parameter: $logger->info('message')
        // Only match if it's a simple string literal (not a variable or complex expression)
        $content = preg_replace_callback(
            '/\$(\w+)->(info|warning|error|debug|critical|alert|emergency)\s*\(\s*(["\'])(?:[^"\'\\\\]|\\\\.)*?\3\s*\)(?!\s*->)/s',
            function ($matches) use (&$replacements) {
                // Skip if it's already using named parameters
                if (strpos($matches[0], ':') !== false) {
                    return $matches[0];
                }
                $replacements++;
                $var = $matches[1];
                $method = $matches[2];
                $quote = $matches[3];
                $message = $matches[0];
                // Extract the message part
                preg_match('/\(\s*(["\'])(?:[^"\'\\\\]|\\\\.)*?\1/', $message, $msgMatch);
                $msgContent = $msgMatch[0];
                return "\${$var}->{$method}(message: {$msgContent})";
            },
            $content
        );

        // Fix JSONResponse constructor: new JSONResponse($data, $statusCode)
        $content = preg_replace_callback(
            '/new\s+JSONResponse\s*\(\s*([^,]+?)\s*,\s*([^\)]+?)\s*\)/s',
            function ($matches) use (&$replacements) {
                if (strpos($matches[0], 'data:') !== false || strpos($matches[0], 'statusCode:') !== false) {
                    return $matches[0]; // Already fixed
                }
                $replacements++;
                $data = trim($matches[1]);
                $statusCode = trim($matches[2]);
                return "new JSONResponse(data: {$data}, statusCode: {$statusCode})";
            },
            $content
        );

        // Fix JSONResponse constructor with single parameter: new JSONResponse($data)
        $content = preg_replace_callback(
            '/new\s+JSONResponse\s*\(\s*([^\)]+?)\s*\)(?!\s*->)/s',
            function ($matches) use (&$replacements) {
                if (strpos($matches[0], 'data:') !== false) {
                    return $matches[0]; // Already fixed
                }
                // Skip if it's a method chain
                if (preg_match('/\)\s*->/', $matches[0])) {
                    return $matches[0];
                }
                $replacements++;
                $data = trim($matches[1]);
                return "new JSONResponse(data: {$data})";
            },
            $content
        );

        // Fix TemplateResponse constructor: new TemplateResponse($appName, $templateName, $params, $renderAs)
        $content = preg_replace_callback(
            '/new\s+TemplateResponse\s*\(\s*([^,]+?)\s*,\s*([^,]+?)\s*,\s*([^,]+?)\s*,\s*([^\)]+?)\s*\)/s',
            function ($matches) use (&$replacements) {
                if (strpos($matches[0], 'appName:') !== false) {
                    return $matches[0]; // Already fixed
                }
                $replacements++;
                $appName = trim($matches[1]);
                $templateName = trim($matches[2]);
                $params = trim($matches[3]);
                $renderAs = trim($matches[4]);
                return "new TemplateResponse(appName: {$appName}, templateName: {$templateName}, params: {$params}, renderAs: {$renderAs})";
            },
            $content
        );

        // Fix TemplateResponse constructor with 3 parameters: new TemplateResponse($appName, $templateName, $params)
        $content = preg_replace_callback(
            '/new\s+TemplateResponse\s*\(\s*([^,]+?)\s*,\s*([^,]+?)\s*,\s*([^\)]+?)\s*\)/s',
            function ($matches) use (&$replacements) {
                if (strpos($matches[0], 'appName:') !== false) {
                    return $matches[0]; // Already fixed
                }
                $replacements++;
                $appName = trim($matches[1]);
                $templateName = trim($matches[2]);
                $params = trim($matches[3]);
                return "new TemplateResponse(appName: {$appName}, templateName: {$templateName}, params: {$params})";
            },
            $content
        );

        // Fix parent::__construct calls: parent::__construct($appName, $request)
        $content = preg_replace_callback(
            '/parent::__construct\s*\(\s*\$appName\s*,\s*\$request\s*\)/',
            function ($matches) use (&$replacements) {
                if (strpos($matches[0], 'appName:') !== false) {
                    return $matches[0]; // Already fixed
                }
                $replacements++;
                return 'parent::__construct(appName: $appName, request: $request)';
            },
            $content
        );

        // Fix sprintf: sprintf('format', $var1, $var2, ...)
        // Note: Only the first parameter can be named in sprintf due to variadic args
        $content = preg_replace_callback(
            '/sprintf\s*\(\s*(["\'])(?:[^"\'\\\\]|\\\\.)*?\1\s*,\s*([^\)]+?)\s*\)/s',
            function ($matches) use (&$replacements) {
                if (strpos($matches[0], 'format:') !== false) {
                    return $matches[0]; // Already fixed
                }
                $replacements++;
                $quote = $matches[1];
                $format = $quote . preg_match('/' . preg_quote($quote, '/') . '([^' . preg_quote($quote, '/') . '\\\\]|\\\\.)*?' . preg_quote($quote, '/') . '/', $matches[0], $fmtMatch) ? $fmtMatch[0] : '';
                $args = trim($matches[2]);
                // Extract the full format string
                preg_match('/\(\s*(["\'])(?:[^"\'\\\\]|\\\\.)*?\1/', $matches[0], $fmtFull);
                $formatStr = $fmtFull[0] ?? $matches[1] . '...' . $matches[1];
                return "sprintf(format: {$formatStr}, {$args})";
            },
            $content
        );

        // Fix DateTime constructor: new \DateTime($datetime)
        $content = preg_replace_callback(
            '/new\s+\\\?DateTime\s*\(\s*([^\)]+?)\s*\)(?!\s*->)/s',
            function ($matches) use (&$replacements) {
                if (strpos($matches[0], 'datetime:') !== false) {
                    return $matches[0]; // Already fixed
                }
                // Skip if it's a method chain
                if (preg_match('/\)\s*->/', $matches[0])) {
                    return $matches[0];
                }
                $replacements++;
                $datetime = trim($matches[1]);
                return "new \\DateTime(datetime: {$datetime})";
            },
            $content
        );

        // Fix ContentSecurityPolicy methods: $csp->addAllowedConnectDomain('*')
        $content = preg_replace_callback(
            '/\$(\w+)->addAllowedConnectDomain\s*\(\s*([^\)]+?)\s*\)/',
            function ($matches) use (&$replacements) {
                if (strpos($matches[0], 'domain:') !== false) {
                    return $matches[0]; // Already fixed
                }
                $replacements++;
                $var = $matches[1];
                $domain = trim($matches[2]);
                return "\${$var}->addAllowedConnectDomain(domain: {$domain})";
            },
            $content
        );

        // Fix setContentSecurityPolicy: $response->setContentSecurityPolicy($csp)
        $content = preg_replace_callback(
            '/\$(\w+)->setContentSecurityPolicy\s*\(\s*\$(\w+)\s*\)/',
            function ($matches) use (&$replacements) {
                if (strpos($matches[0], 'policy:') !== false) {
                    return $matches[0]; // Already fixed
                }
                $replacements++;
                $var1 = $matches[1];
                $var2 = $matches[2];
                return "\${$var1}->setContentSecurityPolicy(policy: \${$var2})";
            },
            $content
        );

        // Fix OpenRegister-specific patterns
        
        // Fix hasPermission: $schema->hasPermission('group', 'action', $userId, $userGroup, $objectOwner)
        // Handle 5 parameters including null values
        $content = preg_replace_callback(
            '/\$(\w+)->hasPermission\s*\(\s*([^,]+?)\s*,\s*([^,]+?)\s*,\s*([^,]+?)\s*,\s*([^,]+?)\s*,\s*([^\)]+?)\s*\)/s',
            function ($matches) use (&$replacements) {
                if (strpos($matches[0], 'groupId:') !== false) {
                    return $matches[0]; // Already fixed
                }
                $replacements++;
                $var = $matches[1];
                $groupId = trim($matches[2]);
                $action = trim($matches[3]);
                $userId = trim($matches[4]);
                $userGroup = trim($matches[5]);
                $objectOwner = trim($matches[6]);
                return "\${$var}->hasPermission(groupId: {$groupId}, action: {$action}, userId: {$userId}, userGroup: {$userGroup}, objectOwner: {$objectOwner})";
            },
            $content
        );
        
        // Fix hasPermission with nulls: $schema->hasPermission('public', $action, null, null, $objectOwner)
        $content = preg_replace_callback(
            '/\$(\w+)->hasPermission\s*\(\s*(["\']\w+["\'])\s*,\s*([^,]+?)\s*,\s*null\s*,\s*null\s*,\s*([^\)]+?)\s*\)/s',
            function ($matches) use (&$replacements) {
                if (strpos($matches[0], 'groupId:') !== false) {
                    return $matches[0]; // Already fixed
                }
                $replacements++;
                $var = $matches[1];
                $groupId = trim($matches[2]);
                $action = trim($matches[3]);
                $objectOwner = trim($matches[4]);
                return "\${$var}->hasPermission(groupId: {$groupId}, action: {$action}, userId: null, userGroup: null, objectOwner: {$objectOwner})";
            },
            $content
        );

        // Fix hasPermission with 3 params: $schema->hasPermission('group', 'action', $userId)
        $content = preg_replace_callback(
            '/\$(\w+)->hasPermission\s*\(\s*([^,]+?)\s*,\s*([^,]+?)\s*,\s*([^\)]+?)\s*\)/s',
            function ($matches) use (&$replacements) {
                if (strpos($matches[0], 'groupId:') !== false) {
                    return $matches[0]; // Already fixed
                }
                $replacements++;
                $var = $matches[1];
                $groupId = trim($matches[2]);
                $action = trim($matches[3]);
                $userId = trim($matches[4]);
                return "\${$var}->hasPermission(groupId: {$groupId}, action: {$action}, userId: {$userId})";
            },
            $content
        );

        // Fix hasPermission with 2 params: $schema->hasPermission('group', 'action')
        $content = preg_replace_callback(
            '/\$(\w+)->hasPermission\s*\(\s*([^,]+?)\s*,\s*([^\)]+?)\s*\)/s',
            function ($matches) use (&$replacements) {
                if (strpos($matches[0], 'groupId:') !== false) {
                    return $matches[0]; // Already fixed
                }
                $replacements++;
                $var = $matches[1];
                $groupId = trim($matches[2]);
                $action = trim($matches[3]);
                return "\${$var}->hasPermission(groupId: {$groupId}, action: {$action})";
            },
            $content
        );

        // Fix searchObjects: $mapper->searchObjects($query, $activeOrg, $rbac, $multi, $ids, $uses)
        $content = preg_replace_callback(
            '/\$(\w+)->searchObjects\s*\(\s*([^,]+?)\s*,\s*([^,]+?)\s*,\s*([^,]+?)\s*,\s*([^,]+?)\s*,\s*([^,]+?)\s*,\s*([^\)]+?)\s*\)/s',
            function ($matches) use (&$replacements) {
                if (strpos($matches[0], 'query:') !== false) {
                    return $matches[0]; // Already fixed
                }
                $replacements++;
                $var = $matches[1];
                $query = trim($matches[2]);
                $activeOrg = trim($matches[3]);
                $rbac = trim($matches[4]);
                $multi = trim($matches[5]);
                $ids = trim($matches[6]);
                $uses = trim($matches[7]);
                return "\${$var}->searchObjects(query: {$query}, activeOrganisationUuid: {$activeOrg}, rbac: {$rbac}, multi: {$multi}, ids: {$ids}, uses: {$uses})";
            },
            $content
        );
        
        // Fix searchObjects with 6 params (common case): $mapper->searchObjects($query, $activeOrg, $rbac, $multi, $ids, $uses)
        // This pattern handles cases where all 6 params are provided
        $content = preg_replace_callback(
            '/\$(\w+)->searchObjects\s*\(\s*\$(\w+)\s*,\s*\$(\w+)\s*,\s*\$(\w+)\s*,\s*\$(\w+)\s*,\s*\$(\w+)\s*,\s*\$(\w+)\s*\)/s',
            function ($matches) use (&$replacements) {
                if (strpos($matches[0], 'query:') !== false) {
                    return $matches[0]; // Already fixed
                }
                $replacements++;
                $var = $matches[1];
                return "\${$var}->searchObjects(query: \${$matches[2]}, activeOrganisationUuid: \${$matches[3]}, rbac: \${$matches[4]}, multi: \${$matches[5]}, ids: \${$matches[6]}, uses: \${$matches[7]})";
            },
            $content
        );

        // Fix searchObjects with fewer params (common case: $mapper->searchObjects($query, null, $rbac, $multi))
        $content = preg_replace_callback(
            '/\$(\w+)->searchObjects\s*\(\s*([^,]+?)\s*,\s*([^,]+?)\s*,\s*([^,]+?)\s*,\s*([^\)]+?)\s*\)/s',
            function ($matches) use (&$replacements) {
                if (strpos($matches[0], 'query:') !== false) {
                    return $matches[0]; // Already fixed
                }
                $replacements++;
                $var = $matches[1];
                $query = trim($matches[2]);
                $activeOrg = trim($matches[3]);
                $rbac = trim($matches[4]);
                $multi = trim($matches[5]);
                return "\${$var}->searchObjects(query: {$query}, activeOrganisationUuid: {$activeOrg}, rbac: {$rbac}, multi: {$multi})";
            },
            $content
        );

        // Fix findAll: $mapper->findAll($config, $rbac, $multi)
        $content = preg_replace_callback(
            '/\$(\w+)->findAll\s*\(\s*([^,]+?)\s*,\s*([^,]+?)\s*,\s*([^\)]+?)\s*\)/s',
            function ($matches) use (&$replacements) {
                if (strpos($matches[0], 'config:') !== false || strpos($matches[0], 'limit:') !== false) {
                    return $matches[0]; // Already fixed or different signature
                }
                $replacements++;
                $var = $matches[1];
                $config = trim($matches[2]);
                $rbac = trim($matches[3]);
                $multi = trim($matches[4]);
                return "\${$var}->findAll(config: {$config}, rbac: {$rbac}, multi: {$multi})";
            },
            $content
        );

        // Fix findAll with single param: $mapper->findAll($config)
        $content = preg_replace_callback(
            '/\$(\w+)->findAll\s*\(\s*([^\)]+?)\s*\)/s',
            function ($matches) use (&$replacements) {
                if (strpos($matches[0], 'config:') !== false || strpos($matches[0], 'limit:') !== false || strpos($matches[0], ':') !== false) {
                    return $matches[0]; // Already fixed or different signature
                }
                $replacements++;
                $var = $matches[1];
                $config = trim($matches[2]);
                return "\${$var}->findAll(config: {$config})";
            },
            $content
        );

        // Fix getHandler->find: $handler->find($id, $register, $schema, $extend, $files, $rbac, $multi)
        $content = preg_replace_callback(
            '/\$(\w+)->find\s*\(\s*([^,]+?)\s*,\s*([^,]+?)\s*,\s*([^,]+?)\s*,\s*([^,]+?)\s*,\s*([^,]+?)\s*,\s*([^,]+?)\s*,\s*([^\)]+?)\s*\)/s',
            function ($matches) use (&$replacements) {
                if (strpos($matches[0], 'id:') !== false) {
                    return $matches[0]; // Already fixed
                }
                $replacements++;
                $var = $matches[1];
                $id = trim($matches[2]);
                $register = trim($matches[3]);
                $schema = trim($matches[4]);
                $extend = trim($matches[5]);
                $files = trim($matches[6]);
                $rbac = trim($matches[7]);
                $multi = trim($matches[8]);
                return "\${$var}->find(id: {$id}, register: {$register}, schema: {$schema}, extend: {$extend}, files: {$files}, rbac: {$rbac}, multi: {$multi})";
            },
            $content
        );

        // Fix getHandler->find with fewer params (common: $handler->find($id, $register, $schema, $extend, $files))
        $content = preg_replace_callback(
            '/\$(\w+)->find\s*\(\s*([^,]+?)\s*,\s*([^,]+?)\s*,\s*([^,]+?)\s*,\s*([^,]+?)\s*,\s*([^\)]+?)\s*\)/s',
            function ($matches) use (&$replacements) {
                if (strpos($matches[0], 'id:') !== false) {
                    return $matches[0]; // Already fixed
                }
                $replacements++;
                $var = $matches[1];
                $id = trim($matches[2]);
                $register = trim($matches[3]);
                $schema = trim($matches[4]);
                $extend = trim($matches[5]);
                $files = trim($matches[6]);
                return "\${$var}->find(id: {$id}, register: {$register}, schema: {$schema}, extend: {$extend}, files: {$files})";
            },
            $content
        );

        // Fix userManager->get: $userManager->get($userId)
        $content = preg_replace_callback(
            '/\$(\w+)->get\s*\(\s*([^\)]+?)\s*\)(?!\s*->)/s',
            function ($matches) use (&$replacements) {
                // Skip if already has named params or if it's a method chain
                if (strpos($matches[0], ':') !== false || preg_match('/\)\s*->/', $matches[0])) {
                    return $matches[0];
                }
                // Only match if it looks like a user ID (string variable or literal)
                $arg = trim($matches[2]);
                if (preg_match('/^\$[a-zA-Z_][a-zA-Z0-9_]*$/', $arg) || preg_match('/^["\']/', $arg)) {
                    $replacements++;
                    $var = $matches[1];
                    return "\${$var}->get(userId: {$arg})";
                }
                return $matches[0];
            },
            $content
        );

        // Fix checkPermission: $this->checkPermission($schema, $action, $userId, $objectOwner, $rbac)
        $content = preg_replace_callback(
            '/\$this->checkPermission\s*\(\s*([^,]+?)\s*,\s*([^,]+?)\s*,\s*([^,]+?)\s*,\s*([^,]+?)\s*,\s*([^\)]+?)\s*\)/s',
            function ($matches) use (&$replacements) {
                if (strpos($matches[0], 'schema:') !== false) {
                    return $matches[0]; // Already fixed
                }
                $replacements++;
                $schema = trim($matches[1]);
                $action = trim($matches[2]);
                $userId = trim($matches[3]);
                $objectOwner = trim($matches[4]);
                $rbac = trim($matches[5]);
                return "\$this->checkPermission(schema: {$schema}, action: {$action}, userId: {$userId}, objectOwner: {$objectOwner}, rbac: {$rbac})";
            },
            $content
        );
        
        // Fix checkPermission with null: $this->checkPermission($schema, 'action', null, $objectOwner, $rbac)
        // Use a more flexible pattern that handles method calls
        $content = preg_replace_callback(
            '/\$this->checkPermission\s*\(\s*([^,]+?)\s*,\s*(["\']\w+["\'])\s*,\s*null\s*,\s*([^,]+?)\s*,\s*([^\)]+?)\s*\)/s',
            function ($matches) use (&$replacements) {
                if (strpos($matches[0], 'schema:') !== false) {
                    return $matches[0]; // Already fixed
                }
                $replacements++;
                $schema = trim($matches[1]);
                $action = trim($matches[2]);
                $objectOwner = trim($matches[3]);
                $rbac = trim($matches[4]);
                return "\$this->checkPermission(schema: {$schema}, action: {$action}, userId: null, objectOwner: {$objectOwner}, rbac: {$rbac})";
            },
            $content
        );
        
        // More general checkPermission pattern - match any 5 params
        $content = preg_replace_callback(
            '/\$this->checkPermission\s*\(\s*([^,]+?)\s*,\s*([^,]+?)\s*,\s*([^,]+?)\s*,\s*([^,]+?)\s*,\s*([^\)]+?)\s*\)/s',
            function ($matches) use (&$replacements) {
                if (strpos($matches[0], 'schema:') !== false) {
                    return $matches[0]; // Already fixed
                }
                $replacements++;
                $schema = trim($matches[1]);
                $action = trim($matches[2]);
                $userId = trim($matches[3]);
                $objectOwner = trim($matches[4]);
                $rbac = trim($matches[5]);
                return "\$this->checkPermission(schema: {$schema}, action: {$action}, userId: {$userId}, objectOwner: {$objectOwner}, rbac: {$rbac})";
            },
            $content
        );

        // Fix renderEntity: $handler->renderEntity($entity, $extend, $depth, $filter, $fields, $unset, $rbac, $multi)
        $content = preg_replace_callback(
            '/\$(\w+)->renderEntity\s*\(\s*([^,]+?)\s*,\s*([^,]+?)\s*,\s*([^,]+?)\s*,\s*([^,]+?)\s*,\s*([^,]+?)\s*,\s*([^,]+?)\s*,\s*([^,]+?)\s*,\s*([^\)]+?)\s*\)/s',
            function ($matches) use (&$replacements) {
                if (strpos($matches[0], 'entity:') !== false) {
                    return $matches[0]; // Already fixed
                }
                $replacements++;
                $var = $matches[1];
                $entity = trim($matches[2]);
                $extend = trim($matches[3]);
                $depth = trim($matches[4]);
                $filter = trim($matches[5]);
                $fields = trim($matches[6]);
                $unset = trim($matches[7]);
                $rbac = trim($matches[8]);
                $multi = trim($matches[9]);
                return "\${$var}->renderEntity(entity: {$entity}, extend: {$extend}, depth: {$depth}, filter: {$filter}, fields: {$fields}, unset: {$unset}, rbac: {$rbac}, multi: {$multi})";
            },
            $content
        );

        // Fix findSilent: $handler->findSilent($id, $register, $schema, $extend, $files)
        $content = preg_replace_callback(
            '/\$(\w+)->findSilent\s*\(\s*([^,]+?)\s*,\s*([^,]+?)\s*,\s*([^,]+?)\s*,\s*([^,]+?)\s*,\s*([^\)]+?)\s*\)/s',
            function ($matches) use (&$replacements) {
                if (strpos($matches[0], 'id:') !== false) {
                    return $matches[0]; // Already fixed
                }
                $replacements++;
                $var = $matches[1];
                $id = trim($matches[2]);
                $register = trim($matches[3]);
                $schema = trim($matches[4]);
                $extend = trim($matches[5]);
                $files = trim($matches[6]);
                return "\${$var}->findSilent(id: {$id}, register: {$register}, schema: {$schema}, extend: {$extend}, files: {$files})";
            },
            $content
        );

        // Fix saveObject handler: $handler->saveObject($register, $schema, $data, $uuid, ...)
        // This is complex, so handle common cases
        $content = preg_replace_callback(
            '/\$(\w+)->saveObject\s*\(\s*([^,]+?)\s*,\s*([^,]+?)\s*,\s*([^,]+?)\s*,\s*([^,]+?)\s*,\s*([^,]+?)\s*,\s*([^,]+?)\s*,\s*([^,]+?)\s*,\s*([^,]+?)\s*,\s*([^,]+?)\s*,\s*([^\)]+?)\s*\)/s',
            function ($matches) use (&$replacements) {
                if (strpos($matches[0], 'register:') !== false) {
                    return $matches[0]; // Already fixed
                }
                $replacements++;
                $var = $matches[1];
                return "\${$var}->saveObject(register: {$matches[2]}, schema: {$matches[3]}, data: {$matches[4]}, uuid: {$matches[5]}, folderId: {$matches[6]}, rbac: {$matches[7]}, multi: {$matches[8]}, persist: {$matches[9]}, silent: {$matches[10]}, validation: {$matches[11]})";
            },
            $content
        );

        // Fix format method calls: ->format('c') - but only for DateTime
        $content = preg_replace_callback(
            '/->format\s*\(\s*(["\'])([^"\']+)\1\s*\)/',
            function ($matches) use (&$replacements) {
                // Don't fix format() - it's a single required parameter, PHPCS might not want it named
                // But if PHPCS complains, we can add it
                return $matches[0];
            },
            $content
        );

        if ($content !== $originalContent) {
            $this->stats['files_modified']++;
            $this->stats['replacements'] += $replacements;
            
            if (!$this->dryRun) {
                file_put_contents($filePath, $content);
                echo "Fixed: $filePath ($replacements replacements)\n";
            } else {
                echo "[DRY RUN] Would fix: $filePath ($replacements replacements)\n";
            }
        }

        $this->stats['files_processed']++;
    }

    /**
     * Fix all PHP files in a directory
     */
    public function fixDirectory(string $directory): void
    {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory)
        );

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $this->fixFile($file->getPathname());
            }
        }
    }

    /**
     * Get statistics
     */
    public function getStats(): array
    {
        return $this->stats;
    }
}

// Main execution
$options = getopt('', ['dry-run', 'file:', 'dir:']);

$dryRun = isset($options['dry-run']);
$fixer = new NamedParameterFixer($dryRun);

if (isset($options['file'])) {
    $fixer->fixFile($options['file']);
} elseif (isset($options['dir'])) {
    $fixer->fixDirectory($options['dir']);
} else {
    // Default: fix lib/ directory
    $libDir = __DIR__ . '/lib';
    if (is_dir($libDir)) {
        echo "Fixing files in $libDir...\n";
        if ($dryRun) {
            echo "[DRY RUN MODE - No files will be modified]\n";
        }
        $fixer->fixDirectory($libDir);
    } else {
        echo "Error: lib/ directory not found\n";
        exit(1);
    }
}

$stats = $fixer->getStats();
echo "\n=== Summary ===\n";
echo "Files processed: {$stats['files_processed']}\n";
echo "Files modified: {$stats['files_modified']}\n";
echo "Total replacements: {$stats['replacements']}\n";

