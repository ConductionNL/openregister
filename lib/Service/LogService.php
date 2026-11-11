<?php

/**
 * OpenRegister LogService
 *
 * Service class for handling audit trail logs in the OpenRegister application.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 */

namespace OCA\OpenRegister\Service;

use Exception;
use OCA\OpenRegister\Db\RegisterSchema;
use InvalidArgumentException;
use OCA\OpenRegister\Db\AuditTrailMapperInterface;
use OCA\OpenRegister\Db\ListQuery;
use OCA\OpenRegister\Db\MagicMapperInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Db\SchemaCatalogInterface;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Kernel\Contracts\ObjectRef;
use OCA\OpenRegister\Service\Rbac\ExplicitRevealTrailMasker;
use OCP\AppFramework\Db\DoesNotExistException;

/**
 * LogService handles audit trail logs
 *
 * Service class for handling audit trail logs in the OpenRegister application.
 * Provides methods for retrieving, filtering, and counting audit trail entries
 * for objects and system-wide operations.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) The object history resolves an object within its register and
 *   schema (#4037), so those entities and the lookup's two outcomes are part of this service's vocabulary.
 */
class LogService {

	/**
	 * Audit trail mapper
	 *
	 * Handles database operations for audit trail entries.
	 *
	 * @var AuditTrailMapperInterface Audit trail mapper instance
	 */
	private readonly AuditTrailMapperInterface $auditTrailMapper;

	/**
	 * Unified object mapper for magic tables
	 *
	 * Used to find objects across all magic tables.
	 *
	 * @var MagicMapperInterface Unified object mapper instance
	 */
	private readonly MagicMapperInterface $unifiedObjectMapper;

	/**
	 * Register mapper
	 *
	 * Reserved for future use in log filtering and validation.
	 *
	 * @var RegisterMapperInterface Register mapper instance
	 */
	private readonly RegisterMapperInterface $registerMapper;

	/**
	 * Schema mapper
	 *
	 * Reserved for future use in log filtering and validation.
	 *
	 * @var SchemaMapperInterface Schema mapper instance
	 */
	private readonly SchemaMapperInterface $schemaMapper;

	/**
	 * Masks explicit-reveal values in the change sets this service hands out.
	 *
	 * @var ExplicitRevealTrailMasker
	 */
	private readonly ExplicitRevealTrailMasker $revealMasker;

	/**
	 * Looks the object of a history request up in its register+schema table.
	 *
	 * @var LogObjectInTable
	 */
	private readonly LogObjectInTable $objectInTable;

	/**
	 * Shapes and renders the audit export.
	 *
	 * @var LogExportFormatter
	 */
	private readonly LogExportFormatter $exportFormatter;

	/**
	 * Constructor for LogService
	 *
	 * Initializes the LogService with required mapper dependencies for handling
	 * audit trail logs and related entities.
	 *
	 * @param AuditTrailMapperInterface $auditTrailMapper Mapper for audit trail database operations.
	 * @param SchemaCatalogInterface    $catalog          The register, schema and unified object (magic) mappers.
	 *
	 * @return void
	 */
	public function __construct(AuditTrailMapperInterface $auditTrailMapper, SchemaCatalogInterface $catalog) {
		$this->auditTrailMapper = $auditTrailMapper;
		$this->unifiedObjectMapper = $catalog->tables();
		$this->registerMapper = $catalog->registers();
		$this->schemaMapper = $catalog->schemas();
		$this->revealMasker = new ExplicitRevealTrailMasker(schemaMapper: $this->schemaMapper);
		$this->objectInTable = new LogObjectInTable(objectMapper: $this->unifiedObjectMapper);
		$this->exportFormatter = new LogExportFormatter();
	}//end __construct()

