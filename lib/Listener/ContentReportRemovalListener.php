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
 * @spec openspec/specs/enhanced-audit-trail/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Listener;

use OCA\OpenRegister\Db\AuditTrail\ObjectAuditEvent;
use OCA\OpenRegister\Db\AuditTrailMapperInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectDeletedEvent;
use OCA\OpenRegister\Listener\Support\HandlesObjectDeletion;
use OCA\OpenRegister\Service\Audit\ContentReportServiceInterface;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Match a removal to the copies taken before it.
 *
 * @template-implements IEventListener<ObjectDeletedEvent>
 *
 * @spec openspec/specs/enhanced-audit-trail/spec.md
 */
class ContentReportRemovalListener implements IEventListener {
	use HandlesObjectDeletion;

	/**
	 * Wire collaborators.
	 *
	 * @param ContentReportServiceInterface $reports The reports and their copies.
	 * @param AuditTrailMapperInterface $auditTrail Records the removal against the copy.
	 * @param LoggerInterface $logger PSR logger for warnings.
	 */
	public function __construct(
		private readonly ContentReportServiceInterface $reports,
		private readonly AuditTrailMapperInterface $auditTrail,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Note the removal on every report filed against the removed content.
	 *
	 * @param ObjectEntity $object The removed object.
	 * @param string       $uuid   Its uuid.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/enhanced-audit-trail/spec.md
	 */
	private function onObjectDeleted(ObjectEntity $object, string $uuid): void {
		$copies = $this->reports->noteRemoval(objectUuid: $uuid);
		if ($copies === []) {
			// Nothing was ever reported about this content, which is the
			// ordinary case. No entry: an audit row per uneventful delete
			// would double the largest table in the app for no reader.
			return;
		}

		// The removal record names the copy, which is the half of the
		// requirement the report row alone does not satisfy: somebody
		// reading the TRAIL has to be able to get to the evidence too.
		$this->auditTrail->recordObjectEvent(
			event: new ObjectAuditEvent(
				object: $object,
				action: ContentReportServiceInterface::ACTION_REMOVAL_NAMED,
				context: [
					'contentReports' => $copies,
					'reason' => 'reported content removed; the copies taken at filing time survive it',
				]
			)
		);
	}//end onObjectDeleted()

	/**
	 * Warn that the copies could not be named; the removal itself stands.
	 *
	 * @param Throwable $error The failure.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/enhanced-audit-trail/spec.md
	 */
	private function onDeletionFailure(Throwable $error): void {
		$this->logger->warning(
			message: '[ContentReportRemovalListener] Could not name the copies on a removal',
			context: [
				'app' => 'openregister',
				'error' => $error->getMessage(),
			]
		);
	}//end onDeletionFailure()
}//end class
