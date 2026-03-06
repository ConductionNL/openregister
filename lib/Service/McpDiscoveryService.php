<?php

/**
 * MCP Discovery Service
 *
 * Provides AI agents with tiered discovery of OpenRegister's API capabilities.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

namespace OCA\OpenRegister\Service;

use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCP\IURLGenerator;

/**
 * McpDiscoveryService builds tiered API discovery responses for AI agents
 *
 * Tier 1: Compact catalog of capability areas (public, no auth).
 * Tier 2: Detailed endpoint docs with live data per capability (authenticated).
 *
 * @package OCA\OpenRegister\Service
 *
 * @psalm-suppress UnusedClass - Used via DI in McpController
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class McpDiscoveryService
{

    /**
     * App name constant
     *
     * @var string
     */
    private const APP_NAME = 'openregister';

    /**
     * API version for MCP endpoints
     *
     * @var string
     */
    private const API_VERSION = '1.0';

    /**
     * Register mapper for fetching live register data
     *
     * @var RegisterMapper
     */
    private readonly RegisterMapper $registerMapper;

    /**
     * Schema mapper for fetching live schema data
     *
     * @var SchemaMapper
     */
    private readonly SchemaMapper $schemaMapper;

    /**
     * URL generator for building absolute URLs
     *
     * @var IURLGenerator
     */
    private readonly IURLGenerator $urlGenerator;

    /**
     * Constructor
     *
     * @param RegisterMapper $registerMapper Register mapper instance
     * @param SchemaMapper   $schemaMapper   Schema mapper instance
     * @param IURLGenerator  $urlGenerator   URL generator instance
     */
    public function __construct(
        RegisterMapper $registerMapper,
        SchemaMapper $schemaMapper,
        IURLGenerator $urlGenerator
    ) {
        $this->registerMapper = $registerMapper;
        $this->schemaMapper   = $schemaMapper;
        $this->urlGenerator   = $urlGenerator;
    }//end __construct()

    /**
     * Get the base URL for this app's API
     *
     * @return string The base URL path
     */
    private function getBaseUrl(): string
    {
        return $this->urlGenerator->linkToRoute(routeName: self::APP_NAME.'.dashboard.page');
    }//end getBaseUrl()

    /**
     * Build a Tier 2 discovery URL for a capability
     *
     * @param string $capabilityId The capability identifier
     *
     * @return string The absolute URL
     */
    private function getCapabilityHref(string $capabilityId): string
    {
        return $this->urlGenerator->linkToRoute(
            routeName: self::APP_NAME.'.mcp.discoverCapability',
            arguments: ['capability' => $capabilityId]
        );
    }//end getCapabilityHref()

    /**
     * Get Tier 1 discovery catalog (public, no auth needed)
     *
     * Returns a compact overview of all capability areas with drill-down URLs.
     *
     * @return array<string, mixed> The discovery catalog
     */
    public function getCatalog(): array
    {
        $capabilities = [
            [
                'id'          => 'registers',
                'name'        => 'Registers',
                'description' => 'Data containers that group schemas and their objects. CRUD, export, import, publish.',
            ],
            [
                'id'          => 'schemas',
                'name'        => 'Schemas',
                'description' => 'JSON Schema definitions that define object structure. CRUD, upload, download, publish.',
            ],
            [
                'id'          => 'objects',
                'name'        => 'Objects',
                'description' => 'Data records stored in register/schema pairs. Full CRUD, filtering, pagination, lock/unlock, publish.',
            ],
            [
                'id'          => 'search',
                'name'        => 'Search',
                'description' => 'Full-text, semantic, and hybrid search across objects and files.',
            ],
            [
                'id'          => 'files',
                'name'        => 'Files',
                'description' => 'File attachments on objects. Upload, download, text extraction, anonymization.',
            ],
            [
                'id'          => 'audit',
                'name'        => 'Audit Trails',
                'description' => 'Change history for objects. View, export, and manage audit records.',
            ],
            [
                'id'          => 'bulk',
                'name'        => 'Bulk Operations',
                'description' => 'Batch save, delete, publish/depublish objects across a register/schema.',
            ],
            [
                'id'          => 'webhooks',
                'name'        => 'Webhooks',
                'description' => 'Event-driven HTTP callbacks. CRUD, test, view logs.',
            ],
            [
                'id'          => 'chat',
                'name'        => 'AI Chat',
                'description' => 'Conversational AI assistant for querying and managing register data.',
            ],
            [
                'id'          => 'views',
                'name'        => 'Views',
                'description' => 'Saved search/filter configurations for reusable data views.',
            ],
        ];

        // Add href to each capability.
        foreach ($capabilities as &$cap) {
            $cap['href'] = $this->getCapabilityHref(capabilityId: $cap['id']);
        }

        $description  = 'A flexible data register platform for Nextcloud.';
        $description .= ' Manages structured objects across registers and schemas';
        $description .= ' with full CRUD, search, audit trails, file management, and AI capabilities.';

        return [
            'version'        => self::API_VERSION,
            'name'           => 'OpenRegister',
            'description'    => $description,
            'authentication' => [
                'type'        => 'basic',
                'description' => 'Use Nextcloud username:password via HTTP Basic Auth or session cookies.',
                'header'      => 'Authorization: Basic base64(user:pass)',
            ],
            'base_url'       => $this->getBaseUrl(),
            'capabilities'   => $capabilities,
        ];
    }//end getCatalog()

    /**
     * Get the list of valid capability IDs
     *
     * @return array<string> List of capability IDs
     */
    public function getCapabilityIds(): array
    {
        return [
            'registers',
            'schemas',
            'objects',
            'search',
            'files',
            'audit',
            'bulk',
            'webhooks',
            'chat',
            'views',
        ];
    }//end getCapabilityIds()

    /**
     * Get Tier 2 detail for a specific capability (authenticated)
     *
     * Returns detailed endpoint documentation and live context data.
     *
     * @param string $capability The capability ID
     *
     * @return array<string, mixed>|null The capability detail, or null if unknown
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     */
    public function getCapabilityDetail(string $capability): ?array
    {
        $builders = [
            'registers' => 'buildRegistersCapability',
            'schemas'   => 'buildSchemasCapability',
            'objects'   => 'buildObjectsCapability',
            'search'    => 'buildSearchCapability',
            'files'     => 'buildFilesCapability',
            'audit'     => 'buildAuditCapability',
            'bulk'      => 'buildBulkCapability',
            'webhooks'  => 'buildWebhooksCapability',
            'chat'      => 'buildChatCapability',
            'views'     => 'buildViewsCapability',
        ];

        if (isset($builders[$capability]) === false) {
            return null;
        }

        $method = $builders[$capability];
        return $this->$method();
    }//end getCapabilityDetail()

    /**
     * Build the registers capability detail
     *
     * @return array<string, mixed> Capability detail with endpoints and context
     */
    private function buildRegistersCapability(): array
    {
        $registers = $this->registerMapper->findAll();
        $context   = [];
        foreach ($registers as $register) {
            $context[] = [
                'id'    => $register->getId(),
                'title' => $register->getTitle(),
                'uuid'  => $register->getUuid(),
            ];
        }

        return [
            'id'          => 'registers',
            'name'        => 'Registers',
            'description' => 'Data containers that group schemas and their objects.',
            'context'     => ['registers' => $context],
            'endpoints'   => [
                [
                    'method'      => 'GET',
                    'path'        => '/api/registers',
                    'description' => 'List all registers. Supports pagination via _limit and _offset query params.',
                ],
                [
                    'method'      => 'GET',
                    'path'        => '/api/registers/{id}',
                    'description' => 'Get a single register by ID.',
                    'parameters'  => [
                        ['name' => 'id', 'in' => 'path', 'type' => 'integer', 'required' => true],
                    ],
                ],
                [
                    'method'      => 'POST',
                    'path'        => '/api/registers',
                    'description' => 'Create a new register.',
                    'body'        => 'JSON with title (required), description, schemas (array of schema IDs).',
                ],
                [
                    'method'      => 'PUT',
                    'path'        => '/api/registers/{id}',
                    'description' => 'Full update of a register.',
                ],
                [
                    'method'      => 'DELETE',
                    'path'        => '/api/registers/{id}',
                    'description' => 'Delete a register.',
                ],
                [
                    'method'      => 'GET',
                    'path'        => '/api/registers/{id}/schemas',
                    'description' => 'List schemas belonging to this register.',
                ],
                [
                    'method'      => 'GET',
                    'path'        => '/api/registers/{id}/export',
                    'description' => 'Export register with all schemas and objects.',
                ],
                [
                    'method'      => 'POST',
                    'path'        => '/api/registers/{id}/import',
                    'description' => 'Import data into a register.',
                ],
            ],
        ];
    }//end buildRegistersCapability()

    /**
     * Build the schemas capability detail
     *
     * @return array<string, mixed> Capability detail with endpoints and context
     */
    private function buildSchemasCapability(): array
    {
        $schemas = $this->schemaMapper->findAll();
        $context = [];
        foreach ($schemas as $schema) {
            $properties    = $schema->getProperties();
            $propertyCount = 0;
            if (is_array($properties) === true) {
                $propertyCount = count($properties);
            }

            $context[] = [
                'id'             => $schema->getId(),
                'title'          => $schema->getTitle(),
                'uuid'           => $schema->getUuid(),
                'property_count' => $propertyCount,
            ];
        }

        return [
            'id'          => 'schemas',
            'name'        => 'Schemas',
            'description' => 'JSON Schema definitions that define object structure.',
            'context'     => ['schemas' => $context],
            'endpoints'   => [
                [
                    'method'      => 'GET',
                    'path'        => '/api/schemas',
                    'description' => 'List all schemas.',
                ],
                [
                    'method'      => 'GET',
                    'path'        => '/api/schemas/{id}',
                    'description' => 'Get a single schema by ID. Returns full JSON Schema definition.',
                ],
                [
                    'method'      => 'POST',
                    'path'        => '/api/schemas',
                    'description' => 'Create a new schema. Body: JSON with title, description, properties.',
                ],
                [
                    'method'      => 'PUT',
                    'path'        => '/api/schemas/{id}',
                    'description' => 'Full update of a schema.',
                ],
                [
                    'method'      => 'DELETE',
                    'path'        => '/api/schemas/{id}',
                    'description' => 'Delete a schema.',
                ],
                [
                    'method'      => 'POST',
                    'path'        => '/api/schemas/upload',
                    'description' => 'Upload a JSON Schema file to create a schema.',
                ],
                [
                    'method'      => 'GET',
                    'path'        => '/api/schemas/{id}/download',
                    'description' => 'Download a schema as a JSON Schema file.',
                ],
            ],
        ];
    }//end buildSchemasCapability()

    /**
     * Build the objects capability detail
     *
     * @return array<string, mixed> Capability detail with endpoints and context
     */
    private function buildObjectsCapability(): array
    {
        $registers   = $this->registerMapper->findAll();
        $allSchemas  = $this->schemaMapper->findAll();
        $schemaIndex = [];
        foreach ($allSchemas as $schema) {
            $schemaIndex[$schema->getId()] = $schema;
        }

        $context = [];
        foreach ($registers as $register) {
            $regSchemas = [];
            $schemaIds  = $register->getSchemas();
            if (is_array($schemaIds) === true) {
                foreach ($schemaIds as $schemaId) {
                    if (isset($schemaIndex[$schemaId]) === true) {
                        $regSchemas[] = [
                            'id'    => $schemaIndex[$schemaId]->getId(),
                            'title' => $schemaIndex[$schemaId]->getTitle(),
                        ];
                    }
                }
            }

            $context[] = [
                'id'      => $register->getId(),
                'title'   => $register->getTitle(),
                'schemas' => $regSchemas,
            ];
        }

        return [
            'id'          => 'objects',
            'name'        => 'Objects',
            'description' => 'Data records stored in register/schema pairs.',
            'context'     => ['registers' => $context],
            'endpoints'   => [
                [
                    'method'      => 'GET',
                    'path'        => '/api/objects/{register}/{schema}',
                    'description' => 'List objects. Supports filtering and pagination.',
                    'parameters'  => [
                        ['name' => 'register', 'in' => 'path', 'type' => 'integer', 'required' => true, 'description' => 'Register ID'],
                        ['name' => 'schema', 'in' => 'path', 'type' => 'integer', 'required' => true, 'description' => 'Schema ID'],
                        ['name' => '_limit', 'in' => 'query', 'type' => 'integer', 'required' => false, 'description' => 'Max results (default 30)'],
                        ['name' => '_offset', 'in' => 'query', 'type' => 'integer', 'required' => false, 'description' => 'Skip N results'],
                        ['name' => '_search', 'in' => 'query', 'type' => 'string', 'required' => false, 'description' => 'Full-text search'],
                        [
                            'name'        => '_order[field]',
                            'in'          => 'query',
                            'type'        => 'string',
                            'required'    => false,
                            'description' => 'Sort by field (asc/desc)',
                        ],
                        [
                            'name'        => 'field.subfield',
                            'in'          => 'query',
                            'type'        => 'string',
                            'required'    => false,
                            'description' => 'Dot-notation filter on object properties',
                        ],
                    ],
                ],
                [
                    'method'      => 'POST',
                    'path'        => '/api/objects/{register}/{schema}',
                    'description' => 'Create a new object. Body: JSON matching the schema definition.',
                ],
                [
                    'method'      => 'GET',
                    'path'        => '/api/objects/{register}/{schema}/{id}',
                    'description' => 'Get a single object by ID.',
                ],
                [
                    'method'      => 'PUT',
                    'path'        => '/api/objects/{register}/{schema}/{id}',
                    'description' => 'Full update of an object.',
                ],
                [
                    'method'      => 'PATCH',
                    'path'        => '/api/objects/{register}/{schema}/{id}',
                    'description' => 'Partial update of an object.',
                ],
                [
                    'method'      => 'DELETE',
                    'path'        => '/api/objects/{register}/{schema}/{id}',
                    'description' => 'Soft-delete an object (restorable from /api/deleted).',
                ],
                [
                    'method'      => 'POST',
                    'path'        => '/api/objects/{register}/{schema}/{id}/lock',
                    'description' => 'Lock an object to prevent edits.',
                ],
                [
                    'method'      => 'POST',
                    'path'        => '/api/objects/{register}/{schema}/{id}/unlock',
                    'description' => 'Unlock a locked object.',
                ],
            ],
        ];
    }//end buildObjectsCapability()

    /**
     * Build the search capability detail
     *
     * @return array<string, mixed> Capability detail with endpoints and context
     */
    private function buildSearchCapability(): array
    {
        return [
            'id'          => 'search',
            'name'        => 'Search',
            'description' => 'Full-text, semantic, and hybrid search across objects and files.',
            'context'     => [],
            'endpoints'   => [
                [
                    'method'      => 'GET',
                    'path'        => '/api/search',
                    'description' => 'Search across all objects. Supports _search, register, schema, and facet filters.',
                    'parameters'  => [
                        ['name' => '_search', 'in' => 'query', 'type' => 'string', 'required' => true, 'description' => 'Search query'],
                        ['name' => 'register', 'in' => 'query', 'type' => 'integer', 'required' => false, 'description' => 'Filter by register ID'],
                        ['name' => 'schema', 'in' => 'query', 'type' => 'integer', 'required' => false, 'description' => 'Filter by schema ID'],
                    ],
                ],
                [
                    'method'      => 'POST',
                    'path'        => '/api/search/semantic',
                    'description' => 'Vector-based semantic search. Body: { "query": "natural language query" }.',
                ],
                [
                    'method'      => 'POST',
                    'path'        => '/api/search/hybrid',
                    'description' => 'Combined keyword + semantic search. Body: { "query": "search text" }.',
                ],
                [
                    'method'      => 'POST',
                    'path'        => '/api/search/files/keyword',
                    'description' => 'Keyword search over file contents.',
                ],
                [
                    'method'      => 'POST',
                    'path'        => '/api/search/files/semantic',
                    'description' => 'Semantic search over file contents.',
                ],
                [
                    'method'      => 'POST',
                    'path'        => '/api/search/files/hybrid',
                    'description' => 'Hybrid search over file contents.',
                ],
            ],
        ];
    }//end buildSearchCapability()

    /**
     * Build the files capability detail
     *
     * @return array<string, mixed> Capability detail with endpoints and context
     */
    private function buildFilesCapability(): array
    {
        return [
            'id'          => 'files',
            'name'        => 'Files',
            'description' => 'File attachments on objects. Upload, download, text extraction, anonymization.',
            'context'     => [],
            'endpoints'   => [
                [
                    'method'      => 'GET',
                    'path'        => '/api/objects/{register}/{schema}/{id}/files',
                    'description' => 'List files attached to an object.',
                ],
                [
                    'method'      => 'POST',
                    'path'        => '/api/objects/{register}/{schema}/{id}/files',
                    'description' => 'Upload a file to an object. Use multipart/form-data.',
                ],
                [
                    'method'      => 'GET',
                    'path'        => '/api/objects/{register}/{schema}/{id}/files/{fileId}',
                    'description' => 'Get file metadata.',
                ],
                [
                    'method'      => 'GET',
                    'path'        => '/api/files/{fileId}/download',
                    'description' => 'Download a file by ID.',
                ],
                [
                    'method'      => 'DELETE',
                    'path'        => '/api/objects/{register}/{schema}/{id}/files/{fileId}',
                    'description' => 'Delete a file from an object.',
                ],
                [
                    'method'      => 'GET',
                    'path'        => '/api/files/{fileId}/text',
                    'description' => 'Get extracted text content from a file.',
                ],
                [
                    'method'      => 'POST',
                    'path'        => '/api/files/{fileId}/extract',
                    'description' => 'Extract text from a file (triggers OCR/parsing).',
                ],
            ],
        ];
    }//end buildFilesCapability()

    /**
     * Build the audit capability detail
     *
     * @return array<string, mixed> Capability detail with endpoints and context
     */
    private function buildAuditCapability(): array
    {
        return [
            'id'          => 'audit',
            'name'        => 'Audit Trails',
            'description' => 'Change history for objects.',
            'context'     => [],
            'endpoints'   => [
                [
                    'method'      => 'GET',
                    'path'        => '/api/audit-trails',
                    'description' => 'List all audit trail entries. Supports pagination.',
                ],
                [
                    'method'      => 'GET',
                    'path'        => '/api/objects/{register}/{schema}/{id}/audit-trails',
                    'description' => 'Get audit trail for a specific object.',
                ],
                [
                    'method'      => 'GET',
                    'path'        => '/api/audit-trails/{id}',
                    'description' => 'Get a single audit trail entry.',
                ],
                [
                    'method'      => 'GET',
                    'path'        => '/api/audit-trails/export',
                    'description' => 'Export audit trails as CSV.',
                ],
            ],
        ];
    }//end buildAuditCapability()

    /**
     * Build the bulk operations capability detail
     *
     * @return array<string, mixed> Capability detail with endpoints and context
     */
    private function buildBulkCapability(): array
    {
        return [
            'id'          => 'bulk',
            'name'        => 'Bulk Operations',
            'description' => 'Batch operations on objects within a register/schema pair.',
            'context'     => [],
            'endpoints'   => [
                [
                    'method'      => 'POST',
                    'path'        => '/api/bulk/{register}/{schema}/save',
                    'description' => 'Batch save/upsert objects. Body: array of objects.',
                ],
                [
                    'method'      => 'POST',
                    'path'        => '/api/bulk/{register}/{schema}/delete',
                    'description' => 'Batch delete objects. Body: { "ids": [...] }.',
                ],
                [
                    'method'      => 'POST',
                    'path'        => '/api/bulk/{register}/{schema}/publish',
                    'description' => 'Batch publish objects. Body: { "ids": [...] }.',
                ],
                [
                    'method'      => 'POST',
                    'path'        => '/api/bulk/{register}/{schema}/depublish',
                    'description' => 'Batch depublish objects. Body: { "ids": [...] }.',
                ],
            ],
        ];
    }//end buildBulkCapability()

    /**
     * Build the webhooks capability detail
     *
     * @return array<string, mixed> Capability detail with endpoints and context
     */
    private function buildWebhooksCapability(): array
    {
        return [
            'id'          => 'webhooks',
            'name'        => 'Webhooks',
            'description' => 'Event-driven HTTP callbacks triggered by object changes.',
            'context'     => [],
            'endpoints'   => [
                [
                    'method'      => 'GET',
                    'path'        => '/api/webhooks',
                    'description' => 'List all webhooks.',
                ],
                [
                    'method'      => 'POST',
                    'path'        => '/api/webhooks',
                    'description' => 'Create a webhook. Body: { "url": "...", "events": [...], "headers": {...} }.',
                ],
                [
                    'method'      => 'GET',
                    'path'        => '/api/webhooks/{id}',
                    'description' => 'Get a webhook by ID.',
                ],
                [
                    'method'      => 'PUT',
                    'path'        => '/api/webhooks/{id}',
                    'description' => 'Update a webhook.',
                ],
                [
                    'method'      => 'DELETE',
                    'path'        => '/api/webhooks/{id}',
                    'description' => 'Delete a webhook.',
                ],
                [
                    'method'      => 'POST',
                    'path'        => '/api/webhooks/{id}/test',
                    'description' => 'Send a test payload to the webhook URL.',
                ],
                [
                    'method'      => 'GET',
                    'path'        => '/api/webhooks/events',
                    'description' => 'List available webhook event types.',
                ],
            ],
        ];
    }//end buildWebhooksCapability()

    /**
     * Build the chat capability detail
     *
     * @return array<string, mixed> Capability detail with endpoints and context
     */
    private function buildChatCapability(): array
    {
        return [
            'id'          => 'chat',
            'name'        => 'AI Chat',
            'description' => 'Conversational AI assistant for querying register data.',
            'context'     => [],
            'endpoints'   => [
                [
                    'method'      => 'POST',
                    'path'        => '/api/chat/send',
                    'description' => 'Send a message to the AI assistant. Body: { "message": "your question" }.',
                ],
                [
                    'method'      => 'GET',
                    'path'        => '/api/chat/history',
                    'description' => 'Get chat message history.',
                ],
                [
                    'method'      => 'DELETE',
                    'path'        => '/api/chat/history',
                    'description' => 'Clear chat history.',
                ],
                [
                    'method'      => 'GET',
                    'path'        => '/api/conversations',
                    'description' => 'List all conversations.',
                ],
                [
                    'method'      => 'POST',
                    'path'        => '/api/conversations',
                    'description' => 'Start a new conversation.',
                ],
                [
                    'method'      => 'GET',
                    'path'        => '/api/conversations/{uuid}/messages',
                    'description' => 'Get messages in a conversation.',
                ],
            ],
        ];
    }//end buildChatCapability()

    /**
     * Build the views capability detail
     *
     * @return array<string, mixed> Capability detail with endpoints and context
     */
    private function buildViewsCapability(): array
    {
        return [
            'id'          => 'views',
            'name'        => 'Views',
            'description' => 'Saved search/filter configurations for reusable data views.',
            'context'     => [],
            'endpoints'   => [
                [
                    'method'      => 'GET',
                    'path'        => '/api/views',
                    'description' => 'List all views.',
                ],
                [
                    'method'      => 'GET',
                    'path'        => '/api/views/{id}',
                    'description' => 'Get a view by ID.',
                ],
                [
                    'method'      => 'POST',
                    'path'        => '/api/views',
                    'description' => 'Create a view. Body: { "name": "...", "filters": {...}, "register": id, "schema": id }.',
                ],
                [
                    'method'      => 'PUT',
                    'path'        => '/api/views/{id}',
                    'description' => 'Update a view.',
                ],
                [
                    'method'      => 'DELETE',
                    'path'        => '/api/views/{id}',
                    'description' => 'Delete a view.',
                ],
            ],
        ];
    }//end buildViewsCapability()
}//end class
