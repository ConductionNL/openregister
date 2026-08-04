<?php

/**
 * OpenRegister ArchivalRetentionTask
 *
 * Hourly cron that sweeps every schema declaring `x-openregister-archival`
 * and deletes rows past their effective retention. The sweep is the ONLY
 * legitimate delete path on archival schemas — user-driven deletes are
 * rejected with HTTP 403 SCHEMA_ARCHIVAL_IMMUTABLE.
 *
 * For each `(register, schema)` pair with archival declared:
 *   1. Resolve the magic table name via `MagicTableHandler::getTableNameForRegisterSchema()`.
 *   2. Run a single native `SELECT _uuid, _created, …` against the table.
 *   3. For each row evaluate `RetentionEvaluator::evaluate()`.
 *   4. Rows whose `expiresAt < now()` are deleted via
 *      `ObjectService::deleteObject(..., _retentionSweep: true)` so the
 *      immutability gate is bypassed but the standard audit-trail entry
 *      still fires.
 *   5. Emit one structured log entry per schema:
 *      `{schemaSlug, scanned, expired, deleted}`.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Cron
 * @package  OCA\OpenRegister\Cron
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/add-archival-annotation-support/tasks.md#task-5
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Cron;

use DateTimeImmutable;
use DateTimeInterface;
use Exception;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Archival\RetentionEvaluator;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJob;
use OCP\BackgroundJob\TimedJob;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;

/**
 * Background job that enforces `x-openregister-archival` retention rules.
 *
 * Scheduled hourly, time-insensitive, single-instance.
 *
 * @psalm-suppress UnusedClass Registered in appinfo/info.xml <background-jobs>.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 *
 * @spec openspec/changes/add-archival-annotation-support/tasks.md#task-5
 */
class ArchivalRetentionTask extends TimedJob
{

    /**
     * Database connection for native row scans.
     *
     * @var IDBConnection
     */
    private readonly IDBConnection $db;

    /**
     * Register repository used to enumerate active registers.
     *
     * @var RegisterMapper
     */
    private readonly RegisterMapper $registerMapper;

    /**
     * Schema repository used to enumerate annotated schemas.
     *
     * @var SchemaMapper
     */
    private readonly SchemaMapper $schemaMapper;

    /**
     * Magic-table resolver used to map registers/schemas to physical tables.
     *
     * @var MagicMapper
     */
    private readonly MagicMapper $magicMapper;

    /**
     * Object service used to perform the sweep deletes.
     *
     * @var ObjectService
     */
    private readonly ObjectService $objectService;

    /**
     * Logger for per-schema summaries.
     *
     * @var LoggerInterface
     */
    private readonly LoggerInterface $logger;

    /**
     * Cached retention evaluator (stateless apart from DI).
     *
     * @var RetentionEvaluator
     */
    private RetentionEvaluator $retentionEvaluator;

    /**
     * Constructor.
     *
     * @param ITimeFactory    $time           Time factory for TimedJob scheduling.
     * @param IDBConnection   $db             Database connection for native SQL row scan.
     * @param RegisterMapper  $registerMapper Register repository.
     * @param SchemaMapper    $schemaMapper   Schema repository.
     * @param MagicMapper     $magicMapper    Magic-table resolver.
     * @param ObjectService   $objectService  Service used to perform the sweep deletes.
     * @param LoggerInterface $logger         Logger for per-schema summaries.
     */
    public function __construct(
        ITimeFactory $time,
        IDBConnection $db,
        RegisterMapper $registerMapper,
        SchemaMapper $schemaMapper,
        MagicMapper $magicMapper,
        ObjectService $objectService,
        LoggerInterface $logger
    ) {
        parent::__construct(time: $time);

        $this->db = $db;
        $this->registerMapper = $registerMapper;
        $this->schemaMapper   = $schemaMapper;
        $this->magicMapper    = $magicMapper;
        $this->objectService  = $objectService;
        $this->logger         = $logger;

        $this->retentionEvaluator = new RetentionEvaluator(logger: $logger);

        // Run every hour (3600 seconds).
        $this->setInterval(seconds: 3600);

        // Delay until low-load time.
        $this->setTimeSensitivity(sensitivity: IJob::TIME_INSENSITIVE);

        // Only run one instance of this job at a time.
        $this->setAllowParallelRuns(allow: false);

    }//end __construct()

