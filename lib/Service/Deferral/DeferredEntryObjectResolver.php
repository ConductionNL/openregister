<?php

/**
 * OpenRegister DeferredEntryObjectResolver
 *
 * Shared re-fetch step of the actor-forwarded deferral contract: resolves a
 * job entry's (uuid, register, schema) back to the CURRENT ObjectEntity.
 * Delivery is at-least-once, so the deferred work must reconcile against
 * current state — an entry whose object is gone or soft-deleted resolves to
 * null and the job treats it as a stale no-op (this also closes the
 * update-then-delete race: a projection job landing after the delete's
 * inline purge must not resurrect sidecar rows).
 *
 * The lookup passes register + schema so MagicMapper targets one magic table
 * (never the bare-UUID cross-table scan), and runs with `_rbac: false` /
 * `_multitenancy: false` / `_render: false` — the inline listener received
 * the persisted entity directly; the forwarded actor exists for attribution
 * and for the deferred work's OWN session reads, not for gating the re-fetch.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Deferral
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Deferral;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use Psr\Log\LoggerInterface;

/**
 * Resolves deferred job entries back to live objects, stale-safe.
 *
 * @spec openspec/changes/actor-forwarded-listener-jobs/tasks.md#task-1.4
 */
class DeferredEntryObjectResolver
{
    /**
     * Wire the object lookup.
     *
     * @param ObjectService   $objectService Object lookup service.
     * @param LoggerInterface $logger        PSR logger.
     *
     * @return void
     */
    public function __construct(
        private readonly ObjectService $objectService,
        private readonly LoggerInterface $logger
    ) {
    }//end __construct()

    /**
     * Re-fetch the current object for a job entry.
     *
     * @param array<string, mixed> $entry Entry carrying `uuid`, `register`, `schema`.
     *
     * @return ObjectEntity|null Current entity, or null when the entry is
     *                           malformed, the object no longer exists, or it
     *                           has been soft-deleted (stale no-op signal).
     *
     * @spec openspec/specs/event-driven-architecture/spec.md
     */
    public function resolve(array $entry): ?ObjectEntity
    {
        $uuid = $this->nonEmptyString(value: ($entry['uuid'] ?? null));
        if ($uuid === null) {
            return null;
        }

        try {
            $object = $this->objectService->find(
                id: $uuid,
                register: $this->nonEmptyString(value: ($entry['register'] ?? null)),
                schema: $this->nonEmptyString(value: ($entry['schema'] ?? null)),
                _rbac: false,
                _multitenancy: false,
                _render: false
            );
        } catch (\Throwable $e) {
            $this->logger->info(
                message: '[DeferredEntryObjectResolver] Object no longer resolvable — stale entry skipped',
                context: [
                    'file'  => __FILE__,
                    'line'  => __LINE__,
                    'uuid'  => $uuid,
                    'error' => $e->getMessage(),
                ]
            );
            return null;
        }//end try

        if ($object === null) {
            return null;
        }

        // Soft-deleted objects are stale for deferred side effects: the
        // delete path already ran its inline cleanup (e.g. translation
        // purge) and re-projecting/notifying would resurrect state.
        $deleted = ($object->getDeleted() ?? []);
        if (count($deleted) > 0) {
            return null;
        }

        return $object;
    }//end resolve()

    /**
     * Normalise a raw entry field to a non-empty string or null.
     *
     * @param mixed $value Raw entry value.
     *
     * @return string|null Non-empty string, or null for anything else.
     */
    private function nonEmptyString(mixed $value): ?string
    {
        if (is_string($value) === true && $value !== '') {
            return $value;
        }

        return null;
    }//end nonEmptyString()
}//end class