	/**
	 * Get logs for an object
	 *
	 * Retrieves audit trail logs for a specific object with optional filtering,
	 * pagination, sorting, and search capabilities. Validates that the object
	 * belongs to the specified register and schema.
	 *
	 * @param string $register The register identifier (slug or ID)
	 * @param string $schema The schema identifier (slug or ID)
	 * @param string $id The object ID to retrieve logs for
	 * @param array<string, mixed> $config Configuration array containing:
	 *                                     - limit: (int) Max items per page (default: 20)
	 *                                     - offset: (int|null) Items to skip for pagination
	 *                                     - page: (int|null) Page number (alternative to offset)
	 *                                     - filters: (array) Filter params (['action' => 'create'])
	 *                                     - sort: (array) Sort params (default: ['created' => 'DESC'])
	 *                                     - search: (string|null) Search term for log content
	 *
	 * @return \OCA\OpenRegister\Db\AuditTrail[] Array of audit trail log entries
	 *
	 * @throws \OCP\AppFramework\Db\DoesNotExistException If no object with that identifier exists in the register and schema
	 * @throws \OCP\AppFramework\Db\MultipleObjectsReturnedException If the identifier is a slug two objects of the register and schema hold
	 *
	 * @psalm-return array<\OCA\OpenRegister\Db\AuditTrail>
	 *
	 * @spec openspec/changes/retrofit-2026-05-25-bw2-svc-flat-2/tasks.md#task-4
	 */
	public function getLogs(string $register, string $schema, string $id, array $config = []): array {
		return $this->logsOf(ref: new ObjectRef(register: $register, schema: $schema, id: $id), config: $config);
	}//end getLogs()

	/**
	 * Get the audit trail logs of one object, as {@see getLogs()} describes.
	 *
	 * @param ObjectRef            $ref    The register, schema and object identifier.
	 * @param array<string, mixed> $config Configuration array (limit, offset, filters, sort, search).
	 *
	 * @return \OCA\OpenRegister\Db\AuditTrail[] Array of audit trail log entries
	 *
	 * @throws \OCP\AppFramework\Db\DoesNotExistException If no object with that identifier exists in the register and schema
	 * @throws \OCP\AppFramework\Db\MultipleObjectsReturnedException If the identifier is a slug two objects of the register and schema hold
	 *
	 * @spec openspec/changes/retrofit-2026-05-25-bw2-svc-flat-2/tasks.md#task-4
	 */
	public function logsOf(ObjectRef $ref, array $config = []): array {
		// Step 1: Resolve the object within the register and schema of the route
		// (deleted objects included, so the history stays readable after a soft-delete).
		$object = $this->findObjectInScope(ref: $ref);

		// Step 3: Add object UUID to filters to restrict logs to this object.
		$filters = $config['filters'] ?? [];
		$filters['object_uuid'] = $object->getUuid();

		// Note: We do NOT add register/schema filters here because:
		// 1. The object already ensures it belongs to the correct register/schema
		// 2. Adding those filters can cause issues if register/schema have been recreated with same slug
		// 3. The object ID is sufficient to uniquely identify audit trails
		// Step 4: Retrieve logs from audit trail mapper with pagination and filtering.
		return $this->auditTrailMapper->findTrail(
			query: new ListQuery(
				limit: ($config['limit'] ?? 20),
				offset: ($config['offset'] ?? 0),
				filters: $filters,
				sort: ($config['sort'] ?? ['created' => 'DESC']),
				search: ($config['search'] ?? null)
			)
		);
	}//end logsOf()

	/**
	 * Count logs for an object
	 *
	 * Counts total number of audit trail entries for a specific object.
	 * Validates that the object belongs to the specified register and schema.
	 *
	 * The count answers the SAME question the matching getLogs() call asks. A
	 * count taken without the caller's filters is a count of a different
	 * result set, and a caller paging on it walks off the end of a list that
	 * ended pages ago: `?action=update` on an object with 1733 rows returned 7
	 * of them and reported a total of 1733, which is 87 pages of nothing.
	 *
	 * @param string $register The register identifier (slug or ID)
	 * @param string $schema The schema identifier (slug or ID)
	 * @param string $id The object ID to count logs for
	 * @param array $config Configuration array containing:
	 *                      - filters: (array) The same filters passed to getLogs()
	 *                      - search: (string|null) The same search term passed to getLogs()
	 *
	 * @return int Number of log entries (0 or positive integer)
	 *
	 * @throws \OCP\AppFramework\Db\DoesNotExistException If no object with that identifier exists in the register and schema
	 * @throws \OCP\AppFramework\Db\MultipleObjectsReturnedException If the identifier is a slug two objects of the register and schema hold
	 *
	 * @psalm-return int<0, max>
	 *
	 * @spec openspec/changes/retrofit-2026-05-25-bw2-svc-flat-2/tasks.md#task-4
	 */
	public function count(string $register, string $schema, string $id, array $config = []): int {
		return $this->countOf(ref: new ObjectRef(register: $register, schema: $schema, id: $id), config: $config);
	}//end count()

