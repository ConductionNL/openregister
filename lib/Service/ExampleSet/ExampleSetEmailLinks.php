<?php

/**
 * OpenRegister Example Set Email Links
 *
 * The mail an example set links to its objects: a fragment's `emailLinks` part
 * names the object and the mail as it shows (subject, sender, date). The mail
 * is a real Nextcloud Mail message given by its account and message ids, such
 * as one a GreenMail test account synced, or one declared by its RFC
 * Message-ID for an instance without that mail. A declared mail is listed on
 * the object but does not open Mail. Remove deletes the link.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\ExampleSet
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 *
 * @spec openspec/specs/example-sets/spec.md#requirement-a-fragment-may-link-mail-to-its-objects
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\ExampleSet;

use DateTime;
use OCA\OpenRegister\Db\EmailLink;
use OCA\OpenRegister\Db\EmailLinkMapper;
use OCA\OpenRegister\Service\Party\PartyService;
use OCP\AppFramework\Db\DoesNotExistException;
use Psr\Container\ContainerInterface;
use Throwable;

/**
 * Checks, writes, proves and deletes the email links of an example set.
 *
 * @spec openspec/specs/example-sets/spec.md#requirement-a-fragment-may-link-mail-to-its-objects
 */
class ExampleSetEmailLinks extends ExampleSetEngineRows {
	/**
	 * The email link fields a fragment may set.
	 */
	public const FIELDS = [
		'object',
		'messageId',
		'mailAccountId',
		'mailMessageId',
		'subject',
		'sender',
		'senderName',
		'mailDate',
		'linkedBy',
		...EmailLink::DETAIL_KEYS,
	];

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container Hands out the link mapper and the object lookup on first use.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ContainerInterface $container,
	) {
	}//end __construct()

	/**
	 * The fragment and record part email links live in.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-a-fragment-may-link-mail-to-its-objects
	 */
	public function part(): string {
		return 'emailLinks';
	}//end part()

	/**
	 * The word for one email link, in messages.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-a-fragment-may-link-mail-to-its-objects
	 */
	public function noun(): string {
		return 'email link';
	}//end noun()

	/**
	 * Why a filled email link cannot be written, or ''.
	 *
	 * @param array<string, mixed> $data  The filled fields.
	 * @param array<int, string>   $users The account ids the set creates or adopts.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-a-fragment-may-link-mail-to-its-objects
	 */
	public function refusal(array $data, array $users): string {
		$unknown = array_diff(array_keys($data), self::FIELDS);
		if ($unknown !== []) {
			return 'field "'.implode('", "', $unknown).'" is not an email link field a set may write';
		}

		if ($this->filled(value: ($data['object'] ?? null)) === false) {
			return 'an email link needs the object it is on (object)';
		}

		if ($this->filled(value: ($data['subject'] ?? null)) === false) {
			return 'an email link needs a subject';
		}

		$mail = $this->mailRefusal(data: $data, users: $users);
		if ($mail !== '') {
			return $mail;
		}

		return $this->detailRefusal(data: $data);
	}//end refusal()

	/**
	 * Why the link's details cannot be written, or ''.
	 *
	 * @param array<string, mixed> $data The filled fields.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/email-link-sender-name-and-details/spec.md#requirement-an-email-link-carries-the-details-the-row-shows
	 */
	private function detailRefusal(array $data): string {
		if (isset($data['direction']) === true && in_array($data['direction'], ['inbound', 'outbound'], true) === false) {
			return 'direction is inbound or outbound';
		}

		if (isset($data['linkedVia']) === true && in_array($data['linkedVia'], ['manual', 'mailbox', 'file'], true) === false) {
			return 'linkedVia is manual, mailbox or file';
		}

		if (isset($data['attachmentCount']) === true && (is_int($data['attachmentCount']) === false || $data['attachmentCount'] < 0)) {
			return 'attachmentCount is a whole number of 0 or more';
		}

		return '';
	}//end detailRefusal()

	/**
	 * Why the mail, its date or its linker cannot be written, or ''.
	 *
	 * @param array<string, mixed> $data  The filled fields.
	 * @param array<int, string>   $users The account ids the set creates or adopts.
	 *
	 * @return string
	 */
	private function mailRefusal(array $data, array $users): string {
		if ($this->filled(value: ($data['messageId'] ?? null)) === false && $this->mailIds(data: $data) === null) {
			return 'an email link needs the mail: a messageId, or mailAccountId and mailMessageId';
		}

		if (isset($data['linkedBy']) === true && in_array((string) $data['linkedBy'], $users, true) === false) {
			return 'linkedBy "'.(string) $data['linkedBy'].'" is not an account the set creates or adopts';
		}

		if (isset($data['mailDate']) === true && $this->moment(value: $data['mailDate']) === null) {
			return 'mailDate is a date and time';
		}

		return '';
	}//end mailRefusal()

	/**
	 * Link the mail to the object, on the object's register and schema.
	 *
	 * @param string               $app  The app that ships the fragment, the linker when the row names none.
	 * @param string               $key  The link's key in the fragment, kept as its source.
	 * @param array<string, mixed> $data The filled fields.
	 *
	 * @return array{error: string, entry: array{id: string}|null} The record entry, or why the write failed.
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-a-fragment-may-link-mail-to-its-objects
	 */
	public function write(string $app, string $key, array $data): array {
		$object = $this->container->get(PartyService::class)->findUnscoped(uuid: (string) $data['object']);
		if ($object === null) {
			return ['error' => 'no object "'.(string) $data['object'].'" to link the mail to', 'entry' => null];
		}

		$ids = ($this->mailIds(data: $data) ?? [0, 0]);

		$link = new EmailLink();
		$link->setObjectUuid((string) $data['object']);
		$link->setRegisterId((int) $object->getRegister());
		$link->setSchemaId((int) $object->getSchema());
		$link->setMailAccountId($ids[0]);
		$link->setMailMessageId($ids[1]);
		$link->setMailMessageUid($this->stringOrNull(value: ($data['messageId'] ?? null)));
		$link->setSubject((string) $data['subject']);
		$link->setSender($this->stringOrNull(value: ($data['sender'] ?? null)));
		$link->setSenderName($this->stringOrNull(value: ($data['senderName'] ?? null)));
		$link->setMailDate($this->moment(value: ($data['mailDate'] ?? null)));
		$metadata = ['source' => 'example-set:'.$app.'/'.$key];
		foreach (EmailLink::DETAIL_KEYS as $detail) {
			if (isset($data[$detail]) === true) {
				$metadata[$detail] = $data[$detail];
			}
		}

		$link->setMetadata((string) json_encode($metadata));
		$link->setLinkedBy((string) ($data['linkedBy'] ?? $app));
		$link->setLinkedAt(new DateTime());

		try {
			$saved = $this->mapper()->insert($link);
		} catch (Throwable $e) {
			return ['error' => $e->getMessage(), 'entry' => null];
		}

		return ['error' => '', 'entry' => ['id' => (string) $saved->getId()]];
	}//end write()

	/**
	 * Whether a written link is no longer there.
	 *
	 * @param array<string, mixed> $entry A record entry from write().
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-a-fragment-may-link-mail-to-its-objects
	 */
	public function lost(array $entry): bool {
		try {
			$this->mapper()->find((int) $entry['id']);
		} catch (Throwable) {
			return true;
		}

		return false;
	}//end lost()

	/**
	 * Delete a link the set wrote.
	 *
	 * @param array<string, mixed> $entry A record entry from write().
	 *
	 * @return string `removed` (also when it was already gone) or `failed`.
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-a-fragment-may-link-mail-to-its-objects
	 */
	public function revert(array $entry): string {
		try {
			$mapper = $this->mapper();
			$mapper->delete($mapper->find((int) $entry['id']));
		} catch (DoesNotExistException) {
			return 'removed';
		} catch (Throwable) {
			return 'failed';
		}

		return 'removed';
	}//end revert()

	/**
	 * The real Mail message's account and message ids, or null when the row gives none.
	 *
	 * @param array<string, mixed> $data The filled fields.
	 *
	 * @return array{0: int, 1: int}|null
	 */
	private function mailIds(array $data): ?array {
		$account = (int) ($data['mailAccountId'] ?? 0);
		$message = (int) ($data['mailMessageId'] ?? 0);
		if ($account <= 0 || $message <= 0) {
			return null;
		}

		return [$account, $message];
	}//end mailIds()

	/**
	 * Whether a value is a non-empty string.
	 *
	 * @param mixed $value The filled value.
	 *
	 * @return bool
	 */
	private function filled(mixed $value): bool {
		return is_string($value) === true && trim($value) !== '';
	}//end filled()

	/**
	 * A filled string, or null.
	 *
	 * @param mixed $value The filled value.
	 *
	 * @return string|null
	 */
	private function stringOrNull(mixed $value): ?string {
		if ($this->filled(value: $value) === false) {
			return null;
		}

		return (string) $value;
	}//end stringOrNull()

	/**
	 * A date and time as written by `{{datetime:...}}`, or null.
	 *
	 * @param mixed $value The filled value.
	 *
	 * @return DateTime|null
	 */
	private function moment(mixed $value): ?DateTime {
		if ($this->filled(value: $value) === false) {
			return null;
		}

		try {
			return new DateTime((string) $value);
		} catch (Throwable) {
			return null;
		}
	}//end moment()

	/**
	 * The email link mapper.
	 *
	 * @return EmailLinkMapper
	 */
	private function mapper(): EmailLinkMapper {
		return $this->container->get(EmailLinkMapper::class);
	}//end mapper()
}//end class
