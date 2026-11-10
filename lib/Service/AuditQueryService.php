<?php

/**
 * OpenRegister Audit Query Service
 *
 * Unified, cross-app query layer over audit-entry objects (e.g. procest's
 * `aiAuditEntry`, parafering's `paraferingAuditEntry`). Apps record these as
 * regular OpenRegister objects under a schema of their own choosing; this
 * service lets admins query and export them without knowing which register
 * or schema a given app used.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/public-audit-query-endpoint/tasks.md#task-1.1
 * @spec openspec/changes/public-audit-query-endpoint/tasks.md#task-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

use OCA\OpenRegister\Kernel\Contracts\ListQuery;
use DateTime;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaCatalogInterface;
use OCA\OpenRegister\Kernel\Contracts\RegisterSchemaRef;
use OCP\AppFramework\Db\DoesNotExistException;

/**
 * Queries audit-entry objects (any app, any register/schema) via ObjectService.
 *
 * Audit-entry objects are ordinary OpenRegister objects; this service does
 * not introduce a new storage concept. When neither `registerId`/`app` nor
 * `schemaId` is supplied, it falls back to a naming convention (schema slug
 * or title containing "audit") so the endpoint stays schema-agnostic while
 * still honouring the "no data exposure" requirement — it must not return
 * every business object in the instance by default.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 *
 * @spec openspec/changes/public-audit-query-endpoint/tasks.md#task-1.1
 */
class AuditQueryService implements AuditQueryServiceInterface {

	/**
	 * Maximum allowed page size.
	 *
	 * @var int
	 */
	private const MAX_LIMIT = 200;

	/**
	 * Default page size when none (or an invalid one) is supplied.
	 *
	 * @var int
	 */
	private const DEFAULT_LIMIT = 50;

	/**
	 * Constructor.
	 *
	 * @param ObjectOperationsInterface $objectService Cross-schema object search (audit entries ARE objects).
	 * @param SchemaCatalogInterface    $catalog       Register and schema lookup + register/schema relationship helpers.
	 */
	public function __construct(
		private readonly ObjectOperationsInterface $objectService,
		private readonly SchemaCatalogInterface $catalog,
	) {
	}//end __construct()

	/**
	 * Query audit-entry objects across all apps/registers/schemas.
	 *
	 * @param ListQuery $query The filters (supported keys: registerId, schemaId, objectId, app (alias
	 *                         for registerId), timestampStart, timestampEnd, sort (default
	 *                         'created:desc')), the page size (clamped to [1, 200]) and the offset
	 *                         (clamped to >= 0).
	 *
	 * @return array{entries: array<int, array<string, mixed>>, total: int, limit: int, offset: int}
	 *
	 * @spec openspec/changes/public-audit-query-endpoint/tasks.md#task-1.1
	 * @spec openspec/changes/public-audit-query-endpoint/tasks.md#task-1.2
	 */
	public function query(ListQuery $query): array {
		return $this->pageOf(
			filters: $query->filters,
			window: ['limit' => $this->clampLimit(limit: $query->limit), 'offset' => max(0, (int)$query->offset)]
		);

	}//end query()

	/**
	 * One page of the audit entries the filters select.
	 *
	 * @param array<string, mixed>             $filters Raw filters, see {@see self::query()}.
	 * @param array{limit: int, offset: int} $window  The clamped page window.
	 *
	 * @return array{entries: array<int, array<string, mixed>>, total: int, limit: int, offset: int}
	 */
	private function pageOf(array $filters, array $window): array {
		$pairs = $this->resolveRegisterSchemaPairs(filters: $filters);

		if (empty($pairs) === true) {
			// Graceful handling of an unconfigured audit schema: no matching
			// register/schema pair, no error, just an empty page.
			return ['entries' => [], 'total' => 0] + $window;
		}

		$rows = $this->collectEntries(pairs: $pairs, filters: $filters);
		$rows = $this->applyTimestampFilter(rows: $rows, filters: $filters);
		$rows = $this->sortRows(rows: $rows, filters: $filters);

		$page = array_slice($rows, $window['offset'], $window['limit']);

		return [
			'entries' => array_map(fn (array $row): array => $this->mapEntry(row: $row), $page),
			'total' => count($rows),
		] + $window;
	}//end pageOf()

