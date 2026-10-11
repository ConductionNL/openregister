<?php

/**
 * The `email` recipient kind: an address read through a reference.
 *
 * A rule may address a person who has no Nextcloud account and is no party
 * on the object, but whose address sits on an object the triggering object
 * points at: a supplier message names its supplier, and the supplier holds
 * the contact address. `{kind: email, field: supplierRef.contactEmail}`
 * follows `supplierRef` to the supplier and mails its `contactEmail`.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Notification
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/external-recipient-opt-out/spec.md#requirement-an-email-recipient-reads-its-address-through-a-reference-req-ero-007
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Notification;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use Psr\Log\LoggerInterface;

/**
 * Resolves and mails the addresses an `email` recipient entry names.
 *
 * The referenced object is read as the system, not as the person whose save
 * fired the rule: a melder may not be allowed to read the supplier, and the
 * rule still has to reach it. The read is bounded to the triggering object's
 * own organisation instead, so a reference can never pull an address out of
 * another tenant.
 *
 * @spec openspec/specs/external-recipient-opt-out/spec.md#requirement-an-email-recipient-reads-its-address-through-a-reference-req-ero-007
 */
class ReferencedAddressNotifier {

	/**
	 * The recipient kind this unit sends.
	 */
	public const KIND = 'email';

	/**
	 * The most references one path may follow.
	 */
	public const MAX_HOPS = 3;

	/**
	 * The path names no value, or an empty one.
	 */
	public const REASON_NO_REFERENCE = 'no-reference';

	/**
	 * The reference points at an object that does not exist (or cannot be read).
	 */
	public const REASON_REFERENCE_NOT_FOUND = 'reference-not-found';

	/**
	 * The referenced object belongs to another organisation.
	 */
	public const REASON_OTHER_TENANT = 'refused-other-tenant';

	/**
	 * The value at the end of the path is not an e-mail address.
	 */
	public const REASON_NOT_AN_ADDRESS = 'not-an-address';

	/**
	 * integriq says the address opted out.
	 */
	public const OUTCOME_REFUSED_OPTED_OUT = 'refused-opted-out';

	/**
	 * integriq did not answer, which is refused like an opt-out.
	 */
	public const OUTCOME_AUTHORITY_UNAVAILABLE = OptOutAuthority::CODE_AUTHORITY_UNAVAILABLE;

