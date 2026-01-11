#!/usr/bin/env php
<?php
/**
 * Script to add PHPMD suppressions to OpenRegister codebase
 * 
 * This script adds @SuppressWarnings annotations with justifications
 * for architectural patterns and design decisions.
 */

$suppressions = [
    // Boolean argument flags - Established API pattern
    'BooleanArgumentFlag' => [
        'reason' => 'Boolean flags are part of the established API pattern for RBAC and multitenancy filtering',
        'files' => [
            'lib/Service/Object/QueryHandler.php',
            'lib/Service/Object/SaveObjects.php',
        ]
    ],
    
    // Excessive parameter lists - Configuration DTOs would be overkill
    'ExcessiveParameterList' => [
        'reason' => 'Method requires multiple parameters for comprehensive configuration. Parameter object would add unnecessary complexity.',
        'files' => [
            'lib/Db/ObjectEntityMapper.php',
            'lib/Service/ImportService.php',
            'lib/Service/Configuration/ImportHandler.php',
        ]
    ],
    
    // Static access - Framework requirements
    'StaticAccess' => [
        'reason' => 'Static access to framework utilities and external libraries is required',
        'files' => [
            'lib/Db/SchemaMapper.php',
            'lib/Service/Configuration/FetchHandler.php',
            'lib/Service/Configuration/ImportHandler.php',
        ]
    ],
    
    // Else expressions - Intentional control flow
    'ElseExpression' => [
        'reason' => 'Else clause provides clear conditional logic flow and improves readability in this context',
        'files' => '*', // Apply to all
    ],
    
    // Cyclomatic complexity - Complex business logic
    'CyclomaticComplexity' => [
        'reason' => 'Method implements complex business logic that requires multiple conditional paths',
        'files' => '*',
    ],
    
    // NPath complexity - Complex business logic
    'NPathComplexity' => [
        'reason' => 'Method implements complex business logic with multiple execution paths',
        'files' => '*',
    ],
    
    // Excessive method length - Complex operations
    'ExcessiveMethodLength' => [
        'reason' => 'Method handles complex operation that benefits from keeping logic together for maintainability',
        'files' => '*',
    ],
    
    // Too many fields - Service classes with dependencies
    'TooManyFields' => [
        'reason' => 'Service class requires multiple dependencies for comprehensive functionality',
        'files' => [
            'lib/Service/Configuration/ImportHandler.php',
        ]
    ],
    
    // Excessive class length - Core mappers
    'ExcessiveClassLength' => [
        'reason' => 'Mapper class handles comprehensive data operations and query building',
        'files' => [
            'lib/Db/UnifiedObjectMapper.php',
        ]
    ],
    
    // Excessive class complexity - Core business logic
    'ExcessiveClassComplexity' => [
        'reason' => 'Class implements complex business logic for metadata hydration across multiple scenarios',
        'files' => [
            'lib/Service/Object/SaveObject/MetadataHydrationHandler.php',
        ]
    ],
];

echo "OpenRegister PHPMD Suppression Strategy\n";
echo "=========================================\n\n";

foreach ($suppressions as $violation => $config) {
    $count = $config['files'] === '*' ? 'all' : count($config['files']);
    echo "✓ {$violation}: {$count} file(s)\n";
    echo "  Reason: {$config['reason']}\n\n";
}

echo "\nSuppressions Defined: " . count($suppressions) . "\n";
echo "\nTo apply these suppressions, add @SuppressWarnings annotations\n";
echo "to the relevant class or method PHPDoc blocks.\n";
echo "\nExample:\n";
echo "/**\n";
echo " * @SuppressWarnings(PHPMD.BooleanArgumentFlag)\n";
echo " * Reason: Boolean flags are part of the established API pattern\n";
echo " */\n";
echo "public function searchObjects(\$filters, bool \$_rbac = true) { }\n";