	/**
	 * Clamp a requested page size into [1, MAX_LIMIT].
	 *
	 * @param int $limit Requested limit (may be <= 0 or > MAX_LIMIT).
	 *
	 * @return int Clamped limit, defaulting to DEFAULT_LIMIT when 0/absent.
	 */
	private function clampLimit(int $limit): int {
		if ($limit <= 0) {
			// Not just "below the minimum" — absent/invalid input defaults
			// to DEFAULT_LIMIT rather than clamping up to MIN_LIMIT, per the
			// documented contract ("default 50" on 0/absent).
			$limit = self::DEFAULT_LIMIT;
		}

		if ($limit > self::MAX_LIMIT) {
			return self::MAX_LIMIT;
		}

		return $limit;
	}//end clampLimit()

	/**
	 * Resolve the (Register, Schema) pairs to search, based on filters.
	 *
	 * - registerId/schemaId both given: exactly that pair.
	 * - only registerId (or its `app` alias) given: every schema on that
	 *   register that looks like an audit schema.
	 * - only schemaId given: that schema on every register that references it.
	 * - neither given: every schema (on every register) that looks like an
	 *   audit schema, i.e. slug or title contains "audit".
	 *
	 * @param array<string, mixed> $filters Raw filters, see {@see self::query()}.
	 *
	 * @return array<int, array{0: Register, 1: Schema}>
	 */
	private function resolveRegisterSchemaPairs(array $filters): array {
		$registerSlug = $filters['registerId'] ?? $filters['app'] ?? null;
		$schemaSlug = $filters['schemaId'] ?? null;

		// Explicit schema requested: trust it, regardless of naming.
		if ($this->isGiven(value: $schemaSlug) === true) {
			return $this->pairsForExplicitSchema(registerSlug: $registerSlug, schemaSlug: $schemaSlug);
		}

		// No explicit schema: fall back to the "looks like audit" convention
		// so the endpoint does not default to exposing every business object.
		if ($this->isGiven(value: $registerSlug) === true) {
			return $this->auditSchemaPairsForRegisterSlug(registerSlug: $registerSlug);
		}

		$pairs = [];
		foreach ($this->catalog->registers()->findAll() as $register) {
			$pairs = array_merge($pairs, $this->auditSchemaPairsForRegister(register: $register));
		}

		return $pairs;
	}//end resolveRegisterSchemaPairs()

	/**
	 * Whether a filter value was supplied: neither null nor the empty string.
	 *
	 * @param mixed $value The filter value.
	 *
	 * @return bool True when given.
	 */
	private function isGiven(mixed $value): bool {
		return $value !== null && $value !== '';
	}//end isGiven()

	/**
	 * The audit-looking schemas of one register, none when it does not exist.
	 *
	 * @param mixed $registerSlug Register slug/id filter (non-empty).
	 *
	 * @return array<int, array{0: Register, 1: Schema}>
	 */
	private function auditSchemaPairsForRegisterSlug($registerSlug): array {
		try {
			$register = $this->catalog->registers()->find(id: $registerSlug);
		} catch (DoesNotExistException $e) {
			return [];
		}

		return $this->auditSchemaPairsForRegister(register: $register);
	}//end auditSchemaPairsForRegisterSlug()

	/**
	 * Resolve pairs when an explicit schemaId filter was supplied.
	 *
	 * @param mixed $registerSlug Register slug/id filter, or null.
	 * @param mixed $schemaSlug Schema slug/id filter (non-empty).
	 *
	 * @return array<int, array{0: Register, 1: Schema}>
	 */
	private function pairsForExplicitSchema($registerSlug, $schemaSlug): array {
		try {
			$schema = $this->catalog->schemas()->find(id: $schemaSlug);
		} catch (DoesNotExistException $e) {
			return [];
		}

		if ($this->isGiven(value: $registerSlug) === true) {
			return $this->pairOnRegister(registerSlug: $registerSlug, schema: $schema);
		}

		return $this->pairsOnRegistersWith(schema: $schema);
	}//end pairsForExplicitSchema()

