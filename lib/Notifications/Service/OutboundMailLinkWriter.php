<?php

/**
 * OutboundMailLinkWriter: the email link of a mail the app sent from an object.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Notifications\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Notifications\Service;

use DateTime;
use OCA\OpenRegister\Db\EmailLink;
use OCA\OpenRegister\Db\EmailLinkMapperInterface;
use OCA\OpenRegister\Notifications\Service\Notification\MailObjectBinding;

/**
 * Writes an `outbound` link with the minted `Message-ID`, so a reply to the
 * sent mail resolves onto the object by its `In-Reply-To`.
 *
 * A sent mail has no Mail app message: the account and message ids are 0 and
 * the uid is the `Message-ID`, which keeps the row unique per message.
 *
 * @spec openspec/changes/reply-threading-by-headers/specs/integration-email/spec.md
 */
class OutboundMailLinkWriter {

	/**
	 * Constructor.
	 *
	 * @param EmailLinkMapperInterface $links The email links.
	 */
	public function __construct(
		private readonly EmailLinkMapperInterface $links,
	) {
	}//end __construct()

	/**
	 * Record one sent mail on its object.
	 *
	 * @param MailObjectBinding $object       The object it was sent from.
	 * @param string            $rfcMessageId The minted `Message-ID`, with its angle brackets.
	 * @param string            $subject      The subject.
	 * @param string            $recipient    The recipient's display name or address.
	 *
	 * @return EmailLink The stored link.
	 *
	 * @spec openspec/changes/reply-threading-by-headers/specs/integration-email/spec.md
	 */
	public function record(MailObjectBinding $object, string $rfcMessageId, string $subject, string $recipient): EmailLink {
		$now = new DateTime();
		$link = new EmailLink();
		$link->setObjectUuid($object->objectUuid);
		$link->setRegisterId($object->registerId);
		if ($object->schemaId !== null) {
			$link->setSchemaId($object->schemaId);
		}

		$link->setMailAccountId(0);
		$link->setMailMessageId(0);
		$link->setMailMessageUid($rfcMessageId);
		$link->setRfcMessageId(EmailLink::storableMessageId(messageId: $rfcMessageId));
		$link->setDirection(EmailLink::DIRECTION_OUTBOUND);
		$link->setSubject(mb_substr($subject, 0, 512));
		$link->setMailDate($now);
		$link->setMetadata((string) json_encode(['direction' => EmailLink::DIRECTION_OUTBOUND, 'recipientName' => $recipient]));
		$link->setLinkedBy($object->linkedBy);
		$link->setLinkedAt($now);

		$this->links->insert(entity: $link);

		return $link;
	}//end record()
}//end class
