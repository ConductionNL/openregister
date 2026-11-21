<?php

/**
 * Writes the uses of access links to the audit trail.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Audit
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/access-by-link-not-by-account/specs/public-access-links/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Audit;

use OCA\OpenRegister\Db\AccessLink;
use OCA\OpenRegister\Db\AuditTrail\NamedActor;
use OCA\OpenRegister\Db\AuditTrail\ObjectAuditEvent;
use OCA\OpenRegister\Db\AuditTrail\ObjectAuditEventOptions;
use OCA\OpenRegister\Db\AuditTrailMapperInterface;
use OCA\OpenRegister\Kernel\Contracts\Core\AccessLinkUseRecorderInterface;
use OCA\OpenRegister\Service\Sharing\AccessLinkUse;

/**
 * One hash-chained object entry per link use, naming the link as the actor.
 *
 * The entry carries the caller's context plus what the link is: its uuid, its declared
 * capabilities, its subject and the address it was used from. The link's own keys win
 * over the caller's, so a caller cannot rename the subject on the trail.
 *
 * @spec openspec/changes/access-by-link-not-by-account/specs/public-access-links/spec.md#requirement-a-revoked-or-expired-link-answers-404-and-every-use-is-recorded-req-abl-003
 */
final class AccessLinkUseRecorder implements AccessLinkUseRecorderInterface {

	/**
	 * Constructor.
	 *
	 * @param AuditTrailMapperInterface $auditTrail The trail, which owns the hash chain.
	 */
	public function __construct(
		private readonly AuditTrailMapperInterface $auditTrail,
	) {
	}//end __construct()

	/**
	 * Write the entry for one use; a use without an object writes nothing, a failed write is thrown.
	 *
	 * @param AccessLink    $link The link that acted.
	 * @param AccessLinkUse $use  What it did, to which object, from which address, with what context.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/access-by-link-not-by-account/specs/public-access-links/spec.md#requirement-a-revoked-or-expired-link-answers-404-and-every-use-is-recorded-req-abl-003
	 */
	public function recordLinkUse(AccessLink $link, AccessLinkUse $use): void {
		if ($use->object === null) {
			return;
		}

		$this->auditTrail->recordObjectEvent(
			event: new ObjectAuditEvent(
				object: $use->object,
				action: $use->act,
				context: array_merge(
					$use->context,
					[
						'accessLink' => $link->getUuid(),
						'capabilities' => $link->declaredCapabilities(),
						'subjectType' => $link->getSubjectType(),
						'subjectId' => $link->getSubjectId(),
						'ipAddress' => $use->ipAddress,
					]
				),
				options: new ObjectAuditEventOptions(actor: new NamedActor(id: $link->principalId(), name: $link->principalName()), ipAddress: $use->ipAddress)
			)
		);
	}//end recordLinkUse()
}//end class