	/**
	 * Constructor.
	 *
	 * @param ObjectService   $objects The object layer, for the referenced object.
	 * @param EmailSender     $email   The email channel.
	 * @param OptOutAuthority $optOut  Asks integriq whether an address may be mailed.
	 * @param LoggerInterface $logger  Logger.
	 */
	public function __construct(
		private readonly ObjectService $objects,
		private readonly EmailSender $email,
		private readonly OptOutAuthority $optOut,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Mail every `email` entry of a rule that resolves to an address.
	 *
	 * Every resolved address is put to integriq in one question. An entry that
	 * does not resolve, or that integriq refuses, is reported with its reason,
	 * never dropped in silence.
	 *
	 * @param ObjectEntity         $object         The triggering object.
	 * @param array<string, mixed> $data           Its data.
	 * @param array<int, mixed>    $recipientsSpec The rule's `recipients` declaration.
	 * @param string               $subject        The rule's subject.
	 * @param string               $body           The rule's message.
	 * @param string               $category       The rule's message category.
	 *
	 * @return array<int, array{field: string, outcome: string}> One outcome per `email` entry.
	 *
	 * @spec openspec/specs/external-recipient-opt-out/spec.md#requirement-an-email-recipient-reads-its-address-through-a-reference-req-ero-007
	 */
	public function notify(
		ObjectEntity $object,
		array $data,
		array $recipientsSpec,
		string $subject,
		string $body,
		string $category,
	): array {
		$entries = [];
		foreach ($recipientsSpec as $recipient) {
			if (is_array($recipient) === false || (string)($recipient['kind'] ?? '') !== self::KIND) {
				continue;
			}

			$path = trim((string)($recipient['field'] ?? ''));
			$entries[] = ['field' => $path] + $this->resolveAddress(object: $object, data: $data, path: $path);
		}

		if ($entries === []) {
			return [];
		}

		$askable = [];
		foreach ($entries as $entry) {
			if ($entry['address'] !== null) {
				$askable[] = $entry['address'];
			}
		}

		$decisions = $this->optOut->ask(
			channel: 'email',
			category: $category,
			addresses: $askable,
			correlationId: 'openregister-email:' . (string)($object->getUuid() ?? '')
		);

		$outcomes = [];
		foreach ($entries as $entry) {
			$outcomes[] = [
				'field' => $entry['field'],
				'outcome' => $this->sendOne(entry: $entry, decisions: $decisions, subject: $subject, body: $body),
			];
		}

		return $outcomes;
	}//end notify()

	/**
	 * The address a path names, or the reason it names none.
	 *
	 * Each segment but the last is read from the current data; when its value
	 * is a reference (a uuid string, or an object carrying `id` or `uuid`) the
	 * referenced object's data becomes the current data. The last segment is
	 * the address.
	 *
	 * @param ObjectEntity         $object The triggering object.
	 * @param array<string, mixed> $data   Its data.
	 * @param string               $path   The dotted path, e.g. `supplierRef.contactEmail`.
	 *
	 * @return array{address: string|null, reason: string|null}
	 *
	 * @spec openspec/specs/external-recipient-opt-out/spec.md#requirement-an-email-recipient-reads-its-address-through-a-reference-req-ero-007
	 */
	public function resolveAddress(ObjectEntity $object, array $data, string $path): array {
		$segments = array_values(array_filter(explode('.', $path), static fn (string $segment): bool => $segment !== ''));
		if ($segments === [] || count($segments) > (self::MAX_HOPS + 1)) {
			return ['address' => null, 'reason' => self::REASON_NO_REFERENCE];
		}

		$last = array_pop($segments);
		$followed = $this->follow(object: $object, data: $data, segments: $segments);
		if ($followed['reason'] !== null) {
			return ['address' => null, 'reason' => $followed['reason']];
		}

		$value = ($followed['data'][$last] ?? null);
		if (is_string($value) === false || trim($value) === '') {
			return ['address' => null, 'reason' => self::REASON_NO_REFERENCE];
		}

		$address = trim($value);
		if (filter_var($address, FILTER_VALIDATE_EMAIL) === false) {
			return ['address' => null, 'reason' => self::REASON_NOT_AN_ADDRESS];
		}

		return ['address' => $address, 'reason' => null];
	}//end resolveAddress()

	/**
	 * Follow each reference segment to the object it names.
	 *
	 * @param ObjectEntity         $object   The triggering object.
	 * @param array<string, mixed> $data     Its data.
	 * @param array<int, string>   $segments The reference segments, in order.
	 *
	 * @return array{data: array<string, mixed>, reason: string|null} The data reached, or why it was not.
	 */
	private function follow(ObjectEntity $object, array $data, array $segments): array {
		$current = $data;
		foreach ($segments as $segment) {
			$reference = $this->referenceOf(value: ($current[$segment] ?? null));
			if ($reference === null) {
				return ['data' => [], 'reason' => self::REASON_NO_REFERENCE];
			}

			$related = $this->findReferenced(id: $reference);
			if ($related === null) {
				return ['data' => [], 'reason' => self::REASON_REFERENCE_NOT_FOUND];
			}

			if ($this->sameTenant(origin: $object, related: $related) === false) {
				return ['data' => [], 'reason' => self::REASON_OTHER_TENANT];
			}

			$current = $related->getObject();
		}

		return ['data' => $current, 'reason' => null];
	}//end follow()

	/**
	 * Send one resolved entry, or name why it was not sent.
	 *
	 * @param array{field: string, address: string|null, reason: string|null} $entry     The entry.
	 * @param array<string, array<string, mixed>>                              $decisions integriq's answers.
	 * @param string                                                           $subject   The subject.
	 * @param string                                                           $body      The body.
	 *
	 * @return string The outcome.
	 */
	private function sendOne(array $entry, array $decisions, string $subject, string $body): string {
		if ($entry['address'] === null) {
			return (string)$entry['reason'];
		}

		$decision = $this->optOut->decisionFor(decisions: $decisions, address: $entry['address']);
		if (($decision['send'] ?? false) !== true) {
			if (($decision['code'] ?? '') === OptOutAuthority::CODE_AUTHORITY_UNAVAILABLE) {
				return self::OUTCOME_AUTHORITY_UNAVAILABLE;
			}

			return self::OUTCOME_REFUSED_OPTED_OUT;
		}

		$unsubscribe = null;
		if (is_array($decision['unsubscribe'] ?? null) === true) {
			$unsubscribe = $decision['unsubscribe'];
		}

		return $this->email->sendToAddress(
			address: $entry['address'],
			displayName: '',
			subject: $subject,
			body: $this->optOut->withLink(body: $body, unsubscribe: $unsubscribe),
			unsubscribe: $unsubscribe
		);
	}//end sendOne()

	/**
	 * The id a stored reference value names, or null.
	 *
	 * @param mixed $value The stored value.
	 *
	 * @return string|null
	 */
	private function referenceOf(mixed $value): ?string {
		if (is_array($value) === true) {
			$value = ($value['id'] ?? ($value['uuid'] ?? null));
		}

		if (is_string($value) === false || trim($value) === '') {
			return null;
		}

		return trim($value);
	}//end referenceOf()

	/**
	 * Read the referenced object as the system.
	 *
	 * @param string $id The reference.
	 *
	 * @return ObjectEntity|null
	 */
	private function findReferenced(string $id): ?ObjectEntity {
		try {
			return $this->objects->find(id: $id, _rbac: false, _multitenancy: false, _render: false, _audit: false);
		} catch (\Throwable $e) {
			$this->logger->debug(
				sprintf('[ReferencedAddressNotifier] reference "%s" did not resolve: %s', $id, $e->getMessage())
			);
			return null;
		}
	}//end findReferenced()

	/**
	 * Whether the referenced object may be read for the triggering one.
	 *
	 * A referenced object without an organisation is not tenant-bound and may
	 * be read. One with an organisation is read only for an object of that
	 * same organisation; an object without one may not reach into a tenant.
	 *
	 * @param ObjectEntity $origin  The triggering object.
	 * @param ObjectEntity $related The referenced object.
	 *
	 * @return bool
	 */
	private function sameTenant(ObjectEntity $origin, ObjectEntity $related): bool {
		$mine = (string)($origin->getOrganisation() ?? '');
		$theirs = (string)($related->getOrganisation() ?? '');
		if ($theirs === '') {
			return true;
		}

		return $mine === $theirs;
	}//end sameTenant()
}//end class
