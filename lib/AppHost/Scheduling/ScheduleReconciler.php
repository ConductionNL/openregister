<?php

/**
 * OpenRegister AppHost — Schedule Reconciler
 *
 * The generic OpenRegister AppHost scheduling engine. Each tick it enumerates
 * the `schedules[]` of every published manifest — on-disk AppHost apps AND
 * virtual apps (OR `application` objects in the `openbuild` register) — and
 * idempotently UPSERTs one OpenConnector `job` OR object per
 * `applicationId + scheduleId`, then garbage-collects jobs whose schedule was
 * disabled or removed. Execution of the resulting jobs is reused wholesale from
 * OpenConnector's `JobTask`/`JobService`; this engine writes NO execution or
 * logging code (the only `nextRun` it writes is the cron→next-fire computation).
 *
 * Safety by construction (ADR-005):
 *   - `jobClass` is ALWAYS a server-vetted value from {@see ScheduleActionAllowList};
 *     a manifest-supplied FQCN is never used.
 *   - `userId` is resolved from the owning application's owner; any author-supplied
 *     `runAs`/`owner` is ignored, and an unresolved owner fails closed (no job).
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\AppHost\Scheduling
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */

declare(strict_types=1);

namespace OCA\OpenRegister\AppHost\Scheduling;

use DateTime;
use DateTimeInterface;
use OCA\OpenRegister\Service\ObjectService;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Enumerates manifest schedules and reconciles them into OpenConnector jobs.
 *
 * @spec openspec/changes/apphost-manifest-schedules/specs/apphost-scheduling/spec.md
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity)
 */
class ScheduleReconciler
{
    /**
     * OpenConnector register slug that owns the `job` schema.
     */
    private const OC_REGISTER_SLUG = 'openconnector';

    /**
     * OpenConnector `job` schema slug.
     */
    private const OC_JOB_SCHEMA_SLUG = 'job';

    /**
     * OpenBuild register slug that owns virtual-app `application` objects.
     */
    private const OB_REGISTER_SLUG = 'openbuild';

    /**
     * OpenBuild `application` schema slug.
     */
    private const OB_APPLICATION_SCHEMA_SLUG = 'application';

    /**
     * Reference prefix marking a `job` object as AppHost-schedule-managed.
     */
    public const REFERENCE_PREFIX = 'apphost-schedule';

    /**
     * Constructor.
     *
     * @param ObjectService           $objectService  OR CRUD facade for reading applications and upserting jobs.
     * @param ScheduleManifestLoader  $manifestLoader On-disk manifest enumeration.
     * @param CronScheduleEvaluator   $cron           Cron → next-fire
     *                                                computation.
     * @param ScheduleActionAllowList $allowList      Closed action → vetted jobClass
     *                                                map.
     * @param IUserManager            $userManager    Resolves an owner UID to a live NC user.
     * @param LoggerInterface         $logger         Secret-free diagnostics.
     */
    public function __construct(
        private readonly ObjectService $objectService,
        private readonly ScheduleManifestLoader $manifestLoader,
        private readonly CronScheduleEvaluator $cron,
        private readonly ScheduleActionAllowList $allowList,
        private readonly IUserManager $userManager,
        private readonly LoggerInterface $logger
    ) {
    }//end __construct()

