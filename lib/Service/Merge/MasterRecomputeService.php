<?php

/**
 * OpenRegister MasterRecomputeService
 *
 * Re-persists a reverse-FK master so its golden record is rematerialised by
 * the survivorship recompute listener. Extracted from
 * `SourceRecordChangeListener::recomputeMaster()` so the same body serves two
 * callers with different timing:
 *
 *   - `SourceRecordRecomputeJob` — the normal path, after the request.
 *   - `SourceRecordChangeListener` — the inline fallback, used only when
 *     listener deferral is switched off (`openregister.listener_deferral`
 *     = `inline`).
 *
 * WHY IT WAS EXTRACTED (openregister#2420). The recompute is a full
 * `ObjectService::saveObject()`, and it used to run SYNCHRONOUSLY inside the
 * handling of every `ObjectCreated`/`Updated`/`Deleted` event for a source
 * object — a write inside a write, with no deferral and no coalescing. An
 * update reassigning a source to another master ran it TWICE. That is the
 * shape that serialised every object write on a live instance on 2026-08-11,
 * and ADR-078 requires such work to be deferred.
 *
 * Idempotent by construction: it reads the master fresh and re-persists it, so
 * running it twice produces the same golden record as running it once. That is
 * what makes at-least-once background delivery safe here.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Merge
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/mdm-reverse-fk-source-resolution/tasks.md#4.1
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Merge;

use OCA\OpenRegister\Service\ObjectService;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Recompute a reverse-FK master's golden record by re-persisting it.
 *
 * @spec openspec/changes/mdm-reverse-fk-source-resolution/tasks.md#4.1
 */
class MasterRecomputeService
{
    /**
     * Wire collaborators.
     *
     * @param ObjectService   $objectService Object read/write path (RBAC + tenant scoped).
     * @param LoggerInterface $logger        PSR logger for warnings.
     *
     * @return void
     *
     * @spec openspec/changes/mdm-reverse-fk-source-resolution/tasks.md#4.1
     */
    public function __construct(
        private readonly ObjectService $objectService,
        private readonly LoggerInterface $logger
    ) {
    }//end __construct()

    /**
     * Recompute a master's golden record by re-persisting it, which re-runs
     * the survivorship recompute listener over the master's (reverse-FK)
     * source set. Best-effort — a failure is logged and swallowed.
     *
     * A master that no longer resolves is a stale no-op, not an error: the
     * source object may have been reassigned or the master deleted between
     * the triggering write and this call.
     *
     * @param string $masterUuid Referenced master uuid.
     *
     * @return void
     *
     * @spec openspec/changes/mdm-reverse-fk-source-resolution/tasks.md#4.1
     *
     * @SuppressWarnings(PHPMD.StaticAccess) `MergeService::normaliseRoundTripDates`
     *   is a pure stateless date-format utility shared with the merge relink;
     *   a static call is the lightest way to reuse it without injecting the
     *   whole MergeService (which would raise coupling for one helper).
     */
    public function recompute(string $masterUuid): void
    {
        if ($masterUuid === '') {
            return;
        }

        try {
            $master = $this->objectService->find(id: $masterUuid, _rbac: true, _multitenancy: true);
            if ($master === null) {
                return;
            }

            // Re-persist: the survivorship recompute listener fires on the
            // resulting update event and rematerialises the golden record. Date
            // fields are normalised back to ISO first — OR stores them in a
            // space-separated form its own validation rejects on a round-trip
            // save (see MergeService::normaliseRoundTripDates).
            $master->setObject(MergeService::normaliseRoundTripDates(data: ($master->getObject() ?? [])));
            $this->objectService->saveObject(
                object: $master,
                register: $master->getRegister(),
                schema: $master->getSchema(),
                uuid: $master->getUuid()
            );
        } catch (Throwable $e) {
            $this->logger->warning(
                sprintf('Source-record change: could not recompute master "%s": %s', $masterUuid, $e->getMessage())
            );
        }//end try
    }//end recompute()
}//end class
