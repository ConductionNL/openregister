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

use DateTime;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
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
class AuditQueryService
{

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
     * @param ObjectService  $objectService  Cross-schema object search (audit entries ARE objects).
     * @param RegisterMapper $registerMapper Register lookup + register/schema relationship helpers.
     * @param SchemaMapper   $schemaMapper   Schema lookup.
     */
    public function __construct(
        private readonly ObjectService $objectService,
        private readonly RegisterMapper $registerMapper,
        private readonly SchemaMapper $schemaMapper
    ) {

    }//end __construct()

    /**
     * Query audit-entry objects across all apps/registers/schemas.
     *
     * @param array<string, mixed> $filters Supported keys: registerId, schemaId,
     *                                      objectId, app (alias for registerId),
     *                                      timestampStart, timestampEnd, sort
     *                                      (default 'created:desc').
     * @param int                  $limit   Requested page size; clamped to [1, 200].
     * @param int                  $offset  Requested offset; clamped to >= 0.
     *
     * @return array{entries: array<int, array<string, mixed>>, total: int, limit: int, offset: int}
     *
     * @spec openspec/changes/public-audit-query-endpoint/tasks.md#task-1.1
     * @spec openspec/changes/public-audit-query-endpoint/tasks.md#task-1.2
     */
    public function query(array $filters, int $limit, int $offset): array
    {
        $limit  = $this->clampLimit(limit: $limit);
        $offset = max(0, $offset);

        $pairs = $this->resolveRegisterSchemaPairs(filters: $filters);

        if (empty($pairs) === true) {
            // Graceful handling of an unconfigured audit schema: no matching
            // register/schema pair, no error, just an empty page.
            return [
                'entries' => [],
                'total'   => 0,
                'limit'   => $limit,
                'offset'  => $offset,
            ];
        }

        $rows = $this->collectEntries(pairs: $pairs, filters: $filters);
        $rows = $this->applyTimestampFilter(rows: $rows, filters: $filters);
        $rows = $this->sortRows(rows: $rows, filters: $filters);

        $total = count($rows);
        $page  = array_slice($rows, $offset, $limit);

        return [
            'entries' => array_map(fn (array $row): array => $this->mapEntry(row: $row), $page),
            'total'   => $total,
            'limit'   => $limit,
            'offset'  => $offset,
        ];

    }//end query()

