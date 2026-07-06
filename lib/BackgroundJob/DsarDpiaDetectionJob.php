<?php

/**
 * OpenRegister DSAR DPIA Detection Job
 *
 * Daily TimedJob driving GDPR art-35 DPIA pattern detection over DSAR cases
 * (dsar-escalation-and-dpia): partitions the register's cases by
 * jurisdiction, resolves each partition's active `dsarPolicyPack`, feeds the
 * pack's `dpiaDetection` block (threshold, windowDays, groupBy) into the pure
 * {@see \OCA\OpenRegister\Service\Gdpr\DpiaPatternDetectionService}, and sets
 * `dpiaRequired = true` on every unflagged case of a triggering group through
 * the normal `ObjectService` write path — with an explicit `dpia.detected`
 * audit row recording rule, group key, window, and count.
 *
 * Idempotent: already-flagged cases count toward their group but are never
 * re-written or re-audited, and a manual flag is never cleared (one-way
 * ratchet). Officer notification is NOT dispatched here — the register's
 * declared `dpiaFlagged` rule fires on the `dpiaRequired` false→true write,
 * so detection and manual flagging share one declared path (gate-18 posture).
 *
 * Fail-safe: no resolvable pack, or a pack without a `dpiaDetection` block,
 * makes the partition a no-op — never a false DPIA flag.
 *
 * Operator overrides (IAppConfig, `DsarRetentionSweepJob` convention):
 *   - `dsar_dpia_detection_enabled` (bool, default: true)
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category BackgroundJob
 * @package  OCA\OpenRegister\BackgroundJob
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/dsar-escalation-and-dpia/specs/dsar-dpia-detection/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\BackgroundJob;

use DateTimeImmutable;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Service\Gdpr\Case\CaseObjectAccessor;
use OCA\OpenRegister\Service\Gdpr\DpiaPatternDetectionService;
use OCA\OpenRegister\Service\Gdpr\Policy\DsarPolicyPackResolver;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;

/**
 * Daily DPIA pattern-detection sweep over DSAR cases.
 *
 * @psalm-suppress UnusedClass Instantiated by the NC background-job framework (appinfo/info.xml).
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) The job wires the case
 *   store, the pack resolver, the pure detection service, the audit mapper,
 *   and the object write path — each a distinct shipped collaborator.
 *
 * @spec openspec/changes/dsar-escalation-and-dpia/specs/dsar-dpia-detection/spec.md
 *   (Requirement: DPIA pattern detection over DSAR cases)
 */
class DsarDpiaDetectionJob extends TimedJob
{

    /**
     * Default interval — once per 24 hours (pipelinq's detection cadence).
     *
     * @var int
     */
    private const RUN_INTERVAL_SECONDS = 86400;

    /**
     * App-config key for the enable/disable toggle.
     *
     * @var string
     */
    private const CONFIG_KEY_ENABLED = 'dsar_dpia_detection_enabled';

    /**
     * App identifier for app-config lookups.
     *
     * @var string
     */
    private const APP_ID = 'openregister';

    /**
     * Audit action recorded on every detection flag.
     *
     * @var string
     */
    public const AUDIT_ACTION = 'dpia.detected';

    /**
     * Constructor.
     *
     * @param ITimeFactory                $time             Time factory required by parent.
     * @param IAppConfig                  $appConfig        App-config reader.
     * @param ObjectService               $objectService    Object read/write path.
     * @param DsarPolicyPackResolver      $packResolver     Active-pack resolution per jurisdiction.
     * @param DpiaPatternDetectionService $detectionService Pure grouping/threshold engine.
     * @param AuditTrailMapper            $auditTrailMapper Immutable detection audit rows.
     * @param LoggerInterface             $logger           Logger.
     */
    public function __construct(
        ITimeFactory $time,
        private readonly IAppConfig $appConfig,
        private readonly ObjectService $objectService,
        private readonly DsarPolicyPackResolver $packResolver,
        private readonly DpiaPatternDetectionService $detectionService,
        private readonly AuditTrailMapper $auditTrailMapper,
        private readonly LoggerInterface $logger
    ) {
        parent::__construct(time: $time);
        $this->setInterval(seconds: self::RUN_INTERVAL_SECONDS);

    }//end __construct()

    /**
     * Drive the DPIA detection sweep.
     *
     * @param mixed $argument Job arguments (unused for recurring jobs).
     *
     * @return void
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     *
     * @spec openspec/changes/dsar-escalation-and-dpia/specs/dsar-dpia-detection/spec.md
     *   (Scenario: Threshold crossing flags the group)
     */
    protected function run($argument): void
    {
        $enabled = filter_var(
            $this->appConfig->getValueString(
                app: self::APP_ID,
                key: self::CONFIG_KEY_ENABLED,
                default: 'true'
            ),
            FILTER_VALIDATE_BOOLEAN
        );

        if ($enabled === false) {
            $this->logger->info(
                message: '[DsarDpiaDetectionJob] detection disabled (dsar_dpia_detection_enabled=false), skipping',
                context: ['file' => __FILE__, 'line' => __LINE__]
            );
            return;
        }

        try {
            $summary = $this->detect();
            if ($summary['flagged'] > 0 || $summary['errors'] > 0) {
                $this->logger->info(
                    message: '[DsarDpiaDetectionJob] detection complete',
                    context: array_merge(['file' => __FILE__, 'line' => __LINE__], $summary)
                );
            }
        } catch (\Throwable $e) {
            $this->logger->error(
                message: '[DsarDpiaDetectionJob] detection failed: '.$e->getMessage(),
                context: ['file' => __FILE__, 'line' => __LINE__]
            );
        }

    }//end run()