    /**
     * Reconcile every declared schedule into an OpenConnector job (never throws).
     *
     * @return void
     *
     * @spec openspec/changes/apphost-manifest-schedules/specs/apphost-scheduling/spec.md
     */
    public function reconcile(): void
    {
        try {
            $existing = $this->loadManagedJobs();
            if ($existing === null) {
                // OpenConnector register/schema absent — degrade quietly.
                return;
            }

            $now         = new DateTime();
            $desiredKeys = [];

            foreach ($this->collectSources() as $source) {
                $applicationId = $source['applicationId'];
                $ownerUid      = $source['ownerUid'];

                foreach ($source['schedules'] as $descriptor) {
                    $reference = $this->computeReference(applicationId: $applicationId, scheduleId: $descriptor->id);

                    $jobClass = $this->allowList->resolve($descriptor->action);
                    if ($jobClass === null) {
                        $this->logger->warning(
                            message: '[AppHost\\Scheduling] Rejected non-allow-listed action',
                            context: [
                                'applicationId' => $applicationId,
                                'scheduleId'    => $descriptor->id,
                                'action'        => $descriptor->action,
                            ]
                        );
                        continue;
                    }

                    if ($ownerUid === null) {
                        $this->logger->warning(
                            message: '[AppHost\\Scheduling] Skipping schedule: no resolvable owner (fail-closed)',
                            context: ['applicationId' => $applicationId, 'scheduleId' => $descriptor->id]
                        );
                        continue;
                    }

                    $desiredKeys[$reference] = true;
                    $this->upsert(
                        descriptor: $descriptor,
                        reference: $reference,
                        jobClass: $jobClass,
                        ownerUid: $ownerUid,
                        existing: ($existing[$reference] ?? null),
                        now: $now
                    );
                }//end foreach
            }//end foreach

            $this->garbageCollect(existing: $existing, desiredKeys: $desiredKeys);
        } catch (Throwable $e) {
            // Top-level guard: a scheduling hiccup must never break cron.php.
            $this->logger->error(
                message: '[AppHost\\Scheduling] Reconcile aborted: '.$e->getMessage(),
                context: ['file' => __FILE__, 'line' => __LINE__]
            );
        }//end try
    }//end reconcile()

    /**
     * Collect all schedule sources (on-disk + virtual) with their resolved owners.
     *
     * @return array<int, array{applicationId: string, ownerUid: string|null, schedules: ScheduleDescriptor[]}>
     *
     * @SuppressWarnings(PHPMD.StaticAccess)
     *
     * @spec openspec/changes/apphost-manifest-schedules/specs/apphost-scheduling/spec.md
     */
    protected function collectSources(): array
    {
        $sources = [];

        // Virtual apps: openbuild `application` objects carry both the manifest
        // and the owner directly on the OR object.
        foreach ($this->loadVirtualApplications() as $application) {
            $applicationId = $this->extractApplicationId(application: $application);
            if ($applicationId === null) {
                continue;
            }

            $manifest = $this->extractManifest(application: $application);
            $parsed   = ScheduleManifest::fromManifest(
                applicationId: $applicationId,
                manifest: $manifest,
                cron: $this->cron
            );
            $this->logDiagnostics(applicationId: $applicationId, diagnostics: $parsed->diagnostics);
            if ($parsed->schedules === []) {
                continue;
            }

            $sources[] = [
                'applicationId' => $applicationId,
                'ownerUid'      => $this->resolveOwner(ownerUid: ($application['@self']['owner'] ?? null)),
                'schedules'     => $parsed->schedules,
            ];
        }//end foreach

        // On-disk apps: the owner is resolved from a matching openbuild
        // `application` object (slug == appId); absent that, no owner (skip).
        foreach ($this->manifestLoader->loadAllOnDisk() as $parsed) {
            $this->logDiagnostics(applicationId: $parsed->applicationId, diagnostics: $parsed->diagnostics);
            $sources[] = [
                'applicationId' => $parsed->applicationId,
                'ownerUid'      => $this->resolveOnDiskOwner(appId: $parsed->applicationId),
                'schedules'     => $parsed->schedules,
            ];
        }

        return $sources;
    }//end collectSources()

    /**
     * Build the deterministic matching reference for a schedule.
     *
     * @param string $applicationId The owning application identifier.
     * @param string $scheduleId    The schedule id.
     *
     * @return string The `apphost-schedule:{applicationId}:{scheduleId}` key.
     *
     * @spec openspec/changes/apphost-manifest-schedules/specs/apphost-scheduling/spec.md
     */
    public function computeReference(string $applicationId, string $scheduleId): string
    {
        return sprintf('%s:%s:%s', self::REFERENCE_PREFIX, $applicationId, $scheduleId);
    }//end computeReference()

