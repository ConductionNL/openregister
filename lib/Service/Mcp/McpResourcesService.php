<?php

/**
 * MCP Resources Service
 *
 * Handles MCP standard resource listing, reading, and URI template
 * operations for the OpenRegister MCP server.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Mcp
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Mcp;

use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Db\DoesNotExistException;
use Psr\Log\LoggerInterface;

/**
 * McpResourcesService handles MCP resource operations
 *
 * Provides resource listing, reading, and URI template support
 * for the OpenRegister MCP server. Resources use the openregister://
 * URI scheme.
 *
 * @psalm-suppress UnusedClass - Injected via DI container
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class McpResourcesService
{

    /**
     * McpResourcesService constructor
     *
     * @param RegisterMapper  $registerMapper Register database mapper
     * @param SchemaMapper    $schemaMapper   Schema database mapper
     * @param ObjectService   $objectService  Object service facade
     * @param LoggerInterface $logger         Logger
     */
    public function __construct(
        private readonly RegisterMapper $registerMapper,
        private readonly SchemaMapper $schemaMapper,
        private readonly ObjectService $objectService,
        private readonly LoggerInterface $logger
    ) {
    }//end __construct()

    /**
     * List available MCP resources
     *
     * Returns static resource entries for registers, schemas,
     * and one entry per register+schema pair for objects.
     *
     * @return array{resources: array} MCP resources/list response
     */
    public function listResources(): array
    {
        $resources = [
            [
                'uri'         => 'openregister://registers',
                'name'        => 'All Registers',
                'description' => 'List of all registers (data containers)',
                'mimeType'    => 'application/json',
            ],
            [
                'uri'         => 'openregister://schemas',
                'name'        => 'All Schemas',
                'description' => 'List of all schemas (data definitions)',
                'mimeType'    => 'application/json',
            ],
        ];

        // Add one resource per register+schema combination for browsing objects.
        try {
            $registers = $this->registerMapper->findAll();
            foreach ($registers as $register) {
                $schemaIds = $register->getSchemas() ?? [];
                foreach ($schemaIds as $schemaId) {
                    try {
                        $schema = $this->schemaMapper->find($schemaId);
                        $resources[] = [
                            'uri'         => 'openregister://objects/'
                                .$register->getId().'/'.$schema->getId(),
                            'name'        => $register->getTitle().' — '.$schema->getTitle(),
                            'description' => 'Objects in register "'.$register->getTitle()
                                .'" with schema "'.$schema->getTitle().'"',
                            'mimeType'    => 'application/json',
                        ];
                    } catch (DoesNotExistException $e) {
                        // Schema may have been deleted; skip.
                        continue;
                    }
                }
            }
        } catch (\Exception $e) {
            $this->logger->warning(
                message: '[MCP] Failed to list register/schema pairs for resources',
                context: ['error' => $e->getMessage()]
            );
        }//end try

        return ['resources' => $resources];
    }//end listResources()

    /**
     * List MCP resource URI templates
     *
     * Returns URI templates that clients can use to construct
     * resource URIs for specific items.
     *
     * @return array{resourceTemplates: array} MCP resources/templates/list response
     */
    public function listTemplates(): array
    {
        return [
            'resourceTemplates' => [
                [
                    'uriTemplate' => 'openregister://registers/{id}',
                    'name'        => 'Register by ID',
                    'description' => 'Get a single register by its numeric ID',
                    'mimeType'    => 'application/json',
                ],
                [
                    'uriTemplate' => 'openregister://schemas/{id}',
                    'name'        => 'Schema by ID',
                    'description' => 'Get a single schema by its numeric ID',
                    'mimeType'    => 'application/json',
                ],
                [
                    'uriTemplate' => 'openregister://objects/{register}/{schema}/{id}',
                    'name'        => 'Object by ID',
                    'description' => 'Get a single object by register ID, schema ID, and object UUID',
                    'mimeType'    => 'application/json',
                ],
            ],
        ];
    }//end listTemplates()

    /**
     * Read an MCP resource by URI
     *
     * Parses the openregister:// URI and fetches the corresponding data
     * from the database.
     *
     * @param string $uri Resource URI (openregister:// scheme)
     *
     * @return array{contents: array} MCP resources/read response
     *
     * @throws \InvalidArgumentException If URI is invalid or unsupported
     */
    public function readResource(string $uri): array
    {
        $parsed = $this->parseUri(uri: $uri);

        $data = match ($parsed['type']) {
            'registers'     => $this->readRegisters(id: $parsed['id'] ?? null),
            'schemas'       => $this->readSchemas(id: $parsed['id'] ?? null),
            'objects'       => $this->readObjects(
                registerId: $parsed['registerId'],
                schemaId: $parsed['schemaId'],
                objectId: $parsed['objectId'] ?? null
            ),
            default         => throw new \InvalidArgumentException(
                message: 'Unsupported resource type: '.$parsed['type']
            ),
        };

        return [
            'contents' => [
                [
                    'uri'      => $uri,
                    'mimeType' => 'application/json',
                    'text'     => json_encode(value: $data, flags: JSON_PRETTY_PRINT),
                ],
            ],
        ];
    }//end readResource()

    /**
     * Parse an openregister:// URI into components
     *
     * Supported patterns:
     * - openregister://registers
     * - openregister://registers/{id}
     * - openregister://schemas
     * - openregister://schemas/{id}
     * - openregister://objects/{registerId}/{schemaId}
     * - openregister://objects/{registerId}/{schemaId}/{objectId}
     *
     * @param string $uri The URI to parse
     *
     * @return array Parsed URI components
     *
     * @throws \InvalidArgumentException If URI format is invalid
     */
    private function parseUri(string $uri): array
    {
        if (str_starts_with(haystack: $uri, needle: 'openregister://') === false) {
            throw new \InvalidArgumentException(
                message: 'Invalid URI scheme, expected openregister://'
            );
        }

        $path = substr(string: $uri, offset: strlen('openregister://'));
        $segments = explode(separator: '/', string: $path);

        $type = $segments[0] ?? '';

        if ($type === 'registers' || $type === 'schemas') {
            return [
                'type' => $type,
                'id'   => isset($segments[1]) ? (int) $segments[1] : null,
            ];
        }

        if ($type === 'objects') {
            if (isset($segments[1], $segments[2]) === false) {
                throw new \InvalidArgumentException(
                    message: 'Objects URI requires register and schema IDs: '
                        .'openregister://objects/{registerId}/{schemaId}'
                );
            }

            return [
                'type'       => 'objects',
                'registerId' => (int) $segments[1],
                'schemaId'   => (int) $segments[2],
                'objectId'   => $segments[3] ?? null,
            ];
        }

        throw new \InvalidArgumentException(
            message: 'Unknown resource type: '.$type
        );
    }//end parseUri()

    /**
     * Read register data
     *
     * @param int|null $id Optional register ID for single fetch
     *
     * @return array Register data (single or list)
     */
    private function readRegisters(?int $id = null): array
    {
        if ($id !== null) {
            $register = $this->registerMapper->find($id);
            return $register->jsonSerialize();
        }

        $registers = $this->registerMapper->findAll();
        return array_map(
            callback: static fn($r) => $r->jsonSerialize(),
            array: $registers
        );
    }//end readRegisters()

    /**
     * Read schema data
     *
     * @param int|null $id Optional schema ID for single fetch
     *
     * @return array Schema data (single or list)
     */
    private function readSchemas(?int $id = null): array
    {
        if ($id !== null) {
            $schema = $this->schemaMapper->find($id);
            return $schema->jsonSerialize();
        }

        $schemas = $this->schemaMapper->findAll();
        return array_map(
            callback: static fn($s) => $s->jsonSerialize(),
            array: $schemas
        );
    }//end readSchemas()

    /**
     * Read object data
     *
     * @param int         $registerId Register ID
     * @param int         $schemaId   Schema ID
     * @param string|null $objectId   Optional object UUID for single fetch
     *
     * @return array Object data (single or list)
     */
    private function readObjects(int $registerId, int $schemaId, ?string $objectId = null): array
    {
        $this->objectService->setRegister($registerId);
        $this->objectService->setSchema($schemaId);

        if ($objectId !== null) {
            $object = $this->objectService->find($objectId);
            return $object->jsonSerialize();
        }

        $result = $this->objectService->findAll();
        return array_map(
            callback: static fn($o) => $o->jsonSerialize(),
            array: $result
        );
    }//end readObjects()
}//end class