    /**
     * Run one detection pass: partition cases by jurisdiction, detect per
     * active pack, flag unflagged members of triggering groups.
     *
     * @return array{cases: int, partitions: int, groupsTriggered: int, flagged: int, errors: int} Detection summary.
     *
     * @spec openspec/changes/dsar-escalation-and-dpia/specs/dsar-dpia-detection/spec.md
     *   (Scenario: Re-runs are idempotent)
     */
    private function detect(): array
    {
        $summary = [
            'cases'           => 0,
            'partitions'      => 0,
            'groupsTriggered' => 0,
            'flagged'         => 0,
            'errors'          => 0,
        ];

        $now   = new DateTimeImmutable();
        $cases = $this->loadCases();

        $summary['cases'] = count($cases);

        // Partition by jurisdiction: each partition resolves its own active
        // pack (jurisdiction is data — grouping across packs with different
        // thresholds would be ambiguous).
        $partitions = [];
        foreach ($cases as $case) {
            $jurisdiction = (string) ($case['jurisdiction'] ?? '');
            $partitions[$jurisdiction][] = $case;
        }

        foreach ($partitions as $partitionCases) {
            $summary['partitions']++;

            $pack   = $this->packResolver->activePackForCase(case: $partitionCases[0], systemContext: true);
            $config = null;
            if ($pack !== null) {
                $config = ($pack['dpiaDetection'] ?? null);
            }

            if (is_array($config) === false || $config === []) {
                // Fail-safe: no pack / no dpiaDetection block ⇒ no flagging.
                continue;
            }

            $groups = $this->detectionService->detect(cases: $partitionCases, config: $config, now: $now);
            foreach ($groups as $group) {
                $summary['groupsTriggered']++;
                $this->flagGroup(group: $group, config: $config, summary: $summary);
            }
        }//end foreach

        return $summary;

    }//end detect()

    /**
     * Flag every unflagged case of a triggering group (audited write).
     *
     * @param array<string, mixed> $group   The triggering group (key, count, caseUuids, unflaggedUuids).
     * @param array<string, mixed> $config  The pack's `dpiaDetection` block.
     * @param array<string, int>   $summary Running summary (by reference).
     *
     * @return void
     *
     * @spec openspec/changes/dsar-escalation-and-dpia/specs/dsar-dpia-detection/spec.md
     *   (Scenario: Threshold crossing flags the group)
     */
    private function flagGroup(array $group, array $config, array &$summary): void
    {
        foreach ($group['unflaggedUuids'] as $uuid) {
            try {
                $entity = $this->objectService->find(
                    id: $uuid,
                    register: CaseObjectAccessor::REGISTER_SLUG,
                    schema: CaseObjectAccessor::SCHEMA_SLUG,
                    _rbac: false,
                    _multitenancy: false
                );
                if ($entity === null) {
                    continue;
                }

                $data = ($entity->getObject() ?? []);
                if (($data['dpiaRequired'] ?? false) === true) {
                    // Raced with a manual flag — idempotency: never re-write.
                    continue;
                }

                $data['dpiaRequired'] = true;

                $saved = $this->objectService->saveObject(
                    object: $data,
                    register: CaseObjectAccessor::REGISTER_SLUG,
                    schema: CaseObjectAccessor::SCHEMA_SLUG,
                    uuid: $uuid,
                    _rbac: false,
                    _multitenancy: false
                );

                $this->auditTrailMapper->createAuditTrailEntry(
                    object: $saved,
                    action: self::AUDIT_ACTION,
                    context: [
                        'rule'       => 'dpia-pattern-detection',
                        'groupKey'   => $group['key'],
                        'windowDays' => (int) ($config['windowDays'] ?? 0),
                        'threshold'  => (int) ($config['threshold'] ?? 0),
                        'count'      => $group['count'],
                    ]
                );

                $summary['flagged']++;
            } catch (\Throwable $e) {
                $summary['errors']++;
                $this->logger->warning(
                    message: '[DsarDpiaDetectionJob] flagging failed for case '.$uuid.': '.$e->getMessage(),
                    context: ['file' => __FILE__, 'line' => __LINE__]
                );
            }//end try
        }//end foreach

    }//end flagGroup()

    /**
     * Load every DSAR case payload (system scope), with the object uuid
     * injected as `@uuid` for the pure detection service.
     *
     * @return array<int, array<string, mixed>> The case payloads.
     */
    private function loadCases(): array
    {
        try {
            $rows = $this->objectService->findAll(
                config: [
                    'filters' => [
                        'register' => CaseObjectAccessor::REGISTER_SLUG,
                        'schema'   => CaseObjectAccessor::SCHEMA_SLUG,
                    ],
                ],
                _rbac: false,
                _multitenancy: false
            );
        } catch (\Throwable $e) {
            $this->logger->warning(
                message: '[DsarDpiaDetectionJob] case enumeration failed: '.$e->getMessage(),
                context: ['file' => __FILE__, 'line' => __LINE__]
            );
            return [];
        }

        $cases = [];
        foreach ($rows as $row) {
            if (is_array($row) === false) {
                continue;
            }

            $uuid = (string) ($row['@self']['uuid'] ?? ($row['@self']['id'] ?? ($row['id'] ?? '')));
            if ($uuid === '') {
                continue;
            }

            $row['@uuid'] = $uuid;
            $cases[]      = $row;
        }

        return $cases;

    }//end loadCases()
}//end class