    /**
     * Execute the retention sweep across every archival-annotated schema.
     *
     * @param mixed $argument Background-job argument (unused).
     *
     * @return void
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     *
     * @spec openspec/specs/retention-management/spec.md
     */
    protected function run($argument): void
    {
        $now = new DateTimeImmutable();

        try {
            $registers = $this->registerMapper->findAll(_rbac: false, _multitenancy: false);
        } catch (Exception $error) {
            $this->logger->error(
                '[ArchivalRetentionTask] Failed to load registers: '.$error->getMessage(),
                ['app' => 'openregister', 'exception' => $error]
            );
            return;
        }

        foreach ($registers as $register) {
            // Defensive: findAll() returns Register[] per docblock; this
            // guard simply hardens the loop against future mapper changes.
            if (($register instanceof Register) === false) {
                continue;
            }

            foreach (($register->getSchemas() ?? []) as $schemaId) {
                try {
                    $schema = $this->schemaMapper->find((int) $schemaId);
                } catch (Exception $error) {
                    $this->logger->warning(
                        sprintf('[ArchivalRetentionTask] Schema #%s not loadable: %s', (string) $schemaId, $error->getMessage()),
                        ['app' => 'openregister']
                    );
                    continue;
                }

                $annotation = $this->extractArchivalAnnotation(schema: $schema);
                if ($annotation === null) {
                    continue;
                }

                $this->sweepSchema(
                    register: $register,
                    schema: $schema,
                    annotation: $annotation,
                    now: $now
                );
            }//end foreach
        }//end foreach

    }//end run()

    /**
     * Return the `x-openregister-archival` annotation when present, or null.
     *
     * @param Schema $schema Schema to inspect.
     *
     * @return array<string, mixed>|null Annotation block or null when absent.
     */
    private function extractArchivalAnnotation(Schema $schema): ?array
    {
        $configuration = ($schema->getConfiguration() ?? []);
        if (is_array($configuration) === false) {
            return null;
        }

        $annotation = ($configuration['x-openregister-archival'] ?? null);
        if (is_array($annotation) === false) {
            return null;
        }

        return $annotation;

    }//end extractArchivalAnnotation()

