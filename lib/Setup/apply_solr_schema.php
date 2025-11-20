<?php
/**
 * Apply SOLR Schema Configuration for OpenRegister
 *
 * ⚠️  DEPRECATED: This script is deprecated and should not be used.
 *
 * This script creates field configurations that conflict with the main field management system.
 * Use the dedicated field management UI/API instead:
 * - SOLR Configuration > Field Management
 * - API: /apps/openregister/api/settings/solr/fields/create
 *
 * @deprecated Use the field management UI/API instead
 * @category   Setup
 * @package    OCA\OpenRegister\Setup
 * @author     OpenRegister Team
 * @copyright  2024 OpenRegister
 * @license    AGPL-3.0-or-later
 * @version    1.0.0
 * @link       https://github.com/OpenRegister/OpenRegister
 */

echo "⚠️  DEPRECATED SCRIPT\n";
echo "==========================================\n";
echo "This script is deprecated and should not be used.\n";
echo "It creates field configurations that conflict with the main field management system.\n\n";
echo "Please use the dedicated field management instead:\n";
echo "- SOLR Configuration > Field Management (UI)\n";
echo "- API: /apps/openregister/api/settings/solr/fields/create\n\n";
echo "Exiting without making changes...\n";
exit(1);

// Get collection name from command line or use default.
$collectionName = $argv[1] ?? 'openregister_nc_f0e53393';
$solrBaseUrl    = 'http://nextcloud-dev-solr:8983/solr';

echo "🔧 Applying SOLR Schema Configuration for OpenRegister\n";
echo "Collection: {$collectionName}\n";
echo "SOLR URL: {$solrBaseUrl}\n";
echo "==========================================\n\n";

/*
 * ObjectEntity field definitions for SOLR schema
 * Based on ObjectEntity.php metadata properties
 */
$fieldDefinitions = [
    // **CRITICAL**: Core tenant field with self_ prefix (consistent naming).
    'self_tenant'         => [
        'type'        => 'string',
        'stored'      => true,
        'indexed'     => true,
        'multiValued' => false,
        'docValues'   => false,
// Not useful for faceting.
        'description' => 'Tenant identifier for multi-tenancy',
    ],

    // Metadata fields with self_ prefix (consistent with legacy mapping).
    'self_object_id'      => [
        'type'        => 'pint',
        'stored'      => true,
        'indexed'     => true,
        'multiValued' => false,
        'docValues'   => false,
    // Not useful for faceting.
        'description' => 'Database object ID',
    ],
    'self_uuid'           => [
        'type'        => 'string',
        'stored'      => true,
        'indexed'     => true,
        'multiValued' => false,
        'docValues'   => false,
    // Not useful for faceting.
        'description' => 'Object UUID',
    ],

    // Context fields.
    'self_register'       => [
        'type'        => 'pint',
        'stored'      => true,
        'indexed'     => true,
        'multiValued' => false,
        'docValues'   => true,
        'description' => 'Register ID',
    ],
    'self_schema'         => [
        'type'        => 'pint',
        'stored'      => true,
        'indexed'     => true,
        'multiValued' => false,
        'docValues'   => true,
        'description' => 'Schema ID',
    ],
    'self_schema_version' => [
        'type'        => 'string',
        'stored'      => true,
        'indexed'     => true,
        'multiValued' => false,
        'docValues'   => true,
        'description' => 'Schema version',
    ],

    // Ownership and metadata.
    'self_owner'          => [
        'type'        => 'string',
        'stored'      => true,
        'indexed'     => true,
        'multiValued' => false,
        'docValues'   => true,
        'description' => 'Object owner',
    ],
    'self_organisation'   => [
        'type'        => 'string',
        'stored'      => true,
        'indexed'     => true,
        'multiValued' => false,
        'docValues'   => true,
        'description' => 'Organisation',
    ],
    'self_application'    => [
        'type'        => 'string',
        'stored'      => true,
        'indexed'     => true,
        'multiValued' => false,
        'docValues'   => true,
        'description' => 'Application name',
    ],

    // Core object fields (no suffixes needed when explicitly defined).
    'self_name'           => [
        'type'        => 'string',
        'stored'      => true,
        'indexed'     => true,
        'multiValued' => false,
        'docValues'   => false,
    // Not useful for faceting - used for search.
        'description' => 'Object name',
    ],
    'self_description'    => [
        'type'        => 'text_general',
        'stored'      => true,
        'indexed'     => true,
        'multiValued' => false,
        'docValues'   => false,
    // Not useful for faceting - used for search.
        'description' => 'Object description (full-text searchable)',
    ],
    'self_summary'        => [
        'type'        => 'text_general',
        'stored'      => true,
        'indexed'     => true,
        'multiValued' => false,
        'docValues'   => false,
    // Not useful for faceting - used for search.
        'description' => 'Object summary',
    ],
    'self_image'          => [
        'type'        => 'string',
        'stored'      => true,
        'indexed'     => false,
        'multiValued' => false,
        'docValues'   => false,
    // Not useful for faceting.
        'description' => 'Object image reference',
    ],
    'self_slug'           => [
        'type'        => 'string',
        'stored'      => true,
        'indexed'     => true,
        'multiValued' => false,
        'docValues'   => false,
    // Not useful for faceting.
        'description' => 'Object URL slug',
    ],
    'self_uri'            => [
        'type'        => 'string',
        'stored'      => true,
        'indexed'     => true,
        'multiValued' => false,
        'docValues'   => false,
    // Not useful for faceting.
        'description' => 'Object URI',
    ],
    'self_version'        => [
        'type'        => 'string',
        'stored'      => true,
        'indexed'     => true,
        'multiValued' => false,
        'docValues'   => false,
    // Not useful for faceting.
        'description' => 'Object version',
    ],
    'self_size'           => [
        'type'        => 'string',
        'stored'      => true,
        'indexed'     => false,
        'multiValued' => false,
        'docValues'   => false,
    // Not useful for faceting.
        'description' => 'Object size',
    ],
    'self_folder'         => [
        'type'        => 'string',
        'stored'      => true,
        'indexed'     => true,
        'multiValued' => false,
        'docValues'   => false,
    // Not useful for faceting.
        'description' => 'Object folder path',
    ],

    // Timestamps (SOLR date format).
    'self_created'        => [
        'type'        => 'pdate',
        'stored'      => true,
        'indexed'     => true,
        'multiValued' => false,
        'docValues'   => true,
        'description' => 'Creation timestamp',
    ],
    'self_updated'        => [
        'type'        => 'pdate',
        'stored'      => true,
        'indexed'     => true,
        'multiValued' => false,
        'docValues'   => true,
        'description' => 'Last update timestamp',
    ],
    'self_published'      => [
        'type'        => 'pdate',
        'stored'      => true,
        'indexed'     => true,
        'multiValued' => false,
        'docValues'   => true,
        'description' => 'Published timestamp',
    ],
    'self_depublished'    => [
        'type'        => 'pdate',
        'stored'      => true,
        'indexed'     => true,
        'multiValued' => false,
        'docValues'   => true,
        'description' => 'Depublished timestamp',
    ],

    // **NEW**: UUID relation fields for clean object relationships.
    'self_relations'      => [
        'type'        => 'string',
        'stored'      => true,
        'indexed'     => true,
        'multiValued' => true,
        'description' => 'Array of related object UUIDs',
    ],
    'self_files'          => [
        'type'        => 'string',
        'stored'      => true,
        'indexed'     => true,
        'multiValued' => true,
        'description' => 'Array of file UUIDs/references',
    ],
    'self_parent_uuid'    => [
        'type'        => 'string',
        'stored'      => true,
        'indexed'     => true,
        'multiValued' => false,
        'description' => 'Parent object UUID (single relation)',
    ],
];


