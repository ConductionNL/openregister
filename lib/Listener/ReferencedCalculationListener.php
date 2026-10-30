<?php

/**
 * OpenRegister referenced calculation listener
 *
 * When an object is updated, refreshes the materialised calculations of the
 * objects that read one of its changed fields through `@ref` (a case's
 * `hasStartableFlows` after its case type lists flows).
 *
 * The listener only decides inline whether a refresh is needed (schema reads,
 * no referrer reads, no writes); the re-saves themselves are queued through
 * ListenerDeferralService as ReferencedCalculationJob (ADR-078), so the
 * user's update returns before up to SYNC_LIMIT referrers per dependency are
 * re-saved and their own post-write listeners fire.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Listener
 * @package  OCA\OpenRegister\Listener
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/rematerialise-on-referenced-change/specs/computed-fields/spec.md#requirement-a-referenced-change-refreshes-the-materialised-values-that-read-it
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Listener;

use OCA\OpenRegister\BackgroundJob\ReferencedCalculationJob;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\OpenRegister\Service\Calculation\ReferencedCalculationRefresher;
use OCA\OpenRegister\Service\Deferral\ListenerDeferralService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Refreshes the materialised values that read an updated object.
 *
 * @template-implements IEventListener<ObjectUpdatedEvent>
 *
 * @spec openspec/changes/rematerialise-on-referenced-change/specs/computed-fields/spec.md#requirement-a-referenced-change-refreshes-the-materialised-values-that-read-it
 */
class ReferencedCalculationListener implements IEventListener {

	/**
	 * Entries per enqueued refresh job.
	 *
	 * @var integer
	 */
	private const CHUNK_SIZE = 50;

	/**
	 * Constructor.
	 *
	 * @param ReferencedCalculationRefresher $refresher Re-saves the objects that read the updated one.
	 * @param LoggerInterface                $logger    The logger.
	 * @param ListenerDeferralService        $deferral  Queues the refresh as an actor-forwarded job.
	 */
	public function __construct(
		private readonly ReferencedCalculationRefresher $refresher,
		private readonly LoggerInterface $logger,
		private readonly ListenerDeferralService $deferral,
	) {
	}//end __construct()

	/**
	 * Queue the refresh of the objects that read the updated object; never fails the save that already happened.
	 *
	 * Runs the refresh inline only when the deferral kill switch
	 * (`listenerDeferral = inline`) is set.
	 *
	 * @param Event $event The inbound event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/rematerialise-on-referenced-change/specs/computed-fields/spec.md#requirement-a-referenced-change-refreshes-the-materialised-values-that-read-it
	 */
	public function handle(Event $event): void {
		if (($event instanceof ObjectUpdatedEvent) === false) {
			return;
		}

		try {
			$updated = $event->getNewObject();
			$changed = $this->refresher->changedReadFields(updated: $updated, old: $event->getOldObject());
			if ($changed === []) {
				return;
			}

			$depth = $this->refresher->depth();
			if ($this->deferral->isDeferralEnabled() === false) {
				$this->refresher->refreshChanged(updated: $updated, changed: $changed, depth: $depth);
				return;
			}

			$this->deferral->defer(
				jobClass: ReferencedCalculationJob::class,
				entry: [
					'uuid'     => (string) $updated->getUuid(),
					'register' => (string) $updated->getRegister(),
					'schema'   => (string) $updated->getSchema(),
					'version'  => $updated->getVersion(),
					'changed'  => $changed,
					'depth'    => $depth,
				],
				chunkSize: self::CHUNK_SIZE
			);
		} catch (Throwable $e) {
			$this->logger->warning('[ReferencedCalculationListener] refresh failed: '.$e->getMessage());
		}
	}//end handle()
}//end class