	/**
	 * The one pair of a schema on a named register, none when it does not exist.
	 *
	 * @param mixed  $registerSlug Register slug/id filter (non-empty).
	 * @param Schema $schema       The schema.
	 *
	 * @return array<int, array{0: Register, 1: Schema}>
	 */
	private function pairOnRegister($registerSlug, Schema $schema): array {
		try {
			$register = $this->catalog->registers()->find(id: $registerSlug);
		} catch (DoesNotExistException $e) {
			return [];
		}

		return [[$register, $schema]];
	}//end pairOnRegister()

	/**
	 * The schema on every register that references it; a missing register is skipped.
	 *
	 * @param Schema $schema The schema.
	 *
	 * @return array<int, array{0: Register, 1: Schema}>
	 */
	private function pairsOnRegistersWith(Schema $schema): array {
		$pairs = [];
		foreach ($this->catalog->registers()->getAllRegisterIdsWithSchema(schemaId: $schema->getId()) as $registerId) {
			try {
				$pairs[] = [$this->catalog->registers()->find(id: $registerId), $schema];
			} catch (DoesNotExistException $e) {
				continue;
			}
		}

		return $pairs;
	}//end pairsOnRegistersWith()

	/**
	 * All schemas on a register whose slug/title looks like an audit schema.
	 *
	 * @param Register $register The register to inspect.
	 *
	 * @return array<int, array{0: Register, 1: Schema}>
	 */
	private function auditSchemaPairsForRegister(Register $register): array {
		$pairs = [];
		foreach ($this->catalog->registers()->getSchemasByRegisterId(registerId: $register->getId()) as $schema) {
			if ($this->looksLikeAuditSchema(schema: $schema) === true) {
				$pairs[] = [$register, $schema];
			}
		}

		return $pairs;
	}//end auditSchemaPairsForRegister()

	/**
	 * Naming-convention heuristic: does this schema look like an audit schema?
	 *
	 * @param Schema $schema The schema to inspect.
	 *
	 * @return bool True when the slug or title contains "audit" (case-insensitive).
	 */
	private function looksLikeAuditSchema(Schema $schema): bool {
		$slug = strtolower($schema->getSlug() ?? '');
		$title = strtolower($schema->getTitle() ?? '');

		return str_contains($slug, 'audit') === true || str_contains($title, 'audit') === true;
	}//end looksLikeAuditSchema()

	/**
	 * Search every resolved (register, schema) pair and flatten the results.
	 *
	 * @param array<int, array{0: Register, 1: Schema}> $pairs Pairs to search.
	 * @param array<string, mixed> $filters Raw filters (for the objectId field filter).
	 *
	 * @return array<int, array{entity: \OCA\OpenRegister\Db\ObjectEntity, registerSlug: string, schemaSlug: string}>
	 */
	private function collectEntries(array $pairs, array $filters): array {
		$objectFilters = [];
		if (empty($filters['objectId']) === false) {
			$objectFilters['objectId'] = $filters['objectId'];
		}

		$rows = [];
		foreach ($pairs as $pair) {
			array_push($rows, ...$this->entriesOfPair(pair: $pair, objectFilters: $objectFilters));
		}

		return $rows;
	}//end collectEntries()

	/**
	 * The audit entries of one (register, schema) pair; none when it is gone or answers a count.
	 *
	 * @param array{0: Register, 1: Schema} $pair          The pair to search.
	 * @param array<string, mixed>          $objectFilters The object field filters.
	 *
	 * @return array<int, array{entity: \OCA\OpenRegister\Db\ObjectEntity, registerSlug: string, schemaSlug: string}>
	 */
	private function entriesOfPair(array $pair, array $objectFilters): array {
		[$register, $schema] = $pair;
		try {
			$result = $this->objectService->searchObjectsBySlug(
				target: new RegisterSchemaRef(register: (string)$register->getSlug(), schema: (string)$schema->getSlug()),
				filters: $objectFilters
			);
		} catch (DoesNotExistException $e) {
			return [];
		}

		if (is_int($result) === true) {
			return [];
		}

		$rows = [];
		foreach ($result as $entity) {
			$rows[] = [
				'entity' => $entity,
				'registerSlug' => (string)$register->getSlug(),
				'schemaSlug' => (string)$schema->getSlug(),
			];
		}

		return $rows;
	}//end entriesOfPair()