    /**
     * Clamp a requested page size into [1, MAX_LIMIT].
     *
     * @param int $limit Requested limit (may be <= 0 or > MAX_LIMIT).
     *
     * @return int Clamped limit, defaulting to DEFAULT_LIMIT when 0/absent.
     */
    private function clampLimit(int $limit): int
    {
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
    private function resolveRegisterSchemaPairs(array $filters): array
    {
        $registerSlug = $filters['registerId'] ?? $filters['app'] ?? null;
        $schemaSlug   = $filters['schemaId'] ?? null;

        // Explicit schema requested: trust it, regardless of naming.
        if ($schemaSlug !== null && $schemaSlug !== '') {
            return $this->pairsForExplicitSchema(registerSlug: $registerSlug, schemaSlug: $schemaSlug);
        }

        // No explicit schema: fall back to the "looks like audit" convention
        // so the endpoint does not default to exposing every business object.
        if ($registerSlug !== null && $registerSlug !== '') {
            try {
                $register = $this->registerMapper->find(id: $registerSlug);
            } catch (DoesNotExistException $e) {
                return [];
            }

            return $this->auditSchemaPairsForRegister(register: $register);
        }

        $pairs = [];
        foreach ($this->registerMapper->findAll() as $register) {
            $pairs = array_merge($pairs, $this->auditSchemaPairsForRegister(register: $register));
        }

        return $pairs;

    }//end resolveRegisterSchemaPairs()

    /**
     * Resolve pairs when an explicit schemaId filter was supplied.
     *
     * @param mixed $registerSlug Register slug/id filter, or null.
     * @param mixed $schemaSlug   Schema slug/id filter (non-empty).
     *
     * @return array<int, array{0: Register, 1: Schema}>
     */
    private function pairsForExplicitSchema($registerSlug, $schemaSlug): array
    {
        try {
            $schema = $this->schemaMapper->find(id: $schemaSlug);
        } catch (DoesNotExistException $e) {
            return [];
        }

        if ($registerSlug !== null && $registerSlug !== '') {
            try {
                $register = $this->registerMapper->find(id: $registerSlug);
            } catch (DoesNotExistException $e) {
                return [];
            }

            return [[$register, $schema]];
        }

        $pairs = [];
        foreach ($this->registerMapper->getAllRegisterIdsWithSchema(schemaId: $schema->getId()) as $registerId) {
            try {
                $pairs[] = [$this->registerMapper->find(id: $registerId), $schema];
            } catch (DoesNotExistException $e) {
                continue;
            }
        }

        return $pairs;

    }//end pairsForExplicitSchema()

    /**
     * All schemas on a register whose slug/title looks like an audit schema.
     *
     * @param Register $register The register to inspect.
     *
     * @return array<int, array{0: Register, 1: Schema}>
     */
    private function auditSchemaPairsForRegister(Register $register): array
    {
        $pairs = [];
        foreach ($this->registerMapper->getSchemasByRegisterId(registerId: $register->getId()) as $schema) {
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
    private function looksLikeAuditSchema(Schema $schema): bool
    {
        $slug  = strtolower($schema->getSlug() ?? '');
        $title = strtolower($schema->getTitle() ?? '');

        return str_contains($slug, 'audit') === true || str_contains($title, 'audit') === true;

    }//end looksLikeAuditSchema()

    /**
     * Search every resolved (register, schema) pair and flatten the results.
     *
     * @param array<int, array{0: Register, 1: Schema}> $pairs   Pairs to search.
     * @param array<string, mixed>                      $filters Raw filters (for the objectId field filter).
     *
     * @return array<int, array{entity: \OCA\OpenRegister\Db\ObjectEntity, registerSlug: string, schemaSlug: string}>
     */
    private function collectEntries(array $pairs, array $filters): array
    {
        $objectFilters = [];
        if (empty($filters['objectId']) === false) {
            $objectFilters['objectId'] = $filters['objectId'];
        }

        $rows = [];
        foreach ($pairs as [$register, $schema]) {
            try {
                $result = $this->objectService->searchObjectsBySlug(
                    registerSlug: (string) $register->getSlug(),
                    schemaSlug: (string) $schema->getSlug(),
                    filters: $objectFilters
                );
            } catch (DoesNotExistException $e) {
                continue;
            }

            if (is_int($result) === true) {
                continue;
            }

            foreach ($result as $entity) {
                $rows[] = [
                    'entity'       => $entity,
                    'registerSlug' => (string) $register->getSlug(),
                    'schemaSlug'   => (string) $schema->getSlug(),
                ];
            }
        }//end foreach

        return $rows;

    }//end collectEntries()

    /**
     * Filter rows by the (optional) timestampStart / timestampEnd ISO-8601 bounds.
     *
     * @param array<int, array<string, mixed>> $rows    Rows collected so far.
     * @param array<string, mixed>             $filters Raw filters.
     *
     * @return array<int, array<string, mixed>> Filtered rows.
     */
    private function applyTimestampFilter(array $rows, array $filters): array
    {
        $start = $this->parseTimestamp(value: $filters['timestampStart'] ?? null);
        $end   = $this->parseTimestamp(value: $filters['timestampEnd'] ?? null);

        if ($start === null && $end === null) {
            return $rows;
        }

        return array_values(
            array_filter(
                $rows,
                function (array $row) use ($start, $end): bool {
                    $created = $row['entity']->getCreated();
                    if ($created === null) {
                        return false;
                    }

                    if ($start !== null && $created < $start) {
                        return false;
                    }

                    if ($end !== null && $created > $end) {
                        return false;
                    }

                    return true;
                }
            )
        );

    }//end applyTimestampFilter()

    /**
     * Parse a filter value into a DateTime, tolerating invalid/absent input.
     *
     * @param mixed $value Raw filter value.
     *
     * @return DateTime|null Parsed timestamp, or null when absent/invalid.
     */
    private function parseTimestamp($value): ?DateTime
    {
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
     * @param array<int, array<string, mixed>> $rows    Rows to sort.
     * @param array<string, mixed>             $filters Raw filters.
     *
     * @return array<int, array<string, mixed>> Sorted rows.
     */
    private function sortRows(array $rows, array $filters): array
    {
        $sort            = (string) ($filters['sort'] ?? 'created:desc');
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
    private function mapEntry(array $row): array
    {
        $entity  = $row['entity'];
        $data    = $entity->getObject() ?? [];
        $created = $entity->getCreated();

        $createdIso = null;
        if ($created !== null) {
            $createdIso = $created->format(DateTime::ATOM);
        }

        return [
            'id'         => $entity->getUuid(),
            'registerId' => $row['registerSlug'],
            'schemaId'   => $row['schemaSlug'],
            'objectId'   => ($data['objectId'] ?? null),
            'data'       => $data,
            'created'    => $createdIso,
            'userId'     => $entity->getOwner(),
        ];

    }//end mapEntry()
}//end class
