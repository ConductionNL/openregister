<?php

/**
 * MCP Resources Service
 *
 * Handles MCP standard resource listing, reading, and URI template
 * operations for the OpenRegister MCP server.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
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

namespace OCA\OpenRegister\Mcp\Service\Mcp;

use Generator;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaCatalogInterface;
use OCA\OpenRegister\Kernel\Contracts\RegisterSchemaRef;
use OCA\OpenRegister\Service\Rbac\ExplicitRevealMasks;
use InvalidArgumentException;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCA\OpenRegister\Service\WriteCause;
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
class McpResourcesService {
	/**
	 * The resources listed whatever registers exist: all registers and all schemas.
	 *
	 * @var list<array<string, string>>
	 */
	private const STATIC_RESOURCES = [
		[
			'uri' => 'openregister://registers',
			'name' => 'All Registers',
			'description' => 'List of all registers (data containers)',
			'mimeType' => 'application/json',
		],
		[
			'uri' => 'openregister://schemas',
			'name' => 'All Schemas',
			'description' => 'List of all schemas (data definitions)',
			'mimeType' => 'application/json',
		],
	];

	/**
	 * McpResourcesService constructor
	 *
	 * @param SchemaCatalogInterface $catalog The register and schema mappers
	 * @param ObjectOperationsInterface $objectService Object service facade
	 * @param LoggerInterface $logger Logger
	 */
	public function __construct(
		private readonly SchemaCatalogInterface $catalog,
		private readonly ObjectOperationsInterface $objectService,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * List available MCP resources
	 *
	 * Returns static resource entries for registers, schemas,
	 * and one entry per register+schema pair for objects.
	 *
	 * @return array{resources: array} MCP resources/list response
	 *
	 * @spec openspec/changes/retrofit-2026-05-25-bw-svc-mid2/tasks.md#task-5
	 */
	public function listResources(): array {
		$resources = self::STATIC_RESOURCES;

		// Add one resource per register+schema combination for browsing objects.
		try {
			foreach ($this->registerSchemaPairs() as [$register, $schemaId]) {
				$resource = $this->pairResource(register: $register, schemaId: $schemaId);
				if ($resource !== null) {
					$resources[] = $resource;
				}
			}
		} catch (\Exception $e) {
			$this->logPairFailure(error: $e);
		}

		return ['resources' => $resources];
	}//end listResources()

	/**
	 * Every register with each of its schema ids, read lazily so a failure keeps the pairs before it.
	 *
	 * @return Generator<int, array{0: Register, 1: int|string}>
	 */
	private function registerSchemaPairs(): Generator {
		foreach ($this->catalog->registers()->findAll() as $register) {
			$schemaIds = $register->getSchemas() ?? [];
			foreach ($schemaIds as $schemaId) {
				yield [$register, $schemaId];
			}
		}
	}//end registerSchemaPairs()

	/**
	 * The objects resource of one register+schema pair; null when the schema was deleted.
	 *
	 * @param Register   $register The register.
	 * @param int|string $schemaId The schema id.
	 *
	 * @return array<string, string>|null
	 */
	private function pairResource(Register $register, int|string $schemaId): ?array {
		try {
			return $this->objectsResource(register: $register, schema: $this->catalog->schemas()->find($schemaId));
		} catch (DoesNotExistException $e) {
			// Schema may have been deleted; skip.
			return null;
		}
	}//end pairResource()

	/**
	 * The resource entry for browsing the objects of a register+schema pair.
	 *
	 * @param Register $register The register.
	 * @param Schema   $schema   The schema.
	 *
	 * @return array<string, string>
	 */
	private function objectsResource(Register $register, Schema $schema): array {
		$regTitle = $register->getTitle();
		$schTitle = $schema->getTitle();
		return [
			'uri' => 'openregister://objects/' . $register->getId() . '/' . $schema->getId(),
			'name' => "$regTitle — $schTitle",
			'description' => "Objects in register \"{$regTitle}\" with schema \"{$schTitle}\"",
			'mimeType' => 'application/json',
		];
	}//end objectsResource()

	/**
	 * Log that the register/schema pairs could not all be listed.
	 *
	 * @param \Exception $error The failure.
	 *
	 * @return void
	 */
	private function logPairFailure(\Exception $error): void {
		$this->logger->warning(
			message: '[MCP] Failed to list register/schema pairs for resources',
			context: ['error' => $error->getMessage()]
		);
	}//end logPairFailure()

	/**
	 * List MCP resource URI templates
	 *
	 * Returns URI templates that clients can use to construct
	 * resource URIs for specific items.
	 *
	 * @return array{resourceTemplates: array} MCP resources/templates/list response
	 *
	 * @spec openspec/changes/retrofit-2026-05-25-bw-svc-mid2/tasks.md#task-5
	 */
	public function listTemplates(): array {
		return [
			'resourceTemplates' => [
				[
					'uriTemplate' => 'openregister://registers/{id}',
					'name' => 'Register by ID',
					'description' => 'Get a single register by its numeric ID',
					'mimeType' => 'application/json',
				],
				[
					'uriTemplate' => 'openregister://schemas/{id}',
					'name' => 'Schema by ID',
					'description' => 'Get a single schema by its numeric ID',
					'mimeType' => 'application/json',
				],
				[
					'uriTemplate' => 'openregister://objects/{register}/{schema}/{id}',
					'name' => 'Object by ID',
					'description' => 'Get a single object by register ID, schema ID, and object UUID',
					'mimeType' => 'application/json',
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
	 * @throws InvalidArgumentException If URI is invalid or unsupported
	 *
	 * @spec openspec/changes/retrofit-2026-05-25-bw-svc-mid2/tasks.md#task-5
	 */
	public function readResource(string $uri): array {
		$parsed = $this->parseUri(uri: $uri);

		$data = match ($parsed['type']) {
			'registers' => $this->readRegisters(id: $parsed['id'] ?? null),
			'schemas' => $this->readSchemas(id: $parsed['id'] ?? null),
			'objects' => $this->readObjects(
				table: new RegisterSchemaRef(register: $parsed['registerId'], schema: $parsed['schemaId']),
				objectId: $parsed['objectId'] ?? null
			),
			default => throw new InvalidArgumentException(
				message: 'Unsupported resource type: ' . $parsed['type']
			),
		};

		return [
			'contents' => [
				[
					'uri' => $uri,
					'mimeType' => 'application/json',
					'text' => json_encode(value: $data, flags: JSON_PRETTY_PRINT),
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
	 * @throws InvalidArgumentException If URI format is invalid
	 */
	private function parseUri(string $uri): array {
		$segments = $this->uriSegments(uri: $uri);
		$type = $segments[0] ?? '';

		if ($type === 'registers' || $type === 'schemas') {
			return [
				'type' => $type,
				'id' => $this->itemId(segments: $segments),
			];
		}

		if ($type === 'objects') {
			return $this->objectsUri(segments: $segments);
		}

		throw new InvalidArgumentException(
			message: 'Unknown resource type: ' . $type
		);
	}//end parseUri()

	/**
	 * The path segments of an openregister:// URI.
	 *
	 * @param string $uri The URI to parse
	 *
	 * @return list<string>
	 *
	 * @throws InvalidArgumentException If the URI is not under the openregister:// scheme
	 */
	private function uriSegments(string $uri): array {
		if (str_starts_with(haystack: $uri, needle: 'openregister://') === false) {
			throw new InvalidArgumentException(
				message: 'Invalid URI scheme, expected openregister://'
			);
		}

		$path = substr(string: $uri, offset: strlen('openregister://'));
		return explode(separator: '/', string: $path);
	}//end uriSegments()

	/**
	 * The optional numeric id after a registers or schemas segment.
	 *
	 * @param list<string> $segments The URI path segments.
	 *
	 * @return int|null
	 */
	private function itemId(array $segments): ?int {
		$id = null;
		if (isset($segments[1]) === true) {
			$id = (int)$segments[1];
		}

		return $id;
	}//end itemId()

	/**
	 * The register, schema and optional object id of an objects URI.
	 *
	 * @param list<string> $segments The URI path segments.
	 *
	 * @return array{type: string, registerId: int, schemaId: int, objectId: string|null}
	 *
	 * @throws InvalidArgumentException If the register or schema id is missing
	 */
	private function objectsUri(array $segments): array {
		if (isset($segments[1], $segments[2]) === false) {
			throw new InvalidArgumentException(
				message: 'Objects URI requires register and schema IDs: openregister://objects/{registerId}/{schemaId}'
			);
		}

		return [
			'type' => 'objects',
			'registerId' => (int)$segments[1],
			'schemaId' => (int)$segments[2],
			'objectId' => $segments[3] ?? null,
		];
	}//end objectsUri()

	/**
	 * Read register data
	 *
	 * @param int|null $id Optional register ID for single fetch
	 *
	 * @return array Register data (single or list)
	 */
	private function readRegisters(?int $id = null): array {
		if ($id !== null) {
			$register = $this->catalog->registers()->find($id);
			return $register->jsonSerialize();
		}

		$registers = $this->catalog->registers()->findAll();
		return array_map(
			callback: static fn ($r) => $r->jsonSerialize(),
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
	private function readSchemas(?int $id = null): array {
		if ($id !== null) {
			$schema = $this->catalog->schemas()->find($id);
			return $schema->jsonSerialize();
		}

		$schemas = $this->catalog->schemas()->findAll();
		return array_map(
			callback: static fn ($schema) => $schema->jsonSerialize(),
			array: $schemas
		);
	}//end readSchemas()

	/**
	 * Read object data
	 *
	 * @param RegisterSchemaRef $table    The register and schema IDs the URI names.
	 * @param string|null       $objectId Optional object UUID for single fetch
	 *
	 * @return array Object data (single or list)
	 * @spec openspec/specs/object-interactions/spec.md#requirement-only-a-person-opening-an-object-counts-as-recently-opened
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) WriteCause::asLookup() is the ambient audit-cause frame; there is no instance to inject;
	 * ExplicitRevealMasks::maskAll() is a pure function.
	 */
	private function readObjects(RegisterSchemaRef $table, ?string $objectId = null): array {
		$this->objectService->setRegister($table->register);
		$this->objectService->setSchema($table->schema);

		// An MCP resource never reveals an explicit-reveal value: it reads masked.
		$schema = $this->catalog->schemas()->find($table->schema);

		if ($objectId !== null) {
			$object = WriteCause::asLookup(fn () => $this->objectService->find($objectId));
			return ExplicitRevealMasks::maskAll(schema: $schema, object: $object->jsonSerialize());
		}

		$result = $this->objectService->findAll();
		return array_map(
			callback: static fn ($obj) => ExplicitRevealMasks::maskAll(schema: $schema, object: $obj->jsonSerialize()),
			array: $result
		);
	}//end readObjects()
}//end class
