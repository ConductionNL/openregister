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

use OCA\OpenRegister\BackgroundJob\Support\DeferredObjectEventActor;
use OCA\OpenRegister\BackgroundJob\Support\DeferredObjectEventLookup;
use OCA\OpenRegister\BackgroundJob\Support\DeferredObjectEventPlatform;
use OCA\OpenRegister\Service\Object\ObjectEventGate;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\QueuedJob;
use Throwable;

/**
 * Re-dispatches a lifecycle event for one object, outside the write request.
 */
class DeferredObjectEventJob extends QueuedJob {
	/**
	 * Constructor.
	 *
	 * @param ITimeFactory                $time     Clock for the job base class.
	 * @param DeferredObjectEventLookup   $lookup   Object, register and schema mappers for the scoped re-read.
	 * @param DeferredObjectEventActor    $actor    Session and user manager that restore the acting user.
	 * @param DeferredObjectEventPlatform $platform Event dispatcher and logger.
	 *
	 * @spec openspec/changes/object-write-sub-500ms/specs/object-write-performance/spec.md
	 */
	public function __construct(
		ITimeFactory $time,
		private readonly DeferredObjectEventLookup $lookup,
		private readonly DeferredObjectEventActor $actor,
		private readonly DeferredObjectEventPlatform $platform,
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
	 *
	 * @spec openspec/changes/draft-creation-side-effects/specs/object-events/spec.md#requirement-creation-side-effects-must-be-held-for-a-draft-and-run-once-when-it-leaves-draft
	 */
	protected function run($argument): void {
		$uuid = (string)(((array)$argument)['uuid'] ?? '');
		if ($uuid === '') {
			return;
		}

		$restored = $this->restoreActingUser(uid: (string)(((array)$argument)['user'] ?? ''), uuid: $uuid);
		try {
			$this->dispatchCreated(
				uuid: $uuid,
				registerId: (int)(((array)$argument)['registerId'] ?? 0),
				schemaId: (int)(((array)$argument)['schemaId'] ?? 0)
			);
		} catch (Throwable $e) {
			// The object may legitimately be gone by now — a create followed by
			// a delete before the queue drained — so this is not an error. It is
			// logged at WARNING rather than INFO deliberately: a deferred event
			// that never fires is silent by construction, every listener simply
			// does not run, and the default loglevel (2 = warning) would hide an
			// INFO line. A side effect that vanished must be visible at the
			// level operators actually keep.
			$this->platform->logger->warning(
				message: '[DeferredObjectEventJob] Could not dispatch deferred event for ' . $uuid . ': ' . $e->getMessage(),
				context: ['file' => __FILE__, 'line' => __LINE__, 'uuid' => $uuid]
			);
		} finally {
			// Do not leave the impersonation standing for whatever job the
			// worker runs next out of the same process.
			if ($restored === true) {
				$this->actor->userSession->setUser(null);
			}
		}//end try

	}//end run()

	/**
	 * Restore the acting user for the dispatch.
	 *
	 * A background job has no session, and OpenRegister reads are
	 * organisation-filtered against the session user — so listeners that
	 * consult the register (the CloudEvent firehose gate is the clearest
	 * example) see an empty instance and skip. Verified 2026-07-29:
	 * dispatching without this ran clean, logged nothing, and produced zero
	 * CloudEvents where the inline path produced one. Deferring side effects
	 * without carrying identity does not move the work — it deletes it.
	 *
	 * @param string $uid  The acting user's uid, empty when none was captured.
	 * @param string $uuid The object's uuid, for the warning.
	 *
	 * @return bool Whether a user was set on the session.
	 *
	 * @spec openspec/changes/draft-creation-side-effects/specs/object-events/spec.md#requirement-creation-side-effects-must-be-held-for-a-draft-and-run-once-when-it-leaves-draft
	 */
	private function restoreActingUser(string $uid, string $uuid): bool {
		$actingUser = null;
		if ($uid !== '') {
			$actingUser = $this->actor->userManager->get($uid);
		}

		if ($actingUser === null) {
			$this->platform->logger->warning(
				message: '[DeferredObjectEventJob] No acting user for ' . $uuid
					. '; listeners that read organisation-scoped data will see nothing.',
				context: ['file' => __FILE__, 'line' => __LINE__, 'uuid' => $uuid, 'uid' => $uid]
			);
			return false;
		}

		$this->actor->userSession->setUser($actingUser);
		return true;
	}//end restoreActingUser()

	/**
	 * Re-read the object, scoped to its register and schema, and dispatch its created event.
	 *
	 * @param string $uuid       The object's uuid.
	 * @param int    $registerId The register id, 0 when unknown.
	 * @param int    $schemaId   The schema id, 0 when unknown.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/draft-creation-side-effects/specs/object-events/spec.md#requirement-creation-side-effects-must-be-held-for-a-draft-and-run-once-when-it-leaves-draft
	 */
	private function dispatchCreated(string $uuid, int $registerId, int $schemaId): void {
		$register = null;
		$schema = null;
		if ($registerId > 0) {
			$register = $this->lookup->registerMapper->find($registerId);
		}

		if ($schemaId > 0) {
			$schema = $this->lookup->schemaMapper->find($schemaId);
		}

		// Scoped read: the register and schema are known, so this never
		// reaches the cross-table fan-out.
		$object = $this->lookup->objectMapper->find(
			identifier: $uuid,
			register: $register,
			schema: $schema,
			includeDeleted: false,
			_rbac: false,
			_multitenancy: false
		);

		(new ObjectEventGate(events: $this->platform->eventDispatcher))->created(object: $object);
	}//end dispatchCreated()
}//end class
