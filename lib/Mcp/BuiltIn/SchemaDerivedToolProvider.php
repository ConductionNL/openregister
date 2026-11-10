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
 * `AuditTrailMapper::recordToolInvocation()` (REQ-DERIVED-006).
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
use OCA\OpenRegister\Db\AuditTrailMapperInterface;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Mcp\IMcpToolProvider;
use OCA\OpenRegister\Mcp\Service\Mcp\McpAnnotationValidator;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCA\OpenRegister\Service\WriteCause;
use Psr\Log\LoggerInterface;

/**
 * SchemaDerivedToolProvider
 *
 * Built-in IMcpToolProvider that derives coarse-CRUD tools from the
 * `x-openregister-mcp` schema dialect. One instance per owning app.
 *
 * @category Mcp
 * @package  OCA\OpenRegister\Mcp\BuiltIn
 *
 * @psalm-suppress UnusedClass - Instantiated by McpProviderDiscovery::register()
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassLength) One IMcpToolProvider implementation: the ABI's id/verb contract, the
 *      two collision rules (hand-written > derived, first-definition > duplicate) and the JSON-Schema sanitiser are one
 *      story about what this app publishes. Splitting them would scatter the derived-tool contract across files.
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
	 * The `id` input property of the verbs that address one object.
	 *
	 * @var array<string, string>
	 */
	private const ID_INPUT_PROPERTY = ['type' => 'string', 'description' => 'Object id or uuid.'];

	/**
	 * The input schema of a verb that takes only an object id (`get`, `delete`).
	 *
	 * @var array<string, mixed>
	 */
	private const ID_INPUT_SCHEMA = [
		'type' => 'object',
		'properties' => ['id' => self::ID_INPUT_PROPERTY],
		'required' => ['id'],
	];

	/**
	 * The `fields` input property of the `search` verb.
	 *
	 * @var array<string, mixed>
	 */
	private const FIELDS_INPUT_PROPERTY = [
		'type' => 'array',
		'items' => ['type' => 'string'],
		'description' => 'Optional field projection.',
	];

	/**
	 * JSON Schema keywords a published property may carry (an allow-list, see jsonSchemaSafe())
	 *
	 * @var list<string>
	 */
	private const JSON_SCHEMA_KEYWORDS = [
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

	/**
	 * RBAC-enforcing object read/write facade.
	 *
	 * @var ObjectOperationsInterface
	 */
	private readonly ObjectOperationsInterface $objectService;

	/**
	 * Immutable hash-chained audit-trail writer.
	 *
	 * @var AuditTrailMapperInterface
	 */
	private readonly AuditTrailMapperInterface $auditTrailMapper;

	/**
	 * PSR logger.
	 *
	 * @var LoggerInterface
	 */
	private readonly LoggerInterface $logger;

	/**
	 * Constructor.
	 *
	 * `$schemaEntries` is a `list<array{schema: Schema, register: Register|null}>`
	 * — that app's opted-in schemas, each paired with its owning register
	 * (when resolvable). `$suppressedIds` is a `list<string>` of tool ids
	 * already claimed by a hand-written provider for this app; the derived
	 * duplicates are self-suppressed (REQ-DERIVED-003).
	 *
	 * @param string                    $appId         The owning app id; `getAppId()` and every id prefix.
	 * @param array                     $schemaEntries The app's opted-in schema entries (see above).
	 * @param array                     $suppressedIds Hand-written tool ids to self-suppress (see above).
	 * @param SchemaDerivedToolServices $services      The object facade, audit-trail writer and logger.
	 */
	public function __construct(
		private readonly string $appId,
		private readonly array $schemaEntries,
		private readonly array $suppressedIds,
		SchemaDerivedToolServices $services,
	) {
		$this->objectService = $services->objectService;
		$this->auditTrailMapper = $services->auditTrailMapper;
		$this->logger = $services->logger;
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

		// Ids already emitted by THIS provider.
		//
		// ⚠️ A tool id is `{app}.{slug}.{verb}`, which carries no register — so
		// every duplicate definition of one schema slug collides on a single
		// id. Measured on the dev instance: 16 schema rows share the slug
		// `generatedDocument` and 11 share `correspondence`, 13 of each
		// MCP-enabled, so the catalogue served 207 rows for 159 distinct tools
		// and an agent saw the same tool thirteen times.
		//
		// The existing REQ-DERIVED-003 suppression only covers derived-vs-
		// HAND-WRITTEN collisions; this is derived-vs-derived and went
		// unguarded. First definition wins, matching that rule's precedence
		// direction, and the rest are logged rather than dropped silently —
		// duplicate schema rows are a data problem this provider can only
		// report, not fix.
		$emittedIds = [];

		foreach ($this->schemaEntries as $entry) {
			$descriptors = array_merge(
				$descriptors,
				$this->descriptorsForSchema(schema: $entry['schema'], emittedIds: $emittedIds)
			);
		}

		return $descriptors;
	}//end getTools()

	/**
	 * The descriptors one MCP-enabled schema contributes.
	 *
	 * @param Schema               $schema     The schema being derived.
	 * @param array<string, mixed> $emittedIds Ids already emitted, mapped to their schema id (updated).
	 *
	 * @return list<array<string, mixed>> The schema's descriptors; none when MCP is not enabled.
	 */
	private function descriptorsForSchema(Schema $schema, array &$emittedIds): array {
		$annotation = $this->mcpAnnotation(schema: $schema);
		if (($annotation['enabled'] ?? false) !== true) {
			return [];
		}

		$slug = $this->schemaSlug(schema: $schema);
		$toolsConfig = ($annotation['tools'] ?? null);

		$descriptors = [];
		foreach ($this->enabledVerbs(toolsConfig: $toolsConfig) as $verb) {
			$tool = new DerivedToolDefinition(
				schema: $schema,
				slug: $slug,
				verb: $verb,
				verbConfig: $this->verbConfig(toolsConfig: $toolsConfig, verb: $verb)
			);
			if ($this->claimToolId(tool: $tool, emittedIds: $emittedIds) === false) {
				continue;
			}

			$descriptors[] = $this->buildDescriptor(tool: $tool);
		}

		return $descriptors;
	}//end descriptorsForSchema()

	/**
	 * The verbs a schema exposes: all of them, or those its `tools` block names.
	 *
	 * @param mixed $toolsConfig The annotation's `tools` block.
	 *
	 * @return list<string> The verbs, in canonical order.
	 */
	private function enabledVerbs(mixed $toolsConfig): array {
		if (is_array($toolsConfig) === true) {
			return array_values(array_intersect(McpAnnotationValidator::VERBS, array_keys($toolsConfig)));
		}

		return McpAnnotationValidator::VERBS;
	}//end enabledVerbs()

	/**
	 * One verb's dialect config, or an empty array when it declares none.
	 *
	 * @param mixed  $toolsConfig The annotation's `tools` block.
	 * @param string $verb        The verb.
	 *
	 * @return array<string, mixed> The verb config.
	 */
	private function verbConfig(mixed $toolsConfig, string $verb): array {
		if (is_array($toolsConfig) === true && is_array($toolsConfig[$verb] ?? null) === true) {
			return $toolsConfig[$verb];
		}

		return [];
	}//end verbConfig()

	/**
	 * The fully-namespaced id of a derived tool: `{appId}.{slug}.{verb}`.
	 *
	 * @param DerivedToolDefinition $tool The tool being described.
	 *
	 * @return string The tool id.
	 */
	private function toolId(DerivedToolDefinition $tool): string {
		return $this->appId . '.' . $tool->slug . '.' . $tool->verb;
	}//end toolId()

	/**
	 * Claim a tool id for a schema, refusing suppressed and already-emitted ids.
	 *
	 * @param DerivedToolDefinition $tool       The tool whose id the schema claims.
	 * @param array<string, mixed>  $emittedIds Ids already emitted, mapped to their schema id (updated).
	 *
	 * @return bool Whether the id may be emitted for this schema.
	 */
	private function claimToolId(DerivedToolDefinition $tool, array &$emittedIds): bool {
		$id = $this->toolId(tool: $tool);
		$schema = $tool->schema;

		// Self-suppression (REQ-DERIVED-003): a hand-written per-app
		// provider already claims this id — omit the derived
		// duplicate entirely rather than merely shadowing it.
		if (in_array($id, $this->suppressedIds, true) === true) {
			return false;
		}

		// A second schema row claiming the same id — see getTools().
		if (array_key_exists($id, $emittedIds) === true) {
			$this->logger->warning(
				message: '[SchemaDerivedToolProvider] duplicate tool id suppressed — two schema rows share one slug',
				context: [
					'file' => __FILE__,
					'line' => __LINE__,
					'toolId' => $id,
					'keptSchemaId' => $emittedIds[$id],
					'droppedSchemaId' => $schema->getId(),
				]
			);
			return false;
		}

		$emittedIds[$id] = $schema->getId();

		return true;
	}//end claimToolId()

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

		return $this->audit()->run(
			target: new ToolAuditTarget(toolId: $toolId, register: $register?->getId(), schema: $schema->getId()),
			invocation: new AuditedInvocation(
				arguments: $arguments,
				call: fn (): array => $this->runVerb(
					verb: $verb,
					call: new DerivedToolCall(schema: $schema, register: $register, arguments: $arguments)
				),
				summarise: fn (array $result): array => $this->resultSummary(verb: $verb, result: $result)
			)
		);
	}//end invokeTool()

	/**
	 * Run one verb of a schema-derived tool.
	 *
	 * @param string          $verb The verb.
	 * @param DerivedToolCall $call The target schema, its register and the tool arguments.
	 *
	 * @return array<string, mixed> The verb's result.
	 *
	 * @throws InvalidArgumentException On an unknown verb.
	 */
	private function runVerb(string $verb, DerivedToolCall $call): array {
		return match ($verb) {
			'search' => $this->search(call: $call),
			'get' => $this->get(call: $call),
			'create' => $this->create(call: $call),
			'update' => $this->update(call: $call),
			'delete' => $this->delete(call: $call),
			default => throw new InvalidArgumentException('Unknown verb: ' . $verb),
		};
	}//end runVerb()

	/**
	 * The audit runner of this provider.
	 *
	 * @return ToolInvocationAudit The runner.
	 */
	private function audit(): ToolInvocationAudit {
		return new ToolInvocationAudit(auditTrailMapper: $this->auditTrailMapper, logger: $this->logger, source: 'SchemaDerivedToolProvider');
	}//end audit()

	/**
	 * Search verb: declared-filter-only, bounded/clamped pagination,
	 * optional field projection, string truncation, has-more/total metadata.
	 *
	 * @param DerivedToolCall $call The target schema, its register and the tool arguments.
	 *
	 * @return array<string, mixed> `{results, page, pageSize, total, hasMore}`.
	 *
	 * @throws InvalidArgumentException If `filters` names an undeclared property.
	 *
	 * @spec openspec/specs/ai-mcp/spec.md
	 *   (Requirement: REQ-DERIVED-004 — Search verb: filters, pagination, projection, truncation)
	 */
	private function search(DerivedToolCall $call): array {
		$search = $this->searchRequest(call: $call);
		$config = $this->searchConfig(search: $search, arguments: $call->arguments);

		$this->anchorObjectService(schema: $call->schema, register: $call->register);

		$objects = $this->objectService->findAll(config: $config);
		$total = $this->objectService->count(config: ['filters' => $search->filters]);
		$results = $this->truncatedResults(objects: $objects);

		return [
			'results' => $results,
			'page' => $search->page,
			'pageSize' => $search->pageSize,
			'total' => $total,
			'hasMore' => (($search->offset() + count($results)) < $total),
		];
	}//end search()

	/**
	 * The declared filters and the page window a `search` call asks for.
	 *
	 * @param DerivedToolCall $call The schema, its register and the arguments.
	 *
	 * @return DerivedSearch
	 *
	 * @throws InvalidArgumentException If `filters` names an undeclared property.
	 */
	private function searchRequest(DerivedToolCall $call): DerivedSearch {
		$filters = $this->declaredFilters(schema: $call->schema, arguments: $call->arguments);

		return new DerivedSearch(
			filters: $filters,
			page: $this->pageNumber(arguments: $call->arguments),
			pageSize: $this->pageSize(arguments: $call->arguments)
		);
	}//end searchRequest()

	/**
	 * The ObjectService query config of a search: page window, filters and an optional field projection.
	 *
	 * @param DerivedSearch        $search    The filters and page window.
	 * @param array<string, mixed> $arguments The tool arguments, for `fields`.
	 *
	 * @return array<string, mixed>
	 */
	private function searchConfig(DerivedSearch $search, array $arguments): array {
		$config = [
			'limit' => $search->pageSize,
			'offset' => $search->offset(),
			'filters' => $search->filters,
		];

		$fields = ($arguments['fields'] ?? null);
		if (is_array($fields) === true && count($fields) > 0) {
			$config['fields'] = $fields;
		}

		return $config;
	}//end searchConfig()

	/**
	 * The found objects serialised, with long strings truncated.
	 *
	 * @param array $objects The found objects.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function truncatedResults(array $objects): array {
		return array_map(
			callback: fn ($object) => $this->truncateStrings(data: $object->jsonSerialize()),
			array: $objects
		);
	}//end truncatedResults()

	/**
	 * The requested search filters, refused when one is not declared by the schema.
	 *
	 * @param Schema               $schema    The target schema.
	 * @param array<string, mixed> $arguments Tool arguments.
	 *
	 * @return array<string, mixed> The filters.
	 *
	 * @throws InvalidArgumentException If `filters` is not an object or names an undeclared property.
	 */
	private function declaredFilters(Schema $schema, array $arguments): array {
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

		return $filters;
	}//end declaredFilters()

	/**
	 * The requested page, at least 1.
	 *
	 * @param array<string, mixed> $arguments Tool arguments.
	 *
	 * @return int The page.
	 */
	private function pageNumber(array $arguments): int {
		$page = (int)($arguments['page'] ?? 1);
		if ($page < 1) {
			$page = 1;
		}

		return $page;
	}//end pageNumber()

	/**
	 * The requested page size: the default when below 1, clamped to the maximum.
	 *
	 * @param array<string, mixed> $arguments Tool arguments.
	 *
	 * @return int The page size.
	 */
	private function pageSize(array $arguments): int {
		$pageSize = (int)($arguments['pageSize'] ?? self::DEFAULT_PAGE_SIZE);
		if ($pageSize < 1) {
			$pageSize = self::DEFAULT_PAGE_SIZE;
		}

		return min($pageSize, self::MAX_PAGE_SIZE);
	}//end pageSize()

	/**
	 * Get verb: fetch a single object by id/uuid via `ObjectService`.
	 *
	 * @param DerivedToolCall $call The target schema, its register and the tool arguments; arguments: must contain `id`.
	 *
	 * @return array<string, mixed> Serialized object.
	 *
	 * @throws InvalidArgumentException If `id` is missing.
	 * @spec openspec/specs/object-interactions/spec.md#requirement-only-a-person-opening-an-object-counts-as-recently-opened
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) WriteCause::asLookup() is the ambient audit-cause frame; there is no instance to inject.
	 */
	private function get(DerivedToolCall $call): array {
		$schema = $call->schema;
		$register = $call->register;
		$arguments = $call->arguments;

		$this->requireParam(arguments: $arguments, param: 'id');

		$object = WriteCause::asLookup(fn () => $this->objectService->find(
			id: $arguments['id'],
			register: $register,
			schema: $schema
		));

		return $object->jsonSerialize();
	}//end get()

	/**
	 * Create verb: write a new object through `ObjectService` (RBAC intact).
	 *
	 * @param DerivedToolCall $call The target schema, its register and the tool arguments; arguments: the object payload (schema-shaped).
	 *
	 * @return array<string, mixed> Serialized created object.
	 *
	 * @spec openspec/specs/ai-mcp/spec.md
	 *   (Requirement: REQ-DERIVED-005 — Write is authorized exactly as the REST path)
	 */
	private function create(DerivedToolCall $call): array {
		$schema = $call->schema;
		$register = $call->register;
		$arguments = $call->arguments;

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
	 * @param DerivedToolCall $call The target schema, its register and the tool arguments; arguments: must contain `id`; the rest is the object payload.
	 *
	 * @return array<string, mixed> Serialized updated object.
	 *
	 * @throws InvalidArgumentException If `id` is missing.
	 */
	private function update(DerivedToolCall $call): array {
		$schema = $call->schema;
		$register = $call->register;
		$arguments = $call->arguments;

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
	 * @param DerivedToolCall $call The target schema, its register and the tool arguments; arguments: must contain `id`.
	 *
	 * @return array<string, mixed> Success confirmation.
	 *
	 * @throws InvalidArgumentException If `id` is missing.
	 *
	 * @spec openspec/specs/ai-mcp/spec.md
	 *   (Requirement: REQ-DERIVED-005 — Unauthorized write fails, no bypass)
	 */
	private function delete(DerivedToolCall $call): array {
		$schema = $call->schema;
		$register = $call->register;
		$arguments = $call->arguments;

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
	 * @param DerivedToolDefinition $tool The schema, slug, verb and verb config.
	 *
	 * @return array{id: string, name: string, description: string, inputSchema: array,
	 *         app: string, subject: string, action: string,
	 *         outputSchema?: array, readOnlyHint?: bool, destructiveHint?: bool,
	 *         idempotentHint?: bool, scope?: string}
	 */
	private function buildDescriptor(DerivedToolDefinition $tool): array {
		$slug = $tool->slug;
		$verb = $tool->verb;
		$verbConfig = $tool->verbConfig;
		$descriptor = [
			'id' => $this->toolId(tool: $tool),
			'name' => $slug . '_' . $verb,
			'description' => $this->description(tool: $tool, override: $verbConfig['description'] ?? null),
			'inputSchema' => $this->buildInputSchema(tool: $tool),

			// The id's three parts, carried as FIELDS rather than only joined
			// into a string.
			//
			// ⚠️ This provider composes `{app}.{slug}.{verb}` from values it
			// already holds, and previously published only the joined result —
			// so every consumer had to take the structure apart again. A grant
			// UI doing that ended up splitting camelCase and singularising
			// English ("courses" → "cours", `webFetch` → subject "fetch"),
			// which is guesswork standing in for data that was never lost, only
			// discarded. A consumer must never have to parse an identifier to
			// recover something the producer knew.
			'app' => $this->appId,
			'subject' => $slug,
			'action' => $verb,
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
	 * @param DerivedToolDefinition $tool     The tool: its schema slug and verb name.
	 * @param string|null           $override Declared description override, or null.
	 *
	 * @return string The resolved description.
	 */
	private function description(DerivedToolDefinition $tool, ?string $override): string {
		if (is_string($override) === true && $override !== '') {
			return $override;
		}

		$slug = $tool->slug;
		$verbName = $tool->verb;

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
	 * @param DerivedToolDefinition $tool The schema being derived, the verb and that verb's dialect config.
	 *
	 * @return array<string, mixed> JSON-Schema-shaped input schema.
	 */
	private function buildInputSchema(DerivedToolDefinition $tool): array {
		return match ($tool->verb) {
			'search' => $this->searchInputSchema(tool: $tool),
			'get' => self::ID_INPUT_SCHEMA,
			'create' => $this->createInputSchema(tool: $tool),
			'update' => $this->updateInputSchema(tool: $tool),
			'delete' => self::ID_INPUT_SCHEMA,
			default => ['type' => 'object', 'properties' => []],
		};
	}//end buildInputSchema()

	/**
	 * The `search` input schema: the declared filters, the page window and a field projection.
	 *
	 * @param DerivedToolDefinition $tool The schema being derived and the `search` verb's dialect config.
	 *
	 * @return array<string, mixed>
	 */
	private function searchInputSchema(DerivedToolDefinition $tool): array {
		$filters = [
			'type' => 'object',
			'properties' => $this->filterProperties(properties: ($tool->schema->getProperties() ?? []), verbConfig: $tool->verbConfig),
			'additionalProperties' => false,
		];

		return [
			'type' => 'object',
			'properties' => array_merge(['filters' => $filters], $this->pagingInputProperties()),
		];
	}//end searchInputSchema()

	/**
	 * The `page`, `pageSize` and `fields` input properties of the `search` verb.
	 *
	 * @return array<string, mixed>
	 */
	private function pagingInputProperties(): array {
		return [
			'page' => ['type' => 'integer', 'description' => 'Page number (default 1).'],
			'pageSize' => [
				'type' => 'integer',
				'description' => sprintf(
					'Results per page (default %d, max %d).',
					self::DEFAULT_PAGE_SIZE,
					self::MAX_PAGE_SIZE
				),
			],
			'fields' => self::FIELDS_INPUT_PROPERTY,
		];
	}//end pagingInputProperties()

	/**
	 * The `create` input schema: every published property, with the schema's required list.
	 *
	 * @param DerivedToolDefinition $tool The schema being derived.
	 *
	 * @return array<string, mixed>
	 */
	private function createInputSchema(DerivedToolDefinition $tool): array {
		return [
			'type' => 'object',
			'properties' => $this->jsonSchemaSafeMap(properties: ($tool->schema->getProperties() ?? [])),
			'required' => ($tool->schema->getRequired() ?? []),
		];
	}//end createInputSchema()

	/**
	 * The `update` input schema: the object id followed by every published property.
	 *
	 * @param DerivedToolDefinition $tool The schema being derived.
	 *
	 * @return array<string, mixed>
	 */
	private function updateInputSchema(DerivedToolDefinition $tool): array {
		return [
			'type' => 'object',
			'properties' => array_merge(
				['id' => self::ID_INPUT_PROPERTY],
				$this->jsonSchemaSafeMap(properties: ($tool->schema->getProperties() ?? []))
			),
			'required' => ['id'],
		];
	}//end updateInputSchema()

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
	 *
	 * @spec openspec/specs/ai-mcp/spec.md
	 *   (Requirement: REQ-DERIVED-001 — SchemaDerivedToolProvider emits declarative CRUD tools)
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
	 *
	 * @spec openspec/specs/ai-mcp/spec.md
	 *   (Requirement: REQ-DERIVED-001 — SchemaDerivedToolProvider emits declarative CRUD tools)
	 */
	private function jsonSchemaSafe(mixed $property): array {
		if (is_array($property) === false) {
			return ['type' => 'string'];
		}

		$safe = $this->allowedKeywords(property: $property);

		$safe = $this->jsonSchemaSafeNested(property: $property, safe: $safe);

		// A property with no usable type still has to be describable.
		if (isset($safe['type']) === false && isset($safe['enum']) === false) {
			$safe['type'] = 'string';
		}

		return $safe;
	}//end jsonSchemaSafe()

	/**
	 * The allow-listed JSON Schema keywords of a property, in allow-list order.
	 *
	 * @param array<string, mixed> $property The declared property.
	 *
	 * @return array<string, mixed> The accepted keywords.
	 */
	private function allowedKeywords(array $property): array {
		$safe = [];
		foreach (self::JSON_SCHEMA_KEYWORDS as $keyword) {
			if (array_key_exists($keyword, $property) === true) {
				$safe[$keyword] = $property[$keyword];
			}
		}

		return $safe;
	}//end allowedKeywords()

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
	 *
	 * @spec openspec/specs/ai-mcp/spec.md
	 *   (Requirement: REQ-DERIVED-001 — SchemaDerivedToolProvider emits declarative CRUD tools)
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
	 *
	 * @spec openspec/specs/ai-mcp/spec.md
	 *   (Requirement: REQ-DERIVED-001 — SchemaDerivedToolProvider emits declarative CRUD tools)
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
		$parsed = $this->parseToolId(toolId: $toolId);
		if ($parsed === null) {
			return null;
		}

		$entry = $this->entryForSlug(slug: $parsed['slug']);
		if ($entry === null) {
			return null;
		}

		return [
			'schema' => $entry['schema'],
			'register' => $entry['register'],
			'verb' => $parsed['verb'],
		];
	}//end resolveEntry()

	/**
	 * Split a tool id of this app into its slug and a known verb.
	 *
	 * @param string $toolId Namespaced tool id.
	 *
	 * @return array{slug: string, verb: string}|null Null for another app's id or an unknown verb.
	 */
	private function parseToolId(string $toolId): ?array {
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

		return ['slug' => $slug, 'verb' => $verb];
	}//end parseToolId()

	/**
	 * The first schema entry whose slug matches.
	 *
	 * @param string $slug The schema slug.
	 *
	 * @return array{schema: Schema, register: Register|null}|null The entry, or null.
	 */
	private function entryForSlug(string $slug): ?array {
		foreach ($this->schemaEntries as $entry) {
			if ($this->schemaSlug(schema: $entry['schema']) === $slug) {
				return $entry;
			}
		}

		return null;
	}//end entryForSlug()

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

		return ToolInvocationDigest::idSummary(result: $result);
	}//end resultSummary()


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
