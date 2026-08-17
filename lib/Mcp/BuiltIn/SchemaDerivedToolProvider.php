<?php

/**
 * Schema-derived MCP tool provider (ADR-063 chain 2/3).
 *
 * Reads every schema's validated `x-openregister-mcp` block (the
 * `or-mcp-schema-dialect` chain-1 dialect) and, for each `enabled:true`
 * schema, emits one coarse-CRUD MCP tool per declared verb — turning a
 * declarative opt-in into live tools on both MCP serving surfaces
 * (`McpToolsService` JSON-RPC + `ToolRegistry`/`McpProviderBridge` chat)
 * with zero hand-written leaf-app code.
 *
 * One instance is registered per OWNING app (not per OpenRegister) so every
 * emitted tool id `{appId}.{schema}.{verb}` satisfies the existing
 * IMcpToolProvider ABI's "id prefix == getAppId()" invariant unchanged —
 * see design.md "`{appId}` in the id vs `getAppId()`". Application.php
 * builds one `SchemaDerivedToolProvider` per app that has at least one
 * opted-in schema and appends it AFTER that app's hand-written provider(s),
 * passing the set of tool ids the hand-written provider(s) already claim so
 * this provider can self-suppress the colliding derived duplicate
 * (hand-written > derived precedence, REQ-DERIVED-003).
 *
 * Writes go through `ObjectService` in the caller's ambient Nextcloud
 * session — no impersonation, no system account, RBAC/IDOR unchanged from
 * the REST path (REQ-DERIVED-005). Every invocation — read, write, or
 * failed — writes exactly one immutable, hash-chained audit record via
 * `AuditTrailMapper::createToolInvocationEntry()` (REQ-DERIVED-006).
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Mcp
 * @package  OCA\OpenRegister\Mcp\BuiltIn
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction BV
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/ai-mcp/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Mcp\BuiltIn;

use InvalidArgumentException;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Mcp\IMcpToolProvider;
use OCA\OpenRegister\Service\Mcp\McpAnnotationValidator;
use OCA\OpenRegister\Service\ObjectService;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * SchemaDerivedToolProvider
 *
 * Built-in IMcpToolProvider that derives coarse-CRUD tools from the
 * `x-openregister-mcp` schema dialect. One instance per owning app.
 *
 * @category Mcp
 * @package  OCA\OpenRegister\Mcp\BuiltIn
 *
 * @psalm-suppress UnusedClass - Instantiated by Application::registerMcpToolProviders()
 *
 * @spec openspec/specs/ai-mcp/spec.md
 *   (Requirement: REQ-DERIVED-001 — SchemaDerivedToolProvider emits declarative CRUD tools)
 */
class SchemaDerivedToolProvider implements IMcpToolProvider {

	/**
	 * Default `search` page size when the caller omits `pageSize`.
	 */
	private const DEFAULT_PAGE_SIZE = 20;

	/**
	 * Hard maximum `search` page size — a caller-supplied `pageSize` above
	 * this is clamped, never rejected.
	 */
	private const MAX_PAGE_SIZE = 100;

	/**
	 * String values longer than this are truncated (with an ellipsis
	 * marker) in `search` results to keep token cost sane.
	 */
	private const TRUNCATE_LENGTH = 500;