	/**
	 * Filter rows by the (optional) timestampStart / timestampEnd ISO-8601 bounds.
	 *
	 * @param array<int, array<string, mixed>> $rows Rows collected so far.
	 * @param array<string, mixed> $filters Raw filters.
	 *
	 * @return array<int, array<string, mixed>> Filtered rows.
	 */
	private function applyTimestampFilter(array $rows, array $filters): array {
		$bounds = [
			'start' => $this->parseTimestamp(value: $filters['timestampStart'] ?? null),
			'end' => $this->parseTimestamp(value: $filters['timestampEnd'] ?? null),
		];

		if ($bounds['start'] === null && $bounds['end'] === null) {
			return $rows;
		}

		return array_values(
			array_filter(
				$rows,
				fn (array $row): bool => $this->isWithinBounds(row: $row, bounds: $bounds)
			)
		);

	}//end applyTimestampFilter()

	/**
	 * Whether a row was created within the bounds; a row without a creation instant never is.
	 *
	 * @param array<string, mixed>                       $row    One collected row.
	 * @param array{start: DateTime|null, end: DateTime|null} $bounds The inclusive bounds; null is open.
	 *
	 * @return bool True when inside both bounds.
	 */
	private function isWithinBounds(array $row, array $bounds): bool {
		$created = $row['entity']->getCreated();
		if ($created === null) {
			return false;
		}

		$afterStart = $bounds['start'] === null || ($created < $bounds['start']) === false;
		$beforeEnd = $bounds['end'] === null || ($created > $bounds['end']) === false;

		return $afterStart === true && $beforeEnd === true;
	}//end isWithinBounds()

	/**
	 * Parse a filter value into a DateTime, tolerating invalid/absent input.
	 *
	 * @param mixed $value Raw filter value.
	 *
	 * @return DateTime|null Parsed timestamp, or null when absent/invalid.
	 */
	private function parseTimestamp($value): ?DateTime {
		if (is_string($value) === false || $value === '') {
			return null;
		}

		try {
			return new DateTime($value);
		} catch (\Exception $e) {
			return null;
		}

	}//end parseTimestamp()

	/**
	 * Sort rows per the `sort` filter (format `field:direction`), default `created:desc`.
	 *
	 * Only `created` is a supported sort field today; any other field falls
	 * back to the default so a bad/unknown sort value cannot error the request.
	 *
	 * @param array<int, array<string, mixed>> $rows Rows to sort.
	 * @param array<string, mixed> $filters Raw filters.
	 *
	 * @return array<int, array<string, mixed>> Sorted rows.
	 */
	private function sortRows(array $rows, array $filters): array {
		$sort = (string)($filters['sort'] ?? 'created:desc');
		[$field, $order] = array_pad(explode(':', $sort, 2), 2, 'desc');

		if ($field !== 'created') {
			$field = 'created';
		}

		$direction = 1;
		if (strtolower($order) === 'desc') {
			$direction = -1;
		}

		usort(
			$rows,
			function (array $a, array $b) use ($direction): int {
				$aCreated = $a['entity']->getCreated();
				$bCreated = $b['entity']->getCreated();

				if ($aCreated === null || $bCreated === null) {
					return 0;
				}

				return $direction * ($aCreated <=> $bCreated);
			}
		);

		return $rows;
	}//end sortRows()

	/**
	 * Map a collected row to the public response shape.
	 *
	 * Only what was logged is exposed — the audit entry's own object data —
	 * never the current state of whatever it describes.
	 *
	 * @param array<string, mixed> $row One row from {@see self::collectEntries()}.
	 *
	 * @return array<string, mixed>
	 */
	private function mapEntry(array $row): array {
		$entity = $row['entity'];
		$data = $entity->getObject() ?? [];
		$created = $entity->getCreated();

		$createdIso = null;
		if ($created !== null) {
			$createdIso = $created->format(DateTime::ATOM);
		}

		return [
			'id' => $entity->getUuid(),
			'registerId' => $row['registerSlug'],
			'schemaId' => $row['schemaSlug'],
			'objectId' => ($data['objectId'] ?? null),
			'data' => $data,
			'created' => $createdIso,
			'userId' => $entity->getOwner(),
		];

	}//end mapEntry()
}//end class