	/**
	 * Count the audit trail logs of one object, as {@see count()} describes.
	 *
	 * @param ObjectRef            $ref    The register, schema and object identifier.
	 * @param array<string, mixed> $config Configuration array (filters, search).
	 *
	 * @return int Number of log entries (0 or positive integer)
	 *
	 * @throws \OCP\AppFramework\Db\DoesNotExistException If no object with that identifier exists in the register and schema
	 * @throws \OCP\AppFramework\Db\MultipleObjectsReturnedException If the identifier is a slug two objects of the register and schema hold
	 *
	 * @spec openspec/changes/retrofit-2026-05-25-bw2-svc-flat-2/tasks.md#task-4
	 */
	public function countOf(ObjectRef $ref, array $config = []): int {
		// Step 1: Resolve the object within the register and schema of the route
		// (deleted objects included, so the history stays readable after a soft-delete).
		$object = $this->findObjectInScope(ref: $ref);

		// Step 3: Get the logs this object matches, under the caller's own
		// filters, with the UUID filter added exactly as getLogs() adds it.
		// No pagination needed since we're only counting.
		$filters = $config['filters'] ?? [];
		$filters['object_uuid'] = $object->getUuid();

		$logs = $this->auditTrailMapper->findTrail(
			query: new ListQuery(limit: ListQuery::UNBOUNDED, filters: $filters, sort: ['created' => 'DESC'], search: ($config['search'] ?? null))
		);

		// Step 4: Return count of log entries.
		return count($logs);
	}//end countOf()

	/**
	 * The object whose history is asked for, looked up within the register and schema of the route.
	 *
	 * The lookup is the one the object routes use (`MagicMapper::findInRegisterSchemaTable()`):
	 * an id, uuid or slug of THIS register and schema, an exact id/uuid match winning over a
	 * slug, a slug two objects hold refused as ambiguous. Before #4037 the history was looked
	 * up across all tables and the register/schema check was swallowed, so a slug URL could
	 * answer with another schema's history. When the register or schema itself no longer
	 * resolves (soft-deleted), the scope cannot be built and the cross-table lookup remains,
	 * so the history of an object in a deleted register stays readable.
	 *
	 * @param ObjectRef $ref The register and schema identifiers (slug or ID) and the object identifier (ID, UUID or slug).
	 *
	 * @return ObjectEntity The object, deleted or not.
	 *
	 * @throws \OCP\AppFramework\Db\DoesNotExistException When no such object exists in the register and schema.
	 * @throws \OCP\AppFramework\Db\MultipleObjectsReturnedException When the slug is held by more than one live object,
	 *                                                                   or only by deleted ones.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md#requirement-a-slug-lookup-that-matches-more-than-one-object-in-a-register-and-schema-must-be-refused-as-ambiguous
	 */
	private function findObjectInScope(ObjectRef $ref): ObjectEntity {
		$id = $ref->id;
		try {
			$registerEntity = $this->registerMapper->find($ref->register, _multitenancy: false, _rbac: false);
			$schemaEntity = $this->schemaMapper->find($ref->schema, _multitenancy: false, _rbac: false);
		} catch (DoesNotExistException $e) {
			// Register or schema gone (soft-deleted): no scope to search in; the
			// history of the object itself stays readable, as before. Any other
			// failure propagates: it is no reason to drop the scope.
			return $this->findAcrossTables(id: $id);
		}

		return $this->objectInTable->find(
			object: new TableObjectRef(id: $id, table: new RegisterSchema(register: $registerEntity, schema: $schemaEntity))
		);
	}//end findObjectInScope()

	/**
	 * The object, deleted or not, wherever it is stored, without access filters.
	 *
	 * @param string $id The object identifier.
	 *
	 * @return ObjectEntity The object.
	 */
	private function findAcrossTables(string $id): ObjectEntity {
		$result = $this->unifiedObjectMapper->findAcrossAllSources(
			identifier: $id,
			includeDeleted: true,
			_multitenancy: false,
			_rbac: false
		);
		return $result['object'];
	}//end findAcrossTables()

