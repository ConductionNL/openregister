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
use OCA\OpenRegister\Db\EmailLinkMapperInterface;
use OCA\OpenRegister\Db\ObjectEntity;
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
	 * The details a link may set to one of a few words, with those words and the refusal for any other, in the order
	 * they are checked.
	 */
	private const DETAIL_CHOICES = [
		'direction' => [['inbound', 'outbound'], 'direction is inbound or outbound'],
		'linkedVia' => [['manual', 'mailbox', 'file'], 'linkedVia is manual, mailbox or file'],
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
		$rules = new ExampleSetFieldRules();
		return $rules->firstRefusal(
			checks: [
				fn (): string => $rules->unknownRefusal(data: $data, fields: self::FIELDS, row: 'an email link'),
				fn (): string => $this->neededRefusal(data: $data),
				fn (): string => $this->mailRefusal(data: $data, users: $users),
				fn (): string => $this->detailRefusal(data: $data),
			]
		);
	}//end refusal()

	/**
	 * Why a link lacks the object it is on or a subject, or ''.
	 *
	 * @param array<string, mixed> $data The filled link fields.
	 *
	 * @return string
	 */
	private function neededRefusal(array $data): string {
		if ($this->filled(value: ($data['object'] ?? null)) === false) {
			return 'an email link needs the object it is on (object)';
		}

		if ($this->filled(value: ($data['subject'] ?? null)) === false) {
			return 'an email link needs a subject';
		}

		return '';
	}//end neededRefusal()

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
		foreach (self::DETAIL_CHOICES as $field => [$choices, $refusal]) {
			if (isset($data[$field]) === true && in_array($data[$field], $choices, true) === false) {
				return $refusal;
			}
		}

		return $this->attachmentCountRefusal(data: $data);
	}//end detailRefusal()

	/**
	 * Why a link's attachment count is not a whole number of 0 or more, or ''; a link without one is fine.
	 *
	 * @param array<string, mixed> $data The filled link fields.
	 *
	 * @return string
	 */
	private function attachmentCountRefusal(array $data): string {
		if (isset($data['attachmentCount']) === true && (is_int($data['attachmentCount']) === false || $data['attachmentCount'] < 0)) {
			return 'attachmentCount is a whole number of 0 or more';
		}

		return '';
	}//end attachmentCountRefusal()

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

		return $this->mailDateRefusal(data: $data);
	}//end mailRefusal()

	/**
	 * Why a link's mail date is not a date and time, or ''; a link without one is fine.
	 *
	 * @param array<string, mixed> $data The filled link fields.
	 *
	 * @return string
	 */
	private function mailDateRefusal(array $data): string {
		if (isset($data['mailDate']) === true && $this->moment(value: $data['mailDate']) === null) {
			return 'mailDate is a date and time';
		}

		return '';
	}//end mailDateRefusal()

	/**
	 * Link the mail to the object, on the object's register and schema.
	 *
	 * @param ExampleSetRow $row The row: its app, its key in the fragment and its filled fields.
	 *
	 * @return array{error: string, entry: array{id: string}|null} The record entry, or why the write failed.
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-a-fragment-may-link-mail-to-its-objects
	 */
	public function write(ExampleSetRow $row): array {
		$app = $row->app;
		$key = $row->key;
		$data = $row->data;
		$object = $this->container->get(PartyService::class)->findUnscoped(uuid: (string) $data['object']);
		if ($object === null) {
			return ['error' => 'no object "'.(string) $data['object'].'" to link the mail to', 'entry' => null];
		}

		$link = $this->newLink(app: $app, key: $key, object: $object, data: $data);
		try {
			$saved = $this->mapper()->insert($link);
		} catch (Throwable $e) {
			return ['error' => $e->getMessage(), 'entry' => null];
		}

		return ['error' => '', 'entry' => ['id' => (string) $saved->getId()]];
	}//end write()

	/**
	 * The link of the mail to the object, as the set writes it.
	 *
	 * @param string               $app    The app that ships the fragment, the linker unless linkedBy names one.
	 * @param string               $key    The link's key in the fragment.
	 * @param ObjectEntity         $object The object.
	 * @param array<string, mixed> $data   The filled link fields.
	 *
	 * @return EmailLink
	 */
	private function newLink(string $app, string $key, ObjectEntity $object, array $data): EmailLink {
		$link = new EmailLink();
		$link->setObjectUuid((string) $data['object']);
		$link->setRegisterId((int) $object->getRegister());
		$link->setSchemaId((int) $object->getSchema());
		$this->setMail(link: $link, data: $data);
		$link->setMetadata((string) json_encode($this->metadata(source: 'example-set:'.$app.'/'.$key, data: $data)));
		$link->setLinkedBy((string) ($data['linkedBy'] ?? $app));
		$link->setLinkedAt(new DateTime());

		return $link;
	}//end newLink()

	/**
	 * Put the mail the link names on it: its ids, subject, sender and date.
	 *
	 * @param EmailLink            $link The link.
	 * @param array<string, mixed> $data The filled link fields.
	 *
	 * @return void
	 */
	private function setMail(EmailLink $link, array $data): void {
		$ids = ($this->mailIds(data: $data) ?? [0, 0]);
		$link->setMailAccountId($ids[0]);
		$link->setMailMessageId($ids[1]);
		$link->setMailMessageUid($this->stringOrNull(value: ($data['messageId'] ?? null)));
		$link->setSubject((string) $data['subject']);
		$link->setSender($this->stringOrNull(value: ($data['sender'] ?? null)));
		$link->setSenderName($this->stringOrNull(value: ($data['senderName'] ?? null)));
		$link->setMailDate($this->moment(value: ($data['mailDate'] ?? null)));
	}//end setMail()

	/**
	 * The link's metadata: where it came from, and the details the row sets.
	 *
	 * @param string               $source The fragment row the link came from.
	 * @param array<string, mixed> $data   The filled link fields.
	 *
	 * @return array<string, mixed>
	 */
	private function metadata(string $source, array $data): array {
		$metadata = ['source' => $source];
		foreach (EmailLink::DETAIL_KEYS as $detail) {
			if (isset($data[$detail]) === true) {
				$metadata[$detail] = $data[$detail];
			}
		}

		return $metadata;
	}//end metadata()

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
	 * @return EmailLinkMapperInterface
	 */
	private function mapper(): EmailLinkMapperInterface {
		return $this->container->get(EmailLinkMapperInterface::class);
	}//end mapper()
}//end class
