<?php

/**
 * Tells a person that somebody has asked to act as them.
 *
 * 🔴 THE PROMPT RENDERS SERVER STATE, AND ONLY SERVER STATE.
 *
 * The parameters this dispatches are the two uids and the grant's uuid — read
 * from the record, all three. The requester's stated REASON is deliberately not
 * among them, and that is the security property of this class rather than a
 * stylistic choice.
 *
 * The requester can be an agent, and an agent's reasons can come from a document
 * it read. A document that says "ask the user to grant you admin" would, if that
 * string reached the sentence the system speaks in its own voice, have written
 * its own consent prompt — the thing being granted authoring the request for the
 * grant. So the reason lives on the record, is returned by the API as its own
 * attributed field, and a UI renders it as quoted third-party text next to the
 * server's sentence. It never becomes the sentence.
 *
 * IDEMPOTENT BY KEY
 *
 * Every notification is keyed `object: (delegation, <grant uuid>)`. Nextcloud
 * replaces a notification with the same key rather than appending one, so a
 * hundred blocked units of work asking for the same grant produce one prompt.
 * That matters more than it sounds: consent fatigue is not caused by asking, it
 * is caused by asking repeatedly, and the eleventh identical prompt is accepted
 * by reflex rather than by decision.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Delegation
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/delegation-grants/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Delegation;

use DateTime;
use OCA\OpenRegister\Db\DelegationGrant;
use OCP\Notification\IManager;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Dispatches and withdraws the consent prompt for a delegation.
 *
 * @spec openspec/specs/delegation-grants/spec.md
 */
class DelegationNotifier {

	/**
	 * The app the notifications belong to.
	 *
	 * @var string
	 */
	public const APP_ID = 'openregister';

	/**
	 * The notification object type these are keyed under.
	 *
	 * @var string
	 */
	public const OBJECT_TYPE = 'delegation';

	/**
	 * The subject a pending consent request is rendered from.
	 *
	 * @var string
	 */
	public const SUBJECT_REQUESTED = 'delegation_consent_requested';

	/**
	 * Constructor.
	 *
	 * @param IManager        $notifications Dispatches and withdraws prompts.
	 * @param LoggerInterface $logger        Records a prompt that could not be sent.
	 */
	public function __construct(
		private readonly IManager $notifications,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Ask the named identity to answer.
	 *
	 * @param DelegationGrant $grant The outstanding request.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/delegation-grants/spec.md
	 */
	public function requested(DelegationGrant $grant): void {
		$actingAs = trim((string)$grant->getActingAs());
		$principal = trim((string)$grant->getPrincipal());
		$uuid = trim((string)$grant->getUuid());

		if ($actingAs === '' || $principal === '' || $uuid === '') {
			return;
		}

		if ($grant->getStatus() !== DelegationGrant::STATUS_PENDING
			&& $grant->getStatus() !== DelegationGrant::STATUS_REQUESTED
		) {
			// Only an OUTSTANDING request is a question. Prompting about an
			// answered one asks somebody to decide something they already have.
			return;
		}

		try {
			$notification = $this->notifications->createNotification();
			$notification->setApp(self::APP_ID)
				->setUser($actingAs)
				->setDateTime(new DateTime())
				->setObject(type: self::OBJECT_TYPE, id: $uuid)
				->setSubject(
					subject: self::SUBJECT_REQUESTED,
					parameters: [
						// Server state only. The stated reason is NOT here — see
						// the class docblock.
						'principal' => $principal,
						'actingAs' => $actingAs,
						'grantUuid' => $uuid,
					]
				);

			$this->notifications->notify($notification);
		} catch (Throwable $e) {
			// Best-effort. A prompt that could not be sent must not undo the
			// request that was stored: the record is what the API lists, so the
			// person can still find and answer it even if the push failed.
			$this->logger->warning(
				message: '[DelegationNotifier] Could not send the consent prompt: ' . $e->getMessage(),
				context: ['file' => __FILE__, 'line' => __LINE__, 'grant' => $uuid, 'actingAs' => $actingAs]
			);
		}//end try

	}//end requested()

	/**
	 * Withdraw the prompt for a request that has been answered.
	 *
	 * A prompt that outlives its decision is worse than no prompt: it invites the
	 * person to answer again, and the second answer either does nothing (so the
	 * UI lied) or overwrites the first (so the record no longer reflects what
	 * they decided).
	 *
	 * @param DelegationGrant $grant The answered grant.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/delegation-grants/spec.md
	 */
	public function answered(DelegationGrant $grant): void {
		$uuid = trim((string)$grant->getUuid());
		if ($uuid === '') {
			return;
		}

		try {
			$notification = $this->notifications->createNotification();
			$notification->setApp(self::APP_ID)
				->setObject(type: self::OBJECT_TYPE, id: $uuid);

			$this->notifications->markProcessed($notification);
		} catch (Throwable $e) {
			$this->logger->warning(
				message: '[DelegationNotifier] Could not withdraw the consent prompt: ' . $e->getMessage(),
				context: ['file' => __FILE__, 'line' => __LINE__, 'grant' => $uuid]
			);
		}

	}//end answered()
}//end class