    /**
     * Build the desired `job` data for a schedule, merged over any existing job.
     *
     * Preserves fields owned by `JobService` (`lastRun`, and — for interval
     * schedules — `nextRun`). For a cron schedule, sets `nextRun` to the next
     * fire time, but only rolls it FORWARD: an existing future `nextRun` is left
     * untouched so a job is never rewound mid-flight (design D-2/D-5).
     *
     * @param ScheduleDescriptor        $descriptor The schedule declaration.
     * @param string                    $reference  The matching reference key.
     * @param string                    $jobClass   The server-vetted job class.
     * @param string                    $ownerUid   The resolved application owner UID.
     * @param array<string, mixed>|null $existing   Existing job data props (null on create).
     * @param DateTimeInterface         $now        Reference "now".
     *
     * @return array<string, mixed> The desired job data properties.
     *
     * @spec openspec/changes/apphost-manifest-schedules/specs/apphost-scheduling/spec.md
     */
    public function buildJobData(
        ScheduleDescriptor $descriptor,
        string $reference,
        string $jobClass,
        string $ownerUid,
        ?array $existing,
        DateTimeInterface $now
    ): array {
        // Start from existing data props (minus metadata) so JobService-owned
        // bookkeeping is preserved on update; empty on create.
        $data = [];
        if ($existing !== null) {
            $data = $existing;
            unset($data['@self']);
        }

        $data['reference']   = $reference;
        $data['jobClass']    = $jobClass;
        $data['arguments']   = $descriptor->arguments;
        $data['isEnabled']   = $descriptor->enabled;
        $data['userId']      = $ownerUid;
        $data['name']        = sprintf('AppHost schedule %s', $descriptor->id);
        $data['description'] = sprintf('Reconciled from manifest schedule "%s".', $descriptor->id);

        if ($descriptor->isInterval() === true) {
            $data['interval'] = $descriptor->intervalSeconds;
            // Interval jobs: never touch lastRun/nextRun (owned by JobService).
            return $data;
        }

        // Cron schedule: drive execution via nextRun, not interval.
        $data['interval'] = null;

        $existingNextRun = $this->parseNextRun(value: ($existing['nextRun'] ?? null));
        if ($existingNextRun !== null && $existingNextRun > $now) {
            // A future nextRun is left as-is (never rewind mid-flight).
            return $data;
        }

        $next = $this->cron->nextRun((string) $descriptor->cron, $now);
        if ($next !== null) {
            $data['nextRun'] = $next->format(DateTimeInterface::ATOM);
        }

        return $data;
    }//end buildJobData()

    /**
     * Whether the owned fields of the desired job differ from the existing job.
     *
     * Compares only reconciler-owned fields; JobService-owned bookkeeping
     * (`lastRun`, interval jobs' `nextRun`) is ignored so an unchanged schedule
     * is a genuine no-op.
     *
     * @param array<string, mixed>|null $existing Existing job data props (null on create → always differs).
     * @param array<string, mixed>      $desired  Desired job data props.
     * @param bool                      $isCron   Whether this is a cron schedule (compare nextRun too).
     *
     * @return bool True when a write is required.
     *
     * @spec openspec/changes/apphost-manifest-schedules/specs/apphost-scheduling/spec.md
     */
    public function jobDiffers(?array $existing, array $desired, bool $isCron): bool
    {
        if ($existing === null) {
            return true;
        }

        $keys = ['reference', 'jobClass', 'isEnabled', 'userId', 'name', 'description'];
        foreach ($keys as $key) {
            if (($existing[$key] ?? null) !== ($desired[$key] ?? null)) {
                return true;
            }
        }

        if (($existing['arguments'] ?? []) !== ($desired['arguments'] ?? [])) {
            return true;
        }

        if ($isCron === true) {
            if (($existing['nextRun'] ?? null) !== ($desired['nextRun'] ?? null)) {
                return true;
            }

            return (($existing['interval'] ?? null) !== ($desired['interval'] ?? null));
        }

        return ((int) ($existing['interval'] ?? 0) !== (int) ($desired['interval'] ?? 0));
    }//end jobDiffers()

    /**
     * Resolve an owner UID to a live NC user UID, or null when unresolvable.
     *
     * @param mixed $ownerUid The candidate owner UID from the application object.
     *
     * @return string|null The verified owner UID, or null (fail-closed).
     *
     * @spec openspec/changes/apphost-manifest-schedules/specs/apphost-scheduling/spec.md
     */
    protected function resolveOwner(mixed $ownerUid): ?string
    {
        if (is_string($ownerUid) === false || trim($ownerUid) === '') {
            return null;
        }

        if ($this->userManager->get($ownerUid) === null) {
            return null;
        }

        return $ownerUid;
    }//end resolveOwner()

