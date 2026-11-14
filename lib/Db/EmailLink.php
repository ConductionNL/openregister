<?php

/**
 * EmailLink entity for linking Nextcloud Mail messages to OpenRegister objects.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Db
 * @package  OCA\OpenRegister\Db
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

use DateTime;
use JsonSerializable;
use OCA\OpenRegister\Db\EmailLink\EmailLinkFieldTypes;
use OCP\AppFramework\Db\Entity;

/**
 * Class EmailLink
 *
 * @method string getObjectUuid()
 * @method void setObjectUuid(string $objectUuid)
 * @method int getRegisterId()
 * @method void setRegisterId(int $registerId)
 * @method int|null getSchemaId()
 * @method void setSchemaId(?int $schemaId)
 * @method int getMailAccountId()
 * @method void setMailAccountId(int $mailAccountId)
 * @method int getMailMessageId()
 * @method void setMailMessageId(int $mailMessageId)
 * @method string|null getMailMessageUid()
 * @method void setMailMessageUid(?string $mailMessageUid)
 * @method string|null getSubject()
 * @method void setSubject(?string $subject)
 * @method string|null getSender()
 * @method void setSender(?string $sender)
 * @method string|null getSenderName()
 * @method void setSenderName(?string $senderName)
 * @method DateTime|null getMailDate()
 * @method void setMailDate(?DateTime $mailDate)
 * @method string|null getMetadata()
 * @method void setMetadata(?string $metadata)
 * @method string getLinkedBy()
 * @method void setLinkedBy(string $linkedBy)
 * @method DateTime getLinkedAt()
 * @method string|null getRfcMessageId()
 * @method void setRfcMessageId(?string $rfcMessageId)
 * @method string|null getDirection()
 * @method void setDirection(?string $direction)
 * @method void setLinkedAt(DateTime $linkedAt)
 *
 * @psalm-suppress PropertyNotSetInConstructor $id is set by Nextcloud's Entity base class
 */
class EmailLink extends Entity implements JsonSerializable {

	/**
	 * The link details read from the metadata and shown beside the fields: the
	 * mail's direction, the other party's name on an outgoing mail, how many
	 * attachments it has, how it was linked (manual, mailbox, file), its
	 * archive copy (such as pdf) and the shared mailbox it came in through.
	 */
	public const DETAIL_KEYS = ['direction', 'recipientName', 'attachmentCount', 'linkedVia', 'archivedAs', 'mailboxName'];

	/**
	 * The object uuid.
	 *
	 * @var string|null
	 */
	protected ?string $objectUuid = null;

	/**
	 * The register id.
	 *
	 * @var integer|null
	 */
	protected ?int $registerId = null;

	/**
	 * The schema id (nullable).
	 *
	 * @var integer|null
	 */
	protected ?int $schemaId = null;

	/**
	 * The mail account id.
	 *
	 * @var integer|null
	 */
	protected ?int $mailAccountId = null;

	/**
	 * The mail message id.
	 *
	 * @var integer|null
	 */
	protected ?int $mailMessageId = null;

	/**
	 * The mail message uid.
	 *
	 * @var string|null
	 */
	protected ?string $mailMessageUid = null;

	/**
	 * The subject.
	 *
	 * @var string|null
	 */
	protected ?string $subject = null;

	/**
	 * The sender.
	 *
	 * @var string|null
	 */
	protected ?string $sender = null;

	/**
	 * The sender's display name, where the sender is the address.
	 *
	 * @var string|null
	 */
	protected ?string $senderName = null;

	/**
	 * The mail date.
	 *
	 * @var DateTime|null
	 */
	protected ?DateTime $mailDate = null;

	/**
	 * Free-form metadata (JSON-encoded string) for future per-row hints.
	 *
	 * @var string|null
	 */
	protected ?string $metadata = null;

	/**
	 * The linked by.
	 *
	 * @var string|null
	 */
	protected ?string $linkedBy = null;

	/**
	 * The linked at.
	 *
	 * @var DateTime|null
	 */
	protected ?DateTime $linkedAt = null;

	/**
	 * The longest `Message-ID` the column holds.
	 */
	public const RFC_MESSAGE_ID_LENGTH = 255;

	/**
	 * The RFC `Message-ID` of the linked mail, with its angle brackets.
	 *
	 * @var string|null
	 */
	protected ?string $rfcMessageId = null;

	/**
	 * A mail that arrived and was linked.
	 */
	public const DIRECTION_INBOUND = 'inbound';

	/**
	 * A mail the app sent bound to the object.
	 */
	public const DIRECTION_OUTBOUND = 'outbound';

	/**
	 * Whether the mail came in or went out: `inbound`, `outbound`, or null on a row from before the column.
	 *
	 * @var string|null
	 */
	protected ?string $direction = null;

	/**
	 * Constructor.
	 */
	public function __construct() {
		foreach (EmailLinkFieldTypes::TYPES as $fieldName => $type) {
			$this->addType(fieldName: $fieldName, type: $type);
		}
	}//end __construct()

	/**
	 * A `Message-ID` as the column holds it: null when it does not fit.
	 *
	 * A longer id is rare and legal. Truncated, it could equal another message's
	 * id, and a reply would thread onto the wrong object; absent, the reply is
	 * `unthreaded` and a person sees it.
	 *
	 * @param string|null $messageId The id.
	 *
	 * @return string|null The id to store.
	 *
	 * @spec openspec/changes/reply-threading-by-headers/specs/integration-email/spec.md
	 */
	public static function storableMessageId(string|null $messageId): string|null {
		if ($messageId === null || $messageId === '' || strlen($messageId) > self::RFC_MESSAGE_ID_LENGTH) {
			return null;
		}

		return $messageId;
	}//end storableMessageId()

	/**
	 * The link details from the metadata, one entry per DETAIL_KEYS key, null when absent.
	 *
	 * `direction` is the metadata's when it names one (a mail linked from a
	 * Sent folder says `outbound`), else the column's.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/specs/email-link-sender-name-and-details/spec.md#requirement-an-email-link-carries-the-details-the-row-shows
	 * @spec openspec/changes/reply-threading-by-headers/specs/integration-email/spec.md
	 */
	public function details(): array {
		$metadata = json_decode((string) $this->metadata, true);
		if (is_array($metadata) === false) {
			$metadata = [];
		}

		$details = [];
		foreach (self::DETAIL_KEYS as $key) {
			$details[$key] = ($metadata[$key] ?? null);
		}

		$details['direction'] ??= $this->direction;

		return $details;
	}//end details()

	/**
	 * JSON serialization.
	 *
	 * @return array
	 *
	 * @spec openspec/specs/email-link-sender-name-and-details/spec.md#requirement-an-email-link-carries-the-details-the-row-shows
	 */
	public function jsonSerialize(): array {
		$typed = new TypedFieldsJson();
		$json = $typed->pick(values: get_object_vars($this), names: array_diff(array_keys($this->getFieldTypes()), ['rfcMessageId', 'direction']));

		return $typed->inserted(json: $json, entriesAfter: ['metadata' => ['details' => $this->details()]]);
	}//end jsonSerialize()
}//end class