    /**
     * Sweep a single `(register, schema)` pair.
     *
     * Pulls every row from the magic table, evaluates the retention rules
     * against each, and deletes rows past their expiry. Logs a per-schema
     * summary `{schemaSlug, scanned, expired, deleted}`.
     *
     * @param Register             $register   Register the schema belongs to.
     * @param Schema               $schema     Schema being swept.
     * @param array<string, mixed> $annotation `x-openregister-archival` annotation.
     * @param DateTimeInterface    $now        Sweep wall-clock anchor.
     *
     * @return void
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     * @SuppressWarnings(PHPMD.NPathComplexity)
     * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
     */
    private function sweepSchema(
        Register $register,
        Schema $schema,
        array $annotation,
        DateTimeInterface $now
    ): void {
        $schemaSlug = $schema->getSlug() ?? (string) $schema->getId();

        // Resolve table name; skip if the magic table has not been
        // materialised yet (e.g. brand-new schema with no rows).
        try {
            $tableExists = $this->magicMapper->tableExistsForRegisterSchema(
                register: $register,
                schema: $schema
            );
        } catch (Exception $error) {
            $this->logger->warning(
                sprintf(
                    '[ArchivalRetentionTask] Cannot check magic-table existence for schema "%s": %s',
                    $schemaSlug,
                    $error->getMessage()
                ),
                ['app' => 'openregister']
            );
            return;
        }

        if ($tableExists === false) {
            return;
        }

        $tableName = MagicMapper::TABLE_PREFIX.((string) $register->getId()).'_'.((string) $schema->getId());

        $scanned = 0;
        $expired = 0;
        $deleted = 0;

        try {
            // Native SELECT — using the QueryBuilder so the table name goes
            // through the platform-aware quoter. We only need the uuid +
            // _created + the row's hydrated field columns; pulling `*` keeps
            // the implementation table-shape-agnostic.
            $qb = $this->db->getQueryBuilder();
            $qb->select('*')->from($tableName);

            $cursor = $qb->executeQuery();
            while (($row = $cursor->fetch()) !== false) {
                $scanned++;

                $createdRaw = ($row['_created'] ?? null);
                if ($createdRaw === null) {
                    // Without a created timestamp we cannot decide expiry.
                    continue;
                }

                try {
                    $createdAt = new DateTimeImmutable((string) $createdRaw);
                } catch (Exception $error) {
                    $this->logger->warning(
                        sprintf(
                            '[ArchivalRetentionTask] Row with unparseable _created skipped on "%s": %s',
                            $schemaSlug,
                            $error->getMessage()
                        ),
                        ['app' => 'openregister']
                    );
                    continue;
                }

                try {
                    $result = $this->retentionEvaluator->evaluate(
                        annotation: $annotation,
                        row: $this->stripMetadataColumns(row: $row),
                        createdAt: $createdAt
                    );
                } catch (Exception $error) {
                    $this->logger->warning(
                        sprintf(
                            '[ArchivalRetentionTask] Skipping row on "%s": %s',
                            $schemaSlug,
                            $error->getMessage()
                        ),
                        ['app' => 'openregister']
                    );
                    continue;
                }

                $expiresAt = new DateTimeImmutable($result['expiresAt']);
                if ($expiresAt >= $now) {
                    continue;
                }

                $expired++;

                $uuid = (string) ($row['_uuid'] ?? '');
                if ($uuid === '') {
                    continue;
                }

                try {
                    // Re-anchor ObjectService at the (register, schema) we
                    // are sweeping so the delete pipeline sees the right
                    // context. setSchema / setRegister are idempotent.
                    $this->objectService->setRegister(register: $register);
                    $this->objectService->setSchema(schema: $schema);

                    $deleteOk = $this->objectService->deleteObject(
                        uuid: $uuid,
                        _rbac: false,
                        _multitenancy: false,
                        _retentionSweep: true
                    );

                    if ($deleteOk === true) {
                        $deleted++;
                    }
                } catch (Exception $error) {
                    $this->logger->warning(
                        sprintf(
                            '[ArchivalRetentionTask] Delete failed on schema "%s" uuid "%s": %s',
                            $schemaSlug,
                            $uuid,
                            $error->getMessage()
                        ),
                        ['app' => 'openregister']
                    );
                }//end try
            }//end while

            $cursor->closeCursor();
        } catch (Exception $error) {
            $this->logger->error(
                sprintf(
                    '[ArchivalRetentionTask] Sweep failed on schema "%s": %s',
                    $schemaSlug,
                    $error->getMessage()
                ),
                ['app' => 'openregister', 'exception' => $error]
            );
        }//end try

        $this->logger->info(
            sprintf(
                '[ArchivalRetentionTask] schema="%s" scanned=%d expired=%d deleted=%d',
                $schemaSlug,
                $scanned,
                $expired,
                $deleted
            ),
            [
                'app'        => 'openregister',
                'schemaSlug' => $schemaSlug,
                'scanned'    => $scanned,
                'expired'    => $expired,
                'deleted'    => $deleted,
            ]
        );

    }//end sweepSchema()

    /**
     * Drop the magic-table metadata columns (`_uuid`, `_created`, ...) from a
     * raw row so the row passed to the condition evaluator carries only the
     * schema-declared fields.
     *
     * @param array<string, mixed> $row Raw row as returned by the QueryBuilder cursor.
     *
     * @return array<string, mixed> Filtered row.
     */
    private function stripMetadataColumns(array $row): array
    {
        $filtered = [];
        foreach ($row as $key => $value) {
            if (is_string($key) === true && str_starts_with($key, '_') === true) {
                continue;
            }

            $filtered[$key] = $value;
        }

        return $filtered;

    }//end stripMetadataColumns()
}//end class