    /**
     * Upsert a single schedule into its OpenConnector job.
     *
     * @param ScheduleDescriptor        $descriptor The schedule declaration.
     * @param string                    $reference  The matching reference key.
     * @param string                    $jobClass   The server-vetted job class.
     * @param string                    $ownerUid   The resolved application owner UID.
     * @param array<string, mixed>|null $existing   Existing job data props (null on create).
     * @param DateTimeInterface         $now        Reference "now".
     *
     * @return void
     *
     * @spec openspec/changes/apphost-manifest-schedules/specs/apphost-scheduling/spec.md
     */
    protected function upsert(
        ScheduleDescriptor $descriptor,
        string $reference,
        string $jobClass,
        string $ownerUid,
        ?array $existing,
        DateTimeInterface $now
    ): void {
        $desired = $this->buildJobData(
            descriptor: $descriptor,
            reference: $reference,
            jobClass: $jobClass,
            ownerUid: $ownerUid,
            existing: $existing,
            now: $now
        );

        if ($this->jobDiffers(existing: $existing, desired: $desired, isCron: $descriptor->isCron()) === false) {
            // No-op: unchanged schedule, no write (idempotent).
            return;
        }

        $uuid = null;
        if ($existing !== null) {
            $uuid = ($existing['@self']['uuid'] ?? ($existing['@self']['id'] ?? null));
            if ($uuid !== null) {
                $uuid = (string) $uuid;
            }
        }

        $this->saveJob(data: $desired, uuid: $uuid);
    }//end upsert()

    /**
     * Garbage-collect managed jobs whose schedule is gone from every manifest.
     *
     * @param array<string, array<string, mixed>> $existing    Managed jobs keyed by reference.
     * @param array<string, bool>                 $desiredKeys References that are still declared.
     *
     * @return void
     *
     * @spec openspec/changes/apphost-manifest-schedules/specs/apphost-scheduling/spec.md
     */
    protected function garbageCollect(array $existing, array $desiredKeys): void
    {
        foreach ($existing as $reference => $job) {
            if (isset($desiredKeys[$reference]) === true) {
                continue;
            }

            // Orphaned: disable (preserve run history) rather than delete.
            if (($job['isEnabled'] ?? false) === false) {
                continue;
            }

            $data = $job;
            unset($data['@self']);
            $data['isEnabled'] = false;

            $uuid = ($job['@self']['uuid'] ?? ($job['@self']['id'] ?? null));
            if ($uuid !== null) {
                $uuid = (string) $uuid;
            }

            $this->saveJob(data: $data, uuid: $uuid);
        }//end foreach
    }//end garbageCollect()

    /**
     * Load all AppHost-managed jobs, keyed by their reference.
     *
     * @return array<string, array<string, mixed>>|null Keyed jobs, or null when the OC register/schema is absent.
     *
     * @spec openspec/changes/apphost-manifest-schedules/specs/apphost-scheduling/spec.md
     */
    protected function loadManagedJobs(): ?array
    {
        try {
            $rows = $this->objectService->findAll(
                config: [
                    'filters' => [
                        'register' => self::OC_REGISTER_SLUG,
                        'schema'   => self::OC_JOB_SCHEMA_SLUG,
                    ],
                ],
                _rbac: false,
                _multitenancy: false
            );
        } catch (Throwable $e) {
            $this->logger->info(
                message: '[AppHost\\Scheduling] OpenConnector job register/schema unavailable; scheduling inert',
                context: ['reason' => $e->getMessage()]
            );
            return null;
        }

        $managed = [];
        foreach ($rows as $row) {
            if (is_array($row) === false) {
                continue;
            }

            $reference = ($row['reference'] ?? null);
            if (is_string($reference) === false || str_starts_with($reference, self::REFERENCE_PREFIX.':') === false) {
                continue;
            }

            $managed[$reference] = $row;
        }

        return $managed;
    }//end loadManagedJobs()

    /**
     * Load the openbuild `application` objects (virtual apps).
     *
     * @return array<int, array<string, mixed>> Rendered application objects (empty on any failure).
     *
     * @spec openspec/changes/apphost-manifest-schedules/specs/apphost-scheduling/spec.md
     */
    protected function loadVirtualApplications(): array
    {
        try {
            $rows = $this->objectService->findAll(
                config: [
                    'filters' => [
                        'register' => self::OB_REGISTER_SLUG,
                        'schema'   => self::OB_APPLICATION_SCHEMA_SLUG,
                    ],
                ],
                _rbac: false,
                _multitenancy: false
            );
        } catch (Throwable $e) {
            $this->logger->info(
                message: '[AppHost\\Scheduling] openbuild application register/schema unavailable; virtual apps skipped',
                context: ['reason' => $e->getMessage()]
            );
            return [];
        }

        return array_values(array_filter($rows, 'is_array'));
    }//end loadVirtualApplications()