/**
 * Apply schema field configuration
 */
function applySchemaField($collectionName, $solrBaseUrl, $fieldName, $fieldConfig)
{
    $url = "{$solrBaseUrl}/{$collectionName}/schema";

    // Try to add field first.
    $addPayload = [
        'add-field' => array_merge(['name' => $fieldName], $fieldConfig),
    ];

    $result = makeHttpRequest($url, $addPayload);

    if ($result['success']) {
        echo "✅ Added field: {$fieldName}\n";
        return true;
    }

    // If add failed, try to replace.
    $replacePayload = [
        'replace-field' => array_merge(['name' => $fieldName], $fieldConfig),
    ];

    $result = makeHttpRequest($url, $replacePayload);

    if ($result['success']) {
        echo "✅ Updated field: {$fieldName}\n";
        return true;
    } else {
        echo "❌ Failed to configure field: {$fieldName} - {$result['error']}\n";
        return false;
    }

}//end applySchemaField()


/**
 * Make HTTP request to SOLR
 */
function makeHttpRequest($url, $payload)
{
    $context = stream_context_create(
            [
                'http' => [
                    'method'  => 'POST',
                    'header'  => 'Content-Type: application/json',
                    'content' => json_encode($payload),
                    'timeout' => 30,
                ],
            ]
            );

    $response = @file_get_contents($url, false, $context);

    if ($response === false) {
        return ['success' => false, 'error' => 'HTTP request failed'];
    }

    $data = json_decode($response, true);

    if ($data === null) {
        return ['success' => false, 'error' => 'Invalid JSON response'];
    }

    $success = ($data['responseHeader']['status'] ?? -1) === 0;
    $error   = $success ? null : ($data['error']['msg'] ?? 'Unknown error');

    return ['success' => $success, 'error' => $error, 'data' => $data];

}//end makeHttpRequest()


// Apply all field configurations.
$successCount = 0;
$totalFields  = count($fieldDefinitions);

echo "Configuring {$totalFields} ObjectEntity metadata fields with self_ prefixes and UUID relation types...\n\n";

foreach ($fieldDefinitions as $fieldName => $fieldConfig) {
    $description = $fieldConfig['description'] ?? '';
    echo "🔧 {$fieldName} ({$description}): ";

    // Remove description from field config (not a SOLR field property).
    unset($fieldConfig['description']);

    if (applySchemaField($collectionName, $solrBaseUrl, $fieldName, $fieldConfig)) {
        $successCount++;
    }
}

echo "\n==========================================\n";
echo "Schema configuration completed!\n";
echo "✅ Success: {$successCount}/{$totalFields} fields configured\n";

if ($successCount === $totalFields) {
    echo "🎉 All ObjectEntity metadata fields are properly configured!\n";
    echo "   Your SOLR schema is ready for production use.\n";
    exit(0);
} else {
    echo "⚠️  Some fields failed to configure. Check SOLR logs for details.\n";
    exit(1);
}
