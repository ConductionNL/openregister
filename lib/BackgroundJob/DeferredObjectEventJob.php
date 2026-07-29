<?php

/**
 * Dispatch an object lifecycle event after the write that caused it committed.
 *
 * `ObjectCreatedEvent` is consumed by roughly two dozen listeners across the
 * fleet — flows, webhooks, CloudEvents, notifications, activity, aggregation,
 * translation projection, search indexing. Dispatched inline they all run
 * inside the HTTP request that performed the write, and the caller waits for
 * every one of them. Measured 2026-07-29 on the development instance, that
 * dispatch was 234-501 ms of a ~530 ms object create — more than the DB insert
 * (76 ms) by a factor of four.
 *
 * None of that work is something the caller needs in order to be told its
 * object was saved. This job carries it out of the request.
 *
 * The job carries identifiers, never the entity: a queued job is serialised to
 * the database, and the object it refers to may legitimately change (or be
 * deleted) before the job runs. Re-reading it at execution time means listeners
 * always see current state, and a deleted object simply produces no event
 * rather than a resurrection of stale data.
 *
 * @category BackgroundJob
 * @package  OCA\OpenRegister\BackgroundJob
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/object-write-sub-500ms/specs/object-write-performance/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\BackgroundJob;

use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\QueuedJob;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IUserManager;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Re-dispatches a lifecycle event for one object, outside the write request.
 */
class DeferredObjectEventJob extends QueuedJob
{
    /**
     * Constructor.
     *
     * @param ITimeFactory     $time            Clock for the job base class.
     * @param MagicMapper      $objectMapper    Re-reads the object at run time.
     * @param RegisterMapper   $registerMapper  Resolves the register to scope the read.
     * @param SchemaMapper     $schemaMapper    Resolves the schema to scope the read.
     * @param IEventDispatcher $eventDispatcher Dispatches the event.
     * @param IUserSession     $userSession     Restores the acting user for the dispatch.
     * @param IUserManager     $userManager     Resolves the acting user by uid.
     * @param LoggerInterface  $logger          The logger.
     */
    public function __construct(
        ITimeFactory $time,
        private readonly MagicMapper $objectMapper,
        private readonly RegisterMapper $registerMapper,
        private readonly SchemaMapper $schemaMapper,
        private readonly IEventDispatcher $eventDispatcher,
        private readonly IUserSession $userSession,
        private readonly IUserManager $userManager,
        private readonly LoggerInterface $logger
    ) {
        parent::__construct(time: $time);

    }//end __construct()

    /**
     * Re-read the object and dispatch its event.
     *
     * Never throws. This runs after the write it belongs to has already been
     * reported as successful, so a failure here must not be able to fail
     * anything — it is logged and the job ends.
     *
     * @param mixed $argument `{uuid, registerId, schemaId, action}`.
     *
     * @return void
     */
    protected function run($argument): void
    {
        $uuid = (string) (((array) $argument)['uuid'] ?? '');
        if ($uuid === '') {
            return;
        }

        $registerId = (int) (((array) $argument)['registerId'] ?? 0);
        $schemaId   = (int) (((array) $argument)['schemaId'] ?? 0);
        $actingUid  = (string) (((array) $argument)['user'] ?? '');

        // Restore the acting user for the dispatch. A background job has no
        // session, and OpenRegister reads are organisation-filtered against the
        // session user — so listeners that consult the register (the CloudEvent
        // firehose gate is the clearest example) see an empty instance and skip.
        // Verified 2026-07-29: dispatching without this ran clean, logged
        // nothing, and produced zero CloudEvents where the inline path produced
        // one. Deferring side effects without carrying identity does not move
        // the work — it deletes it.
        $restored = false;
        if ($actingUid !== '') {
            $actingUser = $this->userManager->get($actingUid);
            if ($actingUser !== null) {
                $this->userSession->setUser($actingUser);
                $restored = true;
            }
        }

        if ($restored === false) {
            $this->logger->warning(
                message: '[DeferredObjectEventJob] No acting user for '.$uuid
                    .'; listeners that read organisation-scoped data will see nothing.',
                context: ['file' => __FILE__, 'line' => __LINE__, 'uuid' => $uuid, 'uid' => $actingUid]
            );
        }

        try {
            $register = null;
            $schema   = null;
            if ($registerId > 0) {
                $register = $this->registerMapper->find($registerId);
            }

            if ($schemaId > 0) {
                $schema = $this->schemaMapper->find($schemaId);
            }

            // Scoped read: the register and schema are known, so this never
            // reaches the cross-table fan-out.
            $object = $this->objectMapper->find(
                identifier: $uuid,
                register: $register,
                schema: $schema,
                includeDeleted: false,
                _rbac: false,
                _multitenancy: false
            );

            $this->eventDispatcher->dispatchTyped(new ObjectCreatedEvent(object: $object));
        } catch (Throwable $e) {
            // The object may legitimately be gone by now — a create followed by
            // a delete before the queue drained — so this is not an error. It is
            // logged at WARNING rather than INFO deliberately: a deferred event
            // that never fires is silent by construction, every listener simply
            // does not run, and the default loglevel (2 = warning) would hide an
            // INFO line. A side effect that vanished must be visible at the
            // level operators actually keep.
            $this->logger->warning(
                message: '[DeferredObjectEventJob] Could not dispatch deferred event for '.$uuid.': '.$e->getMessage(),
                context: ['file' => __FILE__, 'line' => __LINE__, 'uuid' => $uuid]
            );
        } finally {
            // Do not leave the impersonation standing for whatever job the
            // worker runs next out of the same process.
            if ($restored === true) {
                $this->userSession->setUser(null);
            }
        }//end try

    }//end run()
}//end class