	/**
	 * Constructor.
	 *
	 * `$schemaEntries` is a `list<array{schema: Schema, register: Register|null}>`
	 * — that app's opted-in schemas, each paired with its owning register
	 * (when resolvable). `$suppressedIds` is a `list<string>` of tool ids
	 * already claimed by a hand-written provider for this app; the derived
	 * duplicates are self-suppressed (REQ-DERIVED-003).
	 *
	 * @param string $appId The owning app id; `getAppId()` and every id prefix.
	 * @param array $schemaEntries The app's opted-in schema entries (see above).
	 * @param array $suppressedIds Hand-written tool ids to self-suppress (see above).
	 * @param ObjectService $objectService RBAC-enforcing object read/write facade.
	 * @param AuditTrailMapper $auditTrailMapper Immutable hash-chained audit-trail writer.
	 * @param LoggerInterface $logger PSR logger.
	 */
	public function __construct(
		private readonly string $appId,
		private readonly array $schemaEntries,
		private readonly array $suppressedIds,
		private readonly ObjectService $objectService,
		private readonly AuditTrailMapper $auditTrailMapper,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Returns the owning app id.
	 *
	 * @return string The owning app id this instance was constructed for.
	 *
	 * @spec openspec/specs/ai-mcp/spec.md
	 *   (Requirement: REQ-DERIVED-001 — one derived provider instance per owning app,
	 *   getAppId() returning that owning app id so every emitted tool id keeps the
	 *   IMcpToolProvider ABI's "id prefix == getAppId()" invariant)
	 */
	public function getAppId(): string {
		return $this->appId;
	}//end getAppId()

	/**
	 * Returns one tool descriptor per declared verb of every opted-in schema.
	 *
	 * Each descriptor additionally carries the verb's declared MCP annotation
	 * hints and `scope` when the dialect set them (omitted when it didn't —
	 * no defaults are invented). Both are ADVISORY metadata for the LLM/UX
	 * only; OpenRegister RBAC via `ObjectService` remains the authoritative
	 * invoke-time gate (REQ-DERIVED-005, ADR-063).
	 *
	 * @return list<array{id: string, name: string, description: string, inputSchema: array,
	 *         outputSchema?: array, readOnlyHint?: bool, destructiveHint?: bool,
	 *         idempotentHint?: bool, scope?: string}>
	 *
	 * @spec openspec/specs/ai-mcp/spec.md
	 *   (Requirement: REQ-DERIVED-001 — SchemaDerivedToolProvider emits declarative CRUD tools)
	 */
	public function getTools(): array {
		$descriptors = [];

		foreach ($this->schemaEntries as $entry) {
			$schema = $entry['schema'];
			$annotation = $this->mcpAnnotation(schema: $schema);
			if (($annotation['enabled'] ?? false) !== true) {
				continue;
			}

			$slug = $this->schemaSlug(schema: $schema);
			$toolsConfig = ($annotation['tools'] ?? null);
			$verbs = McpAnnotationValidator::VERBS;
			if (is_array($toolsConfig) === true) {
				$verbs = array_values(array_intersect(McpAnnotationValidator::VERBS, array_keys($toolsConfig)));
			}

			foreach ($verbs as $verb) {
				$id = $this->appId . '.' . $slug . '.' . $verb;

				// Self-suppression (REQ-DERIVED-003): a hand-written per-app
				// provider already claims this id — omit the derived
				// duplicate entirely rather than merely shadowing it.
				if (in_array($id, $this->suppressedIds, true) === true) {
					continue;
				}

				$verbConfig = [];
				if (is_array($toolsConfig) === true && is_array($toolsConfig[$verb] ?? null) === true) {
					$verbConfig = $toolsConfig[$verb];
				}

				$descriptors[] = $this->buildDescriptor(
					schema: $schema,
					slug: $slug,
					verb: $verb,
					verbConfig: $verbConfig,
					id: $id
				);
			}//end foreach
		}//end foreach

		return $descriptors;
	}//end getTools()

	/**
	 * Dispatch a tool invocation by its parsed `{appId}.{schema}.{verb}` id.
	 *
	 * Writes exactly one immutable audit record per invocation — success or
	 * failure — before returning (or rethrowing).
	 *
	 * @param string $toolId Namespaced tool id, e.g. "pipelinq.lead.search".
	 * @param array<string, mixed> $arguments JSON-decoded tool arguments.
	 *
	 * @return array<string, mixed> JSON-encodable result.
	 *
	 * @throws InvalidArgumentException If the tool id is unknown or required params are missing.
	 *
	 * @spec openspec/specs/ai-mcp/spec.md
	 *   (Requirement: REQ-DERIVED-005 — Writes go through ObjectService with RBAC intact)
	 * @spec openspec/specs/ai-mcp/spec.md
	 *   (Requirement: REQ-DERIVED-006 — Every invocation is audited)
	 */
	public function invokeTool(string $toolId, array $arguments): array {
		$resolved = $this->resolveEntry(toolId: $toolId);
		if ($resolved === null) {
			throw new InvalidArgumentException('Unknown tool: ' . $toolId);
		}

		$schema = $resolved['schema'];
		$register = $resolved['register'];
		$verb = $resolved['verb'];

		$paramsDigest = hash('sha256', $this->canonicalArguments(arguments: $arguments));

		try {
			$result = match ($verb) {
				'search' => $this->search(schema: $schema, register: $register, arguments: $arguments),
				'get' => $this->get(schema: $schema, register: $register, arguments: $arguments),
				'create' => $this->create(schema: $schema, register: $register, arguments: $arguments),
				'update' => $this->update(schema: $schema, register: $register, arguments: $arguments),
				'delete' => $this->delete(schema: $schema, register: $register, arguments: $arguments),
				default => throw new InvalidArgumentException('Unknown verb: ' . $verb),
			};

			$this->writeAudit(
				toolId: $toolId,
				paramsDigest: $paramsDigest,
				resultSummary: $this->resultSummary(verb: $verb, result: $result),
				schema: $schema,
				register: $register,
				result: $result
			);

			return $result;
		} catch (Throwable $e) {
			$this->writeAudit(
				toolId: $toolId,
				paramsDigest: $paramsDigest,
				resultSummary: [
					'isError' => true,
					'errorClass' => get_class($e),
					'message' => substr($e->getMessage(), 0, 300),
				],
				schema: $schema,
				register: $register,
				result: null
			);

			throw $e;
		}//end try
	}//end invokeTool()

	/**
	 * Search verb: declared-filter-only, bounded/clamped pagination,
	 * optional field projection, string truncation, has-more/total metadata.
	 *
	 * @param Schema $schema The target schema.
	 * @param Register|null $register The schema's owning register, when resolvable.
	 * @param array<string, mixed> $arguments Tool arguments.
	 *
	 * @return array<string, mixed> `{results, page, pageSize, total, hasMore}`.
	 *
	 * @throws InvalidArgumentException If `filters` names an undeclared property.
	 *
	 * @spec openspec/specs/ai-mcp/spec.md
	 *   (Requirement: REQ-DERIVED-004 — Search verb: filters, pagination, projection, truncation)
	 */
	private function search(Schema $schema, ?Register $register, array $arguments): array {
		$annotation = $this->mcpAnnotation(schema: $schema);
		$declaredFilters = ($annotation['tools']['search']['filters'] ?? []);
		if (is_array($declaredFilters) === false) {
			$declaredFilters = [];
		}

		$filters = ($arguments['filters'] ?? []);
		if (is_array($filters) === false) {
			throw new InvalidArgumentException('`filters` must be an object.');
		}

		foreach (array_keys($filters) as $filterKey) {
			if (in_array($filterKey, $declaredFilters, true) === false) {
				throw new InvalidArgumentException(
					sprintf(
						'Undeclared search filter "%s". Declared filters: [%s].',
						(string)$filterKey,
						implode(', ', $declaredFilters)
					)
				);
			}
		}

		$page = (int)($arguments['page'] ?? 1);
		if ($page < 1) {
			$page = 1;
		}

		$pageSize = (int)($arguments['pageSize'] ?? self::DEFAULT_PAGE_SIZE);
		if ($pageSize < 1) {
			$pageSize = self::DEFAULT_PAGE_SIZE;
		}

		$pageSize = min($pageSize, self::MAX_PAGE_SIZE);
		$offset = (($page - 1) * $pageSize);

		$config = [
			'limit' => $pageSize,
			'offset' => $offset,
			'filters' => $filters,
		];

		$fields = ($arguments['fields'] ?? null);
		if (is_array($fields) === true && count($fields) > 0) {
			$config['fields'] = $fields;
		}

		$this->anchorObjectService(schema: $schema, register: $register);

		$objects = $this->objectService->findAll(config: $config);
		$total = $this->objectService->count(config: ['filters' => $filters]);

		$results = array_map(
			callback: fn ($object) => $this->truncateStrings(data: $object->jsonSerialize()),
			array: $objects
		);

		return [
			'results' => $results,
			'page' => $page,
			'pageSize' => $pageSize,
			'total' => $total,
			'hasMore' => (($offset + count($results)) < $total),
		];
	}//end search()

	/**
	 * Get verb: fetch a single object by id/uuid via `ObjectService`.
	 *
	 * @param Schema $schema The target schema.
	 * @param Register|null $register The schema's owning register, when resolvable.
	 * @param array<string, mixed> $arguments Must contain `id`.
	 *
	 * @return array<string, mixed> Serialized object.
	 *
	 * @throws InvalidArgumentException If `id` is missing.
	 */
	private function get(Schema $schema, ?Register $register, array $arguments): array {
		$this->requireParam(arguments: $arguments, param: 'id');

		$object = $this->objectService->find(
			id: $arguments['id'],
			register: $register,
			schema: $schema
		);

		return $object->jsonSerialize();
	}//end get()

	/**
	 * Create verb: write a new object through `ObjectService` (RBAC intact).
	 *
	 * @param Schema $schema The target schema.
	 * @param Register|null $register The schema's owning register, when resolvable.
	 * @param array<string, mixed> $arguments The object payload (schema-shaped).
	 *
	 * @return array<string, mixed> Serialized created object.
	 *
	 * @spec openspec/specs/ai-mcp/spec.md
	 *   (Requirement: REQ-DERIVED-005 — Write is authorized exactly as the REST path)
	 */
	private function create(Schema $schema, ?Register $register, array $arguments): array {
		$object = $this->objectService->saveObject(
			object: $arguments,
			register: $register,
			schema: $schema
		);

		return $object->jsonSerialize();
	}//end create()

	/**
	 * Update verb: write an existing object through `ObjectService` (RBAC intact).
	 *
	 * @param Schema $schema The target schema.
	 * @param Register|null $register The schema's owning register, when resolvable.
	 * @param array<string, mixed> $arguments Must contain `id`; the rest is the object payload.
	 *
	 * @return array<string, mixed> Serialized updated object.
	 *
	 * @throws InvalidArgumentException If `id` is missing.
	 */
	private function update(Schema $schema, ?Register $register, array $arguments): array {
		$this->requireParam(arguments: $arguments, param: 'id');

		$id = $arguments['id'];
		$data = $arguments;
		unset($data['id']);

		$object = $this->objectService->saveObject(
			object: $data,
			register: $register,
			schema: $schema,
			uuid: $id
		);

		return $object->jsonSerialize();
	}//end update()

	/**
	 * Delete verb: remove an object through `ObjectService` (RBAC intact — an
	 * unauthorized delete raises the same error the REST path returns,
	 * which `invokeTool()` audits and rethrows for the isError envelope).
	 *
	 * @param Schema $schema The target schema.
	 * @param Register|null $register The schema's owning register, when resolvable.
	 * @param array<string, mixed> $arguments Must contain `id`.
	 *
	 * @return array<string, mixed> Success confirmation.
	 *
	 * @throws InvalidArgumentException If `id` is missing.
	 *
	 * @spec openspec/specs/ai-mcp/spec.md
	 *   (Requirement: REQ-DERIVED-005 — Unauthorized write fails, no bypass)
	 */
	private function delete(Schema $schema, ?Register $register, array $arguments): array {
		$this->requireParam(arguments: $arguments, param: 'id');

		$id = $arguments['id'];
		$this->objectService->deleteObject(
			uuid: $id,
			register: $register,
			schema: $schema
		);

		return ['deleted' => true, 'id' => $id];
	}//end delete()

	/**
	 * Anchor `ObjectService`'s ambient register/schema context for a call
	 * that needs it set before invocation (mirrors {@see ObjectsToolProvider}).
	 *
	 * @param Schema $schema The target schema.
	 * @param Register|null $register The schema's owning register, when resolvable.
	 *
	 * @return void
	 */
	private function anchorObjectService(Schema $schema, ?Register $register): void {
		if ($register !== null) {
			$this->objectService->setRegister(register: $register);
		}

		$this->objectService->setSchema(schema: $schema);
	}//end anchorObjectService()

	/**
	 * Build one tool descriptor for a schema+verb pair.
	 *
	 * @param Schema $schema The schema being derived.
	 * @param string $slug The schema's slug (id-tail).
	 * @param string $verb One of {@see McpAnnotationValidator::VERBS}.
	 * @param array<string, mixed> $verbConfig That verb's dialect config (description/scope/filters/hints).
	 * @param string $id The fully-namespaced tool id.
	 *
	 * @return array{id: string, name: string, description: string, inputSchema: array,
	 *         outputSchema?: array, readOnlyHint?: bool, destructiveHint?: bool,
	 *         idempotentHint?: bool, scope?: string}
	 */
	private function buildDescriptor(Schema $schema, string $slug, string $verb, array $verbConfig, string $id): array {
		$descriptor = [
			'id' => $id,
			'name' => $slug . '_' . $verb,
			'description' => $this->description(slug: $slug, verb: $verbConfig['description'] ?? null, verbName: $verb),
			'inputSchema' => $this->buildInputSchema(schema: $schema, verb: $verb, verbConfig: $verbConfig),
		];

		$outputSchema = $this->buildOutputSchema(verb: $verb);
		if ($outputSchema !== null) {
			$descriptor['outputSchema'] = $outputSchema;
		}

		// MCP 2025-11-25 annotation hints — untrusted UX metadata only, per
		// design.md; they inform the LLM and never gate execution.
		foreach (McpAnnotationValidator::HINT_KEYS as $hintKey) {
			if (isset($verbConfig[$hintKey]) === true) {
				$descriptor[$hintKey] = $verbConfig[$hintKey];
			}
		}

		// The dialect's per-verb `scope` (REQ-DIALECT-001, validated against
		// McpAnnotationValidator::SCOPES at save time). Emitted additively,
		// omitted entirely when the verb didn't declare one — no default is
		// invented, because an invented `read` would be a claim the dialect
		// never made. Like the hints above it is ADVISORY metadata only:
		// ObjectService RBAC remains the authoritative invoke-time gate
		// (REQ-DERIVED-005), and nothing in this class reads `scope` back.
		if (isset($verbConfig['scope']) === true) {
			$descriptor['scope'] = $verbConfig['scope'];
		}

		return $descriptor;
	}//end buildDescriptor()

	/**
	 * Resolve a verb's description: the declared override, else a generated
	 * default naming the schema and owning app.
	 *
	 * @param string $slug Schema slug.
	 * @param string|null $verb Declared description override, or null.
	 * @param string $verbName The verb name.
	 *
	 * @return string The resolved description.
	 */
	private function description(string $slug, ?string $verb, string $verbName): string {
		if (is_string($verb) === true && $verb !== '') {
			return $verb;
		}

		return match ($verbName) {
			'search' => sprintf('Search %s objects in %s.', $slug, $this->appId),
			'get' => sprintf('Get a single %s object by id in %s.', $slug, $this->appId),
			'create' => sprintf('Create a %s object in %s.', $slug, $this->appId),
			'update' => sprintf('Update a %s object in %s.', $slug, $this->appId),
			'delete' => sprintf('Delete a %s object in %s.', $slug, $this->appId),
			default => sprintf('%s %s objects in %s.', ucfirst($verbName), $slug, $this->appId),
		};
	}//end description()

	/**
	 * Build the `inputSchema` for a verb, reusing the schema JSON per
	 * design.md's derivation table.
	 *
	 * @param Schema $schema The schema being derived.
	 * @param string $verb One of {@see McpAnnotationValidator::VERBS}.
	 * @param array<string, mixed> $verbConfig That verb's dialect config.
	 *
	 * @return array<string, mixed> JSON-Schema-shaped input schema.
	 */
	private function buildInputSchema(Schema $schema, string $verb, array $verbConfig): array {
		$properties = ($schema->getProperties() ?? []);
		$required = ($schema->getRequired() ?? []);
		$idProperty = ['type' => 'string', 'description' => 'Object id or uuid.'];

		return match ($verb) {
			'search' => [
				'type' => 'object',
				'properties' => [
					'filters' => [
						'type' => 'object',
						'properties' => $this->filterProperties(properties: $properties, verbConfig: $verbConfig),
						'additionalProperties' => false,
					],
					'page' => ['type' => 'integer', 'description' => 'Page number (default 1).'],
					'pageSize' => [
						'type' => 'integer',
						'description' => sprintf(
							'Results per page (default %d, max %d).',
							self::DEFAULT_PAGE_SIZE,
							self::MAX_PAGE_SIZE
						),
					],
					'fields' => [
						'type' => 'array',
						'items' => ['type' => 'string'],
						'description' => 'Optional field projection.',
					],
				],
			],
			'get' => [
				'type' => 'object',
				'properties' => ['id' => $idProperty],
				'required' => ['id'],
			],
			'create' => [
				'type' => 'object',
				'properties' => $this->jsonSchemaSafeMap(properties: $properties),
				'required' => $required,
			],
			'update' => [
				'type' => 'object',
				'properties' => array_merge(
					['id' => $idProperty],
					$this->jsonSchemaSafeMap(properties: $properties)
				),
				'required' => ['id'],
			],
			'delete' => [
				'type' => 'object',
				'properties' => ['id' => $idProperty],
				'required' => ['id'],
			],
			default => ['type' => 'object', 'properties' => []],
		};//end match
	}//end buildInputSchema()

	/**
	 * Build the `outputSchema` for read verbs (MCP 2025-06-18
	 * `structuredContent`), null for write verbs.
	 *
	 * Takes no Schema: it deliberately does not depend on the schema's properties
	 * any more, and keeping the parameter would suggest otherwise to the next
	 * reader — which is how it would get re-inlined.
	 *
	 * @param string $verb One of {@see McpAnnotationValidator::VERBS}.
	 *
	 * @return array<string, mixed>|null The output schema, or null when not applicable.
	 */
	private function buildOutputSchema(string $verb): ?array {
		// THE ENVELOPE, NOT THE ITEM.
		//
		// This used to inline `$schema->getProperties()` in full, for both verbs.
		// Measured 2026-08-16 across the 122 tools registered on the development
		// instance, that made `outputSchema` **79.7% of the entire tools/list
		// payload** -- 335,580 of 433,198 bytes, ~84,000 of ~108,000 tokens, on a
		// payload re-sent to the model every single turn. Tool definitions alone
		// were consuming 54% of a 200K context window before the user said
		// anything.
		//
		// The worst case was `shillinq.ARInvoice.search`: 36,293 B of outputSchema
		// against 1,915 B of inputSchema -- 94% of that tool, and more tokens by
		// itself than hermiq's entire 22-tool set.
		//
		// Note the asymmetry this corrects. buildInputSchema() already narrows a
		// `search` verb to its DECLARED FILTERS via filterProperties()
		// (REQ-DERIVED-004). The input path was economical and the output path was
		// not, and nothing made the difference visible.
		//
		// The item's properties are redundant here: the model reads the actual
		// result when the tool returns. The ENVELOPE is not redundant -- it tells
		// the model that a `search` yields {results, total, hasMore} rather than a
		// bare array, which is what stops it guessing at the response shape.
		// Keeping it costs 6,688 bytes across the whole registry (1.5%) against
		// dropping `outputSchema` altogether.
		return match ($verb) {
			'search' => [
				'type' => 'object',
				'properties' => [
					'results' => ['type' => 'array', 'items' => ['type' => 'object']],
					'total' => ['type' => 'integer'],
					'hasMore' => ['type' => 'boolean'],
				],
			],
			'get' => ['type' => 'object'],
			default => null,
		};
	}//end buildOutputSchema()

	/**
	 * Narrow a schema's properties down to the ones a `search` verb declared
	 * as filters (REQ-DERIVED-004: only declared filters are honoured).
	 *
	 * @param array<string, mixed> $properties The schema's declared properties.
	 * @param array<string, mixed> $verbConfig The `search` verb's dialect config.
	 *
	 * @return array<string, mixed> The filter-eligible property subset.
	 */
	private function filterProperties(array $properties, array $verbConfig): array {
		$declared = ($verbConfig['filters'] ?? []);
		if (is_array($declared) === false) {
			return [];
		}

		$result = [];
		foreach ($declared as $name) {
			if (is_string($name) === true && array_key_exists($name, $properties) === true) {
				$result[$name] = $this->jsonSchemaSafe(property: $properties[$name]);
			}
		}

		return $result;
	}//end filterProperties()

	/**
	 * Reduce a schema property to keywords that are valid JSON Schema.
	 *
	 * 🔴 A tool's `inputSchema` leaves this instance and is validated by the
	 * vendor as strict JSON Schema draft 2020-12. OpenRegister's property
	 * definitions are NOT that: they carry dialect of our own, and one of those
	 * keywords is fatal.
	 *
	 * `$ref` is the one that bites. It is OpenRegister's RELATION marker —
	 * `{"type":"string","format":"uuid","$ref":"client"}` means "this uuid
	 * points at the client schema" — but to a JSON Schema validator `$ref` is a
	 * URI reference, and `"client"` resolves to nothing. Measured 2026-08-17
	 * against Anthropic:
	 *
	 *   400 tools.2.custom.input_schema: JSON schema is invalid.
	 *       It must match JSON Schema draft 2020-12
	 *
	 * `pipelinq.lead.search` declares `client` as a filter and was therefore
	 * UNCALLABLE BY ANY AGENT, no matter who granted it — while its sibling
	 * `pipelinq.client.search`, whose filters carry no `$ref`, worked. Twenty
	 * properties across that one app use the relation dialect, so this is a
	 * whole class of tools rather than one bad line.
	 *
	 * Fixed HERE rather than in the app registers: the dialect is correct where
	 * it is written and OpenRegister depends on it. What was wrong is emitting
	 * it into a document that promises to be JSON Schema.
	 *
	 * An allow-list, not a deny-list of `$ref`: the next dialect keyword added
	 * to a register would otherwise silently break every derived tool that used
	 * it, which is exactly how this arrived.
	 *
	 * @param mixed $property The declared property.
	 *
	 * @return array<string, mixed> The property, safe to publish.
	 */
	private function jsonSchemaSafe(mixed $property): array {
		if (is_array($property) === false) {
			return ['type' => 'string'];
		}

		$allowed = [
			'type',
			'title',
			'description',
			'enum',
			'const',
			'default',
			'format',
			'pattern',
			'minimum',
			'maximum',
			'exclusiveMinimum',
			'exclusiveMaximum',
			'minLength',
			'maxLength',
			'minItems',
			'maxItems',
			'uniqueItems',
		];

		$safe = [];
		foreach ($allowed as $keyword) {
			if (array_key_exists($keyword, $property) === true) {
				$safe[$keyword] = $property[$keyword];
			}
		}

		$safe = $this->jsonSchemaSafeNested(property: $property, safe: $safe);

		// A property with no usable type still has to be describable.
		if (isset($safe['type']) === false && isset($safe['enum']) === false) {
			$safe['type'] = 'string';
		}

		return $safe;
	}//end jsonSchemaSafe()

	/**
	 * Sanitise the nested shapes of a property.
	 *
	 * Split out of {@see jsonSchemaSafe()} to keep that method under the
	 * complexity threshold. The recursion is the point: without it the dialect
	 * simply reappears one level down, inside `items` or `properties`, and the
	 * published document is still not JSON Schema.
	 *
	 * @param array<string, mixed> $property The declared property.
	 * @param array<string, mixed> $safe     The keywords accepted so far.
	 *
	 * @return array<string, mixed> The accepted keywords plus sanitised nested shapes.
	 */
	private function jsonSchemaSafeNested(array $property, array $safe): array {
		if (isset($property['items']) === true) {
			$safe['items'] = $this->jsonSchemaSafe(property: $property['items']);
		}

		if (isset($property['properties']) === true && is_array($property['properties']) === true) {
			$nested = [];
			foreach ($property['properties'] as $key => $value) {
				$nested[$key] = $this->jsonSchemaSafe(property: $value);
			}

			$safe['properties'] = $nested;
		}

		return $safe;
	}//end jsonSchemaSafeNested()

	/**
	 * Sanitise a whole property map for publication in a tool schema.
	 *
	 * @param array<string, mixed> $properties The declared properties.
	 *
	 * @return array<string, mixed> The sanitised map.
	 */
	private function jsonSchemaSafeMap(array $properties): array {
		$safe = [];
		foreach ($properties as $name => $property) {
			$safe[$name] = $this->jsonSchemaSafe(property: $property);
		}

		return $safe;
	}//end jsonSchemaSafeMap()

	/**
	 * Resolve the validated `x-openregister-mcp` block, or an empty array
	 * when absent/malformed.
	 *
	 * @param Schema $schema The schema to read.
	 *
	 * @return array<string, mixed> The `x-openregister-mcp` block.
	 */
	private function mcpAnnotation(Schema $schema): array {
		$configuration = ($schema->getConfiguration() ?? []);
		$annotation = ($configuration['x-openregister-mcp'] ?? []);
		if (is_array($annotation) === false) {
			return [];
		}

		return $annotation;
	}//end mcpAnnotation()

	/**
	 * A schema's slug, falling back to its numeric id when unset.
	 *
	 * @param Schema $schema The schema.
	 *
	 * @return string The slug (id-tail used in every derived tool id).
	 */
	private function schemaSlug(Schema $schema): string {
		$slug = $schema->getSlug();
		if (is_string($slug) === true && $slug !== '') {
			return $slug;
		}

		return (string)$schema->getId();
	}//end schemaSlug()

	/**
	 * Resolve a namespaced tool id back to its schema/register/verb.
	 *
	 * @param string $toolId Namespaced tool id, e.g. "pipelinq.lead.search".
	 *
	 * @return array{schema: Schema, register: Register|null, verb: string}|null
	 */
	private function resolveEntry(string $toolId): ?array {
		$parts = explode('.', $toolId);
		if (count($parts) < 3) {
			return null;
		}

		$verb = array_pop($parts);
		$slug = array_pop($parts);
		$appId = implode('.', $parts);

		if ($appId !== $this->appId || in_array($verb, McpAnnotationValidator::VERBS, true) === false) {
			return null;
		}

		foreach ($this->schemaEntries as $entry) {
			if ($this->schemaSlug(schema: $entry['schema']) === $slug) {
				return [
					'schema' => $entry['schema'],
					'register' => $entry['register'],
					'verb' => $verb,
				];
			}
		}

		return null;
	}//end resolveEntry()

	/**
	 * Build a small structured outcome summary for a successful invocation
	 * (never raw object payloads — REQ-DERIVED-006).
	 *
	 * @param string $verb The invoked verb.
	 * @param array<string, mixed> $result The verb's return value.
	 *
	 * @return array<string, mixed> The result summary.
	 */
	private function resultSummary(string $verb, array $result): array {
		if ($verb === 'search') {
			return [
				'isError' => false,
				'count' => count($result['results'] ?? []),
				'total' => ($result['total'] ?? null),
				'hasMore' => ($result['hasMore'] ?? null),
			];
		}

		$id = null;
		if (is_string($result['id'] ?? null) === true) {
			$id = $result['id'];
		}

		return [
			'isError' => false,
			'id' => $id,
		];
	}//end resultSummary()

	/**
	 * Write exactly one immutable, hash-chained audit record for this
	 * invocation. Fail-soft: a broken audit write is logged and MUST NOT
	 * mask (or replace) the invocation's real result/error — losing an
	 * audit row is bad, losing the invocation outcome to an audit hiccup is
	 * worse (mirrors {@see \OCA\OpenRegister\Service\ObjectService::logProcessingRead()}).
	 *
	 * @param string $toolId Full namespaced tool id.
	 * @param string $paramsDigest SHA-256 hex digest of the invocation arguments.
	 * @param array<string, mixed> $resultSummary Structured outcome summary.
	 * @param Schema $schema The invoked tool's schema.
	 * @param Register|null $register The schema's owning register, when resolvable.
	 * @param array<string, mixed>|null $result The verb's return value, or null on failure.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/ai-mcp/spec.md
	 *   (Requirement: REQ-DERIVED-006 — Every invocation is audited)
	 */
	private function writeAudit(
		string $toolId,
		string $paramsDigest,
		array $resultSummary,
		Schema $schema,
		?Register $register,
		?array $result,
	): void {
		try {
			$objectUuid = null;
			if ($result !== null && is_string($result['id'] ?? null) === true) {
				$objectUuid = $result['id'];
			}

			$registerId = null;
			if ($register !== null) {
				$registerId = $register->getId();
			}

			$this->auditTrailMapper->createToolInvocationEntry(
				toolId: $toolId,
				paramsDigest: $paramsDigest,
				resultSummary: $resultSummary,
				register: $registerId,
				schema: $schema->getId(),
				objectUuid: $objectUuid
			);
		} catch (Throwable $e) {
			$this->logger->error(
				'[SchemaDerivedToolProvider] Failed to write invocation audit record',
				['toolId' => $toolId, 'error' => $e->getMessage()]
			);
		}//end try
	}//end writeAudit()

	/**
	 * Deterministically canonicalise arguments (recursive key-sort) so the
	 * params digest is stable regardless of argument-array key order.
	 *
	 * @param array<string, mixed> $arguments Tool arguments.
	 *
	 * @return string Canonical JSON representation.
	 */
	private function canonicalArguments(array $arguments): string {
		$encoded = json_encode(
			$this->deepKsort(value: $arguments),
			JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
		);

		if ($encoded === false) {
			return '';
		}

		return $encoded;
	}//end canonicalArguments()

	/**
	 * Recursively sort an array by key for deterministic serialisation.
	 *
	 * @param array<array-key, mixed> $value The array to sort.
	 *
	 * @return array<array-key, mixed> The sorted array.
	 */
	private function deepKsort(array $value): array {
		ksort($value);
		foreach ($value as $key => $item) {
			if (is_array($item) === true) {
				$value[$key] = $this->deepKsort(value: $item);
			}
		}

		return $value;
	}//end deepKsort()

	/**
	 * Truncate long top-level string values in a serialized object so a
	 * `search` page cannot burn an unbounded token budget.
	 *
	 * @param array<string, mixed> $data The serialized object.
	 *
	 * @return array<string, mixed> The same object, long strings truncated.
	 */
	private function truncateStrings(array $data): array {
		foreach ($data as $key => $value) {
			if (is_string($value) === true && strlen($value) > self::TRUNCATE_LENGTH) {
				$data[$key] = substr($value, 0, self::TRUNCATE_LENGTH) . '…';
			}
		}

		return $data;
	}//end truncateStrings()

	/**
	 * Assert a parameter is present in arguments.
	 *
	 * @param array<string, mixed> $arguments Tool arguments.
	 * @param string $param Required parameter name.
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException If parameter is missing.
	 */
	private function requireParam(array $arguments, string $param): void {
		if (isset($arguments[$param]) === false) {
			throw new InvalidArgumentException('Missing required parameter: ' . $param);
		}
	}//end requireParam()
}//end class
