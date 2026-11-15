<?php

/**
 * OpenRegister MailHeader Entity
 *
 * The thread headers of one collected `.eml` file: its Message-ID, the one
 * message it answers, its whole ancestry and when it was sent. One row per
 * file, so a thread can be assembled without reparsing every message.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Database
 * @package  OCA\OpenRegister\Files\Db
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/reply-threading-by-headers/specs/integration-email/spec.md#requirement-collected-mail-files-carry-their-thread-headers-req-rth-010
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Files\Db;

use DateTime;
use DateTimeInterface;
use JsonSerializable;
use OCA\OpenRegister\Db\TypedFieldsJsonTrait;
use OCP\AppFramework\Db\Entity;

/**
 * The thread headers of one collected mail file.
 *
 * @method int getFileId()
 * @method void setFileId(int $fileId)
 * @method string getMessageId()
 * @method void setMessageId(string $messageId)
 * @method string getInReplyTo()
 * @method void setInReplyTo(string $inReplyTo)
 * @method string getReferenceIds()
 * @method void setReferenceIds(string $referenceIds)
 * @method DateTime|null getSentAt()
 * @method void setSentAt(?DateTime $sentAt)
 *
 * @psalm-suppress PropertyNotSetInConstructor $id is set by Nextcloud's Entity base class
 *
 * @spec openspec/changes/reply-threading-by-headers/specs/integration-email/spec.md#requirement-collected-mail-files-carry-their-thread-headers-req-rth-010
 */
class MailHeader extends Entity implements JsonSerializable {
	// Serialises every registered field in registration order, dates as ISO 8601.
	use TypedFieldsJsonTrait;

	/**
	 * The longest message id the columns hold.
	 *
	 * @var integer
	 */
	public const MAX_ID_LENGTH = 255;

	/**
	 * The Nextcloud file id of the `.eml` file.
	 *
	 * @var integer
	 */
	protected int $fileId = 0;

	/**
	 * The file's Message-ID, angle brackets stripped; '' when it has none.
	 *
	 * @var string
	 */
	protected string $messageId = '';

	/**
	 * The Message-ID this mail answers, angle brackets stripped; '' when none.
	 *
	 * @var string
	 */
	protected string $inReplyTo = '';

	/**
	 * The References chain, oldest first, as a JSON list of ids.
	 *
	 * @var string
	 */
	protected string $referenceIds = '[]';

	/**
	 * When the mail says it was sent, null when its Date header is absent or unreadable.
	 *
	 * @var DateTime|null
	 */
	protected ?DateTime $sentAt = null;

	/**
	 * Register the field types.
	 */
	public function __construct() {
		$this->addType(fieldName: 'fileId', type: 'integer');
		$this->addType(fieldName: 'messageId', type: 'string');
		$this->addType(fieldName: 'inReplyTo', type: 'string');
		$this->addType(fieldName: 'referenceIds', type: 'string');
		$this->addType(fieldName: 'sentAt', type: 'datetime');
	}//end __construct()

	/**
	 * The row for a file, from the headers `EmlParser` read.
	 *
	 * An id longer than the column is dropped rather than cut: a truncated id
	 * would match nothing, or worse, a different message.
	 *
	 * @param int                  $fileId  The Nextcloud file id.
	 * @param array<string, mixed> $headers The parsed headers (`messageId`, `inReplyTo`, `references`, `date`).
	 *
	 * @return self The row, not yet stored.
	 *
	 * @spec openspec/changes/reply-threading-by-headers/specs/integration-email/spec.md#requirement-collected-mail-files-carry-their-thread-headers-req-rth-010
	 */
	public static function fromHeaders(int $fileId, array $headers): self {
		$row = new self();
		$row->setFileId($fileId);
		$row->setMessageId(self::storableId(id: $headers['messageId'] ?? null));
		$row->setInReplyTo(self::storableId(id: $headers['inReplyTo'] ?? null));

		$references = [];
		foreach ((array)($headers['references'] ?? []) as $reference) {
			$id = self::storableId(id: $reference);
			if ($id !== '') {
				$references[] = $id;
			}
		}

		$row->setReferenceIds((string)json_encode($references, JSON_UNESCAPED_SLASHES));

		$date = ($headers['date'] ?? null);
		if ($date instanceof DateTimeInterface) {
			$row->setSentAt(DateTime::createFromInterface($date));
		}

		return $row;
	}//end fromHeaders()

	/**
	 * The References chain, oldest first.
	 *
	 * @return array<int, string> The ids.
	 *
	 * @spec openspec/changes/reply-threading-by-headers/specs/integration-email/spec.md#requirement-collected-mail-files-carry-their-thread-headers-req-rth-010
	 */
	public function references(): array {
		$decoded = json_decode($this->referenceIds, true);
		if (is_array($decoded) === false) {
			return [];
		}

		return array_values(array_filter($decoded, static fn (mixed $id): bool => is_string($id) === true && $id !== ''));
	}//end references()

	/**
	 * A message id as the columns hold it: trimmed, brackets stripped, '' when absent or too long.
	 *
	 * @param mixed $id The id as parsed.
	 *
	 * @return string The storable id.
	 */
	private static function storableId(mixed $id): string {
		if (is_string($id) === false) {
			return '';
		}

		$id = trim(trim($id), '<>');
		if (strlen($id) > self::MAX_ID_LENGTH) {
			return '';
		}

		return $id;
	}//end storableId()
}//end class