	/**
	 * Get all audit trail logs with optional filtering
	 *
	 * @param array $config Configuration array containing:
	 *                      - limit: (int) Maximum number of items per page
	 *                      - offset: (int|null) Number of items to skip
	 *                      - page: (int|null) Current page number
	 *                      - filters: (array) Filter parameters
	 *                      - sort: (array) Sort parameters ['field' => 'ASC|DESC']
	 *                      - search: (string|null) Search term
	 *
	 * @return \OCA\OpenRegister\Db\AuditTrail[] Array of audit trail entries
	 *
	 * @psalm-return array<\OCA\OpenRegister\Db\AuditTrail>
	 *
	 * @spec exclude Thin delegation to AuditTrailMapper::findAll with default pagination/sort; no business logic.
	 */
	public function getAllLogs(array $config = []): array {
		return $this->auditTrailMapper->findTrail(
			query: new ListQuery(
				limit: ($config['limit'] ?? 20),
				offset: ($config['offset'] ?? 0),
				filters: ($config['filters'] ?? []),
				sort: ($config['sort'] ?? ['created' => 'DESC']),
				search: ($config['search'] ?? null)
			)
		);
	}//end getAllLogs()

	/**
	 * Count all audit trail logs with optional filtering
	 *
	 * @param array $filters Optional filters to apply
	 *
	 * @return int Number of audit trail entries
	 *
	 * @psalm-return int<0, max>
	 *
	 * @spec exclude Thin delegation to AuditTrailMapper::findAll + count(); no business logic.
	 */
	public function countAllLogs(array $filters = []): int {
		$logs = $this->auditTrailMapper->findTrail(query: ListQuery::unbounded(filters: $filters, sort: ['created' => 'DESC']));
		return count($logs);
	}//end countAllLogs()

	/**
	 * Get a single audit trail log by ID
	 *
	 * @param int $id The audit trail ID
	 *
	 * @return mixed The audit trail entry
	 * @throws \OCP\AppFramework\Db\DoesNotExistException If audit trail not found
	 */
	public function getLog(int $id) {
		return $this->auditTrailMapper->find($id);
	}//end getLog()

	/**
	 * Audit entries with their explicit-reveal values masked, for every reader
	 * including administrators (the change sets of a single entry or an export).
	 *
	 * @param array<int, mixed> $entries Audit entries (AuditTrail entities or arrays).
	 *
	 * @return array<int, mixed> The entries; a masked one as an array.
	 *
	 * @spec openspec/changes/property-explicit-reveal/specs/row-field-level-security/spec.md
	 */
	public function maskExplicitReveal(array $entries): array {
		return $this->revealMasker->mask(entries: $entries);
	}//end maskExplicitReveal()

	/**
	 * Export audit trail logs with specified format and filters
	 *
	 * @param string $format Export format: 'csv', 'json', 'xml', 'txt'
	 * @param array $config Configuration array containing:
	 *                      - filters: (array) Filter
	 *                      parameters - includeChanges:
	 *                      (bool) Whether to include
	 *                      change data - includeMetadata:
	 *                      (bool) Whether to include
	 *                      metadata - search:
	 *                      (string|null) Search term
	 *
	 * @return (bool|string)[]
	 *
	 * @throws \InvalidArgumentException If unsupported format is specified
	 *
	 * @psalm-return array{content: bool|string, filename: string, contentType: string}
	 *
	 * @spec openspec/changes/retrofit-2026-05-25-bw2-svc-flat-2/tasks.md#task-4
	 */
	public function exportLogs(string $format, array $config = []): array {
		// Get all logs with current filters.
		$logs = $this->auditTrailMapper->findTrail(
			query: new ListQuery(
				limit: ListQuery::UNBOUNDED,
				filters: ($config['filters'] ?? []),
				sort: ['created' => 'DESC'],
				search: ($config['search'] ?? null)
			)
		);

		return $this->exportRows(format: $format, logs: $logs, config: $config);
	}//end exportLogs()

	/**
	 * Export rows the caller already selected.
	 *
	 * The audit page selects its rows through the keyset query with its own
	 * filters; this renders exactly those rows and re-queries nothing, so the
	 * file cannot disagree with the list it was exported from.
	 *
	 * @param string                                  $format One of csv, json, xml, txt.
	 * @param array<int, \OCA\OpenRegister\Db\AuditTrail> $logs   The rows.
	 * @param array<string, mixed>                    $config includeChanges, includeMetadata.
	 *
	 * @return array{content: string, filename: string, contentType: string} The file.
	 *
	 * @throws InvalidArgumentException When the format is not supported.
	 *
	 * @spec openspec/changes/audit-log-page/specs/audit-trail-immutable/spec.md#requirement-the-filtered-audit-list-exports-with-its-hash-chain
	 */
	public function exportRows(string $format, array $logs, array $config = []): array {
		$rows = $this->exportFormatter->rows(entries: $this->maskExplicitReveal(entries: $logs), config: $config);

		return $this->exportFormatter->render(format: $format, rows: $rows);
	}//end exportRows()
}//end class
