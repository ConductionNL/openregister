<?php

/**
 * When reported content is removed, the report says so and names the copy.
 *
 * Without this listener the copy would still be taken and still be readable,
 * and a reviewer opening a report on removed content would find no sign that
 * the content had gone. That is the failure the requirement's own scenario
 * describes: "deleting the content does not delete the evidence" is only half
 * kept if the evidence cannot say that the deletion happened.
 *
 * It listens on ObjectDeletedEvent, which MagicMapper dispatches on the
 * canonical write path every Conduction app inherits, so no hot-path service
 * had to be modified to get full coverage of removals.
 *
 * Best-effort by construction. A bookkeeping write that fails must never block
 * a removal somebody may be legally required to make, and the copy itself was
 * already safe long before this ran.
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
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/audit-trail-shipped-and-purpose-bound/specs/enhanced-audit-trail/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Listener;

use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Event\ObjectDeletedEvent;
use OCA\OpenRegister\Service\Audit\ContentReportService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Match a removal to the copies taken before it.
 *
 * @template-implements IEventListener<ObjectDeletedEvent>
 *
 * @spec openspec/changes/audit-trail-shipped-and-purpose-bound/specs/enhanced-audit-trail/spec.md
 */
class ContentReportRemovalListener implements IEventListener {
	/**
	 * Wire collaborators.
	 *
	 * @param ContentReportService $reports The reports and their copies.
	 * @param AuditTrailMapper $auditTrail Records the removal against the copy.
	 * @param LoggerInterface $logger PSR logger for warnings.
	 */
	public function __construct(
		private readonly ContentReportService $reports,
		private readonly AuditTrailMapper $auditTrail,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Note the removal on every report filed against the removed content.
	 *
	 * @param Event $event Inbound dispatcher event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/audit-trail-shipped-and-purpose-bound/specs/enhanced-audit-trail/spec.md
	 */
	public function handle(Event $event): void {
		if (($event instanceof ObjectDeletedEvent) === false) {
			return;
		}

		try {
			$object = $event->getObject();
			$objectUuid = (string)($object->getUuid() ?? '');
			if ($objectUuid === '') {
				return;
			}

			$copies = $this->reports->noteRemoval(objectUuid: $objectUuid);
			if ($copies === []) {
				// Nothing was ever reported about this content, which is the
				// ordinary case. No entry: an audit row per uneventful delete
				// would double the largest table in the app for no reader.
				return;
			}

			// The removal record names the copy, which is the half of the
			// requirement the report row alone does not satisfy: somebody
			// reading the TRAIL has to be able to get to the evidence too.
			$this->auditTrail->createAuditTrailEntry(
				object: $object,
				action: ContentReportService::ACTION_REMOVAL_NAMED,
				context: [
					'contentReports' => $copies,
					'reason' => 'reported content removed; the copies taken at filing time survive it',
				]
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				message: '[ContentReportRemovalListener] Could not name the copies on a removal',
				context: [
					'app' => 'openregister',
					'error' => $e->getMessage(),
				]
			);
		}//end try
	}//end handle()
}//end class