    /**
     * Resolve an on-disk app's owner from a matching openbuild `application` object.
     *
     * @param string $appId The Nextcloud app id (matched against the application slug).
     *
     * @return string|null The resolved owner UID, or null when no matching application/owner exists.
     *
     * @spec openspec/changes/apphost-manifest-schedules/specs/apphost-scheduling/spec.md
     */
    protected function resolveOnDiskOwner(string $appId): ?string
    {
        foreach ($this->loadVirtualApplications() as $application) {
            $slug = ($application['slug'] ?? ($application['@self']['slug'] ?? null));
            if (is_string($slug) === true && $slug === $appId) {
                return $this->resolveOwner(ownerUid: ($application['@self']['owner'] ?? null));
            }
        }

        return null;
    }//end resolveOnDiskOwner()

    /**
     * Persist a `job` object into the OpenConnector register (create or update).
     *
     * @param array<string, mixed> $data The job data props.
     * @param string|null          $uuid The existing job uuid (null → create).
     *
     * @return void
     *
     * @spec openspec/changes/apphost-manifest-schedules/specs/apphost-scheduling/spec.md
     */
    protected function saveJob(array $data, ?string $uuid): void
    {
        try {
            $this->objectService->saveObject(
                object: $data,
                register: self::OC_REGISTER_SLUG,
                schema: self::OC_JOB_SCHEMA_SLUG,
                uuid: $uuid,
                _rbac: false,
                _multitenancy: false
            );
        } catch (Throwable $e) {
            $this->logger->error(
                message: '[AppHost\\Scheduling] Failed to persist reconciled job',
                context: ['reference' => ($data['reference'] ?? ''), 'reason' => $e->getMessage()]
            );
        }
    }//end saveJob()

    /**
     * Extract the stable application identifier from an application object.
     *
     * @param array<string, mixed> $application The rendered application object.
     *
     * @return string|null The uuid (preferred) or id, or null when absent.
     */
    private function extractApplicationId(array $application): ?string
    {
        $id = ($application['@self']['uuid'] ?? ($application['@self']['id'] ?? null));
        if ($id === null || $id === '') {
            return null;
        }

        return (string) $id;
    }//end extractApplicationId()

    /**
     * Extract the manifest payload carried on an application object.
     *
     * @param array<string, mixed> $application The rendered application object.
     *
     * @return array<string, mixed> The decoded manifest (empty when absent/invalid).
     */
    private function extractManifest(array $application): array
    {
        $manifest = ($application['manifest'] ?? null);
        if (is_string($manifest) === true) {
            $decoded  = json_decode($manifest, associative: true);
            $manifest = null;
            if (is_array($decoded) === true) {
                $manifest = $decoded;
            }
        }

        if (is_array($manifest) === true) {
            return $manifest;
        }

        return [];
    }//end extractManifest()

    /**
     * Parse a stored `nextRun` value into a comparable DateTime.
     *
     * @param mixed $value The stored nextRun (ISO-8601 string) or null.
     *
     * @return DateTime|null The parsed DateTime, or null when unparseable/absent.
     */
    private function parseNextRun(mixed $value): ?DateTime
    {
        if (is_string($value) === false || trim($value) === '') {
            return null;
        }

        try {
            return new DateTime($value);
        } catch (Throwable $e) {
            return null;
        }
    }//end parseNextRun()

    /**
     * Log a manifest's rejected-schedule diagnostics (secret-free).
     *
     * @param string   $applicationId The owning application identifier.
     * @param string[] $diagnostics   The diagnostics to log.
     *
     * @return void
     */
    private function logDiagnostics(string $applicationId, array $diagnostics): void
    {
        foreach ($diagnostics as $diagnostic) {
            $this->logger->warning(
                message: '[AppHost\\Scheduling] Rejected schedule entry: '.$diagnostic,
                context: ['applicationId' => $applicationId]
            );
        }
    }//end logDiagnostics()
}//end class
