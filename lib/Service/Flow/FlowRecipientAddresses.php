<?php

/**
 * Sorts a send step's recipients into users and email addresses, and screens
 * the addresses against the step's `externalRecipients` allowlist.
 *
 * Split out of FlowMessagingService so the service keeps its one job, the
 * guard chain and the send, while the rules for "what counts as an address,
 * and which addresses may be mailed" live in one place. Users resolve
 * through the subsystem's own recipient resolver; this class adds no
 * resolver of its own.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Flow
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/flow-send-email-external-recipients/spec.md#requirement-a-send-email-step-reaches-an-address-only-as-far-as-the-step-allows
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow;

use OCA\OpenRegister\Notifications\Service\Notification\NotificationRecipientResolverInterface;
use OCA\OpenRegister\Notifications\Service\Notification\RecipientSource;

/**
 * Recipient address rules for the flow send nodes.
 *
 * @spec openspec/specs/flow-send-email-external-recipients/spec.md#requirement-a-send-email-step-reaches-an-address-only-as-far-as-the-step-allows
 */
class FlowRecipientAddresses {

	/**
	 * Constructor.
	 *
	 * @param NotificationRecipientResolverInterface $recipientResolver The subsystem's recipient resolver.
	 */
	public function __construct(
		private readonly NotificationRecipientResolverInterface $recipientResolver,
	) {

	}//end __construct()

	/**
	 * Resolve one literal recipient entry.
	 *
	 * A user id wins, then a group id (expanded), then, on the email channel,
	 * anything holding an `@` is a candidate address. Everything else is
	 * unknown. User first, because a Nextcloud uid may itself look like an
	 * address.
	 *
	 * @param string $entry The trimmed entry.
	 * @param bool $acceptAddresses Whether addresses are candidates (email) or unknowns.
	 *
	 * @return array{uids: array<int, string>, addresses: array<int, array{address: string, name: string}>, unknown: array<int, string>}
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) Whether the channel takes addresses is a
	 * fact about the channel, not a mode of this method's own.
	 *
	 * @spec openspec/specs/flow-send-email-external-recipients/spec.md#requirement-a-send-email-step-reaches-an-address-only-as-far-as-the-step-allows
	 */
	public function resolveLiteral(string $entry, bool $acceptAddresses): array {
		$out = ['uids' => [], 'addresses' => [], 'unknown' => []];

		if ($this->recipientResolver->userExists(uid: $entry) === true) {
			$out['uids'][] = $entry;
			return $out;
		}

		if ($this->recipientResolver->groupExists(gid: $entry) === true) {
			$out['uids'] = array_values(
				$this->recipientResolver->resolveFromSource(
					recipientsSpec: [
						[
							'kind' => 'groups',
							'groups' => [$entry],
						],
					],
					source: self::sourceOf(data: [])
				)['uids']
			);
			return $out;
		}

		if ($acceptAddresses === true && str_contains($entry, '@') === true) {
			$out['addresses'][] = ['address' => $entry, 'name' => ''];
			return $out;
		}

		$out['unknown'][] = $entry;
		return $out;
	}//end resolveLiteral()

	/**
	 * Resolve one `{{ field }}` recipient entry against the item.
	 *
	 * The field's value goes through the subsystem's relation reader, every
	 * uid verified; a candidate that is not a user is returned as unknown.
	 * With `$acceptAddresses` (the email channel) its addresses are taken out
	 * first and returned as candidates for the caller to screen.
	 *
	 * @param string          $field  The field name.
	 * @param RecipientLookup $lookup The item's json, and whether addresses are candidates (email) or unknowns.
	 *
	 * @return array{uids: array<int, string>, addresses: array<int, array{address: string, name: string}>, unknown: array<int, string>}
	 *
	 * @spec openspec/specs/flow-send-email-external-recipients/spec.md#requirement-a-send-notification-step-reads-role-fields-on-the-item
	 */
	public function resolveTemplate(string $field, RecipientLookup $lookup): array {
		$value = $this->normaliseRoleValue(value: ($lookup->json[$field] ?? null));
		$addresses = [];
		if ($lookup->acceptAddresses === true) {
			$split = $this->splitAddresses(value: $value);
			$value = $split['rest'];
			$addresses = $split['addresses'];
		}

		$resolved = $this->recipientResolver->resolveFromSource(
			recipientsSpec: [
				[
					'kind' => 'relation',
					'relation' => $field,
				],
			],
			source: self::sourceOf(data: [$field => $value])
		)['uids'];
		$candidates = $this->recipientResolver->extractUidsFromRelation(value: $value);

		return [
			'uids' => array_values($resolved),
			'addresses' => $addresses,
			'unknown' => array_values(array_diff($candidates, $resolved)),
		];
	}//end resolveTemplate()

	/**
	 * The step's `externalRecipients` mode, defaulting to closed.
	 *
	 * @param array $config The step configuration.
	 *
	 * @return string One of the EXTERNAL_* modes.
	 *
	 * @spec openspec/specs/flow-send-email-external-recipients/spec.md#requirement-a-send-email-step-reaches-an-address-only-as-far-as-the-step-allows
	 */
	public function externalRecipientMode(array $config): string {
		$mode = strtolower(trim((string)($config['externalRecipients'] ?? '')));
		if (in_array($mode, FlowMessagingService::EXTERNAL_RECIPIENT_MODES, true) === true) {
			return $mode;
		}

		// An unrecognised value is refused at save time by the node; a stored
		// one that slipped past falls back to the closed mode, never open.
		return FlowMessagingService::EXTERNAL_NONE;
	}//end externalRecipientMode()

	/**
	 * Apply the step's allowlist to one item's candidate addresses.
	 *
	 * @param array<int, array{address: string, name: string}> $addresses The candidates.
	 * @param array $json The item's json.
	 * @param string $mode The allowlist mode.
	 *
	 * @return array{allowed: array<string, string>, refused: array<string, array{recipient: string, reason: string}>}
	 *
	 * @spec openspec/specs/flow-send-email-external-recipients/spec.md#requirement-a-send-email-step-reaches-an-address-only-as-far-as-the-step-allows
	 */
	public function screenAddresses(array $addresses, array $json, string $mode): array {
		return self::screenUnder(addresses: $addresses, policy: $this->policyFor(json: $json, mode: $mode));
	}//end screenAddresses()

	/**
	 * The allowlist one item's candidate addresses are screened under.
	 *
	 * @param array  $json The item's json.
	 * @param string $mode The allowlist mode.
	 *
	 * @return ExternalRecipientPolicy The mode, and the item's addresses when the mode reads them.
	 *
	 * @spec openspec/specs/flow-send-email-external-recipients/spec.md#requirement-a-send-email-step-reaches-an-address-only-as-far-as-the-step-allows
	 */
	public function policyFor(array $json, string $mode): ExternalRecipientPolicy {
		// Only the object mode reads the item; collecting its addresses is a
		// pure read, so doing it once up front changes no outcome.
		$onItem = [];
		if ($mode === FlowMessagingService::EXTERNAL_OBJECT) {
			$onItem = $this->addressesOnItem(value: $json);
		}

		return new ExternalRecipientPolicy(mode: $mode, onItem: $onItem);
	}//end policyFor()

	/**
	 * Split the candidates into the allowed and the refused under one policy.
	 *
	 * @param array<int, array{address: string, name: string}> $addresses The candidates.
	 * @param ExternalRecipientPolicy                          $policy    The mode, and the item's addresses.
	 *
	 * @return array{allowed: array<string, string>, refused: array<string, array{recipient: string, reason: string}>}
	 *
	 * @spec openspec/specs/flow-send-email-external-recipients/spec.md#requirement-a-send-email-step-reaches-an-address-only-as-far-as-the-step-allows
	 */
	private static function screenUnder(array $addresses, ExternalRecipientPolicy $policy): array {
		$allowed = [];
		$refused = [];
		foreach ($addresses as $candidate) {
			$address = trim($candidate['address']);
			$key = strtolower($address);
			$reason = $policy->refusalFor(address: $address);
			if ($reason !== '') {
				$refused[$key . '|' . $reason] = ['recipient' => $address, 'reason' => $reason];
			} else if (isset($allowed[$key]) === false) {
				// Keyed case-insensitively, so one person is mailed once per item
				// however many fields spell their address.
				$allowed[$key] = $candidate['name'];
			}
		}

		return ['allowed' => $allowed, 'refused' => $refused];
	}//end screenUnder()

	/**
	 * Every string on the item that could be an address, normalised.
	 *
	 * @param mixed $value The item's json, or a value inside it.
	 *
	 * @return array<string, true> The normalised strings holding an `@`.
	 */
	private function addressesOnItem(mixed $value): array {
		if (is_string($value) === true) {
			$value = strtolower(trim($value));
			if (str_contains($value, '@') === true) {
				return [$value => true];
			}

			return [];
		}

		if (is_array($value) === false) {
			return [];
		}

		$found = [];
		foreach ($value as $inner) {
			$found += $this->addressesOnItem(value: $inner);
		}

		return $found;
	}//end addressesOnItem()

	/**
	 * Wrap a single role object in a list.
	 *
	 * The relation reader walks a list; handed one object it would walk the
	 * object's VALUES and read a display name as a uid. A field holding one
	 * `{ "uid": ..., "displayName": ... }` is a list of one.
	 *
	 * @param mixed $value The field's value.
	 *
	 * @return mixed The value, a single role object wrapped.
	 *
	 * @spec openspec/specs/flow-send-email-external-recipients/spec.md#requirement-a-send-notification-step-reads-role-fields-on-the-item
	 */
	private function normaliseRoleValue(mixed $value): mixed {
		if ($this->isRecipientMap(value: $value) === true) {
			return [$value];
		}

		return $value;
	}//end normaliseRoleValue()

	/**
	 * Whether a value is one recipient written as a map rather than a list of them.
	 *
	 * @param mixed $value The field value.
	 *
	 * @return bool True for a non-empty map carrying a user or address key.
	 *
	 * @spec openspec/specs/flow-send-email-external-recipients/spec.md#requirement-a-send-email-step-reaches-an-address-only-as-far-as-the-step-allows
	 */
	private function isRecipientMap(mixed $value): bool {
		if (is_array($value) === false || $value === [] || array_is_list($value) === true) {
			return false;
		}

		return array_intersect_key($value, array_flip(['userId', 'uid', 'user_id', 'email', 'emailAddress'])) !== [];
	}//end isRecipientMap()

	/**
	 * Take the addresses out of a field's value, leaving the user entries.
	 *
	 * A string is an address when it holds an `@` and names no user (a uid
	 * may itself look like an address, and a user wins). An object is a
	 * user when it carries `uid` / `userId` / `user_id`, otherwise an address
	 * when it carries `email` / `emailAddress`, the convention the party
	 * model reads.
	 *
	 * @param mixed $value The field's value.
	 *
	 * @return array{rest: mixed, addresses: array<int, array{address: string, name: string}>}
	 *
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity) Two entry shapes, each with a user and an address branch.
	 *
	 * @spec openspec/specs/flow-send-email-external-recipients/spec.md#requirement-a-send-email-step-reaches-an-address-only-as-far-as-the-step-allows
	 */
	private function splitAddresses(mixed $value): array {
		if (is_string($value) === true) {
			$value = [$value];
		}

		if (is_array($value) === false) {
			return ['rest' => $value, 'addresses' => []];
		}

		return $this->partitionEntries(entries: $value);
	}//end splitAddresses()

	/**
	 * Partition a list of recipient entries into external addresses and the rest.
	 *
	 * @param array<int|string, mixed> $entries The entries.
	 *
	 * @return array{rest: array<int, mixed>, addresses: array<int, array{address: string, name: string}>}
	 *
	 * @spec openspec/specs/flow-send-email-external-recipients/spec.md#requirement-a-send-email-step-reaches-an-address-only-as-far-as-the-step-allows
	 */
	private function partitionEntries(array $entries): array {
		$rest = [];
		$addresses = [];
		foreach ($entries as $entry) {
			if (is_string($entry) === true) {
				$entry = trim($entry);
			}

			$address = $this->externalAddressOf(entry: $entry);
			if ($address !== null) {
				$addresses[] = $address;
				continue;
			}

			$rest[] = $entry;
		}

		return ['rest' => $rest, 'addresses' => $addresses];
	}//end partitionEntries()

	/**
	 * The external address one entry names, or null when it is a user or nothing.
	 *
	 * @param mixed $entry The (trimmed, when a string) entry.
	 *
	 * @return array{address: string, name: string}|null The address.
	 *
	 * @spec openspec/specs/flow-send-email-external-recipients/spec.md#requirement-a-send-email-step-reaches-an-address-only-as-far-as-the-step-allows
	 */
	private function externalAddressOf(mixed $entry): ?array {
		if (is_string($entry) === true) {
			return $this->bareAddressOf(entry: $entry);
		}

		if (is_array($entry) === false) {
			return null;
		}

		return $this->mappedAddressOf(entry: $entry);
	}//end externalAddressOf()

	/**
	 * A bare string that contains `@` and is not a user id is an external address.
	 *
	 * @param string $entry The trimmed entry.
	 *
	 * @return array{address: string, name: string}|null The address.
	 *
	 * @spec openspec/specs/flow-send-email-external-recipients/spec.md#requirement-a-send-email-step-reaches-an-address-only-as-far-as-the-step-allows
	 */
	private function bareAddressOf(string $entry): ?array {
		if (str_contains($entry, '@') === true && $this->recipientResolver->userExists(uid: $entry) === false) {
			return ['address' => $entry, 'name' => ''];
		}

		return null;
	}//end bareAddressOf()

	/**
	 * The address a map entry names, unless the map names a user.
	 *
	 * @param array<string, mixed> $entry The entry.
	 *
	 * @return array{address: string, name: string}|null The address.
	 *
	 * @spec openspec/specs/flow-send-email-external-recipients/spec.md#requirement-a-send-email-step-reaches-an-address-only-as-far-as-the-step-allows
	 */
	private function mappedAddressOf(array $entry): ?array {
		if (FlowScalarText::orNull(value: ($entry['userId'] ?? $entry['uid'] ?? $entry['user_id'] ?? null)) !== null) {
			return null;
		}

		$address = FlowScalarText::orNull(value: ($entry['email'] ?? $entry['emailAddress'] ?? null));
		if ($address === null) {
			return null;
		}

		return [
			'address' => $address,
			'name' => (string)(FlowScalarText::orNull(value: ($entry['name'] ?? $entry['displayName'] ?? null)) ?? ''),
		];
	}//end mappedAddressOf()

	/**
	 * Resolve a node's recipients config against one item.
	 *
	 * Entries are literal user or group ids, or a template resolving a field
	 * on the item (`{{ assignee }}` / `{{ item.assignee }}`). Resolution goes
	 * through the subsystem's recipient resolver — groups expanded, every uid
	 * verified — and unknown ids are returned for the run log rather than
	 * silently dropped.
	 *
	 * With `$acceptAddresses` (the email channel), an entry that is not a
	 * user or group but holds an `@` is a candidate address, and so is a
	 * field value that is one, or an object carrying `email` /
	 * `emailAddress`. Candidates are screened by the caller; this method only
	 * sorts them out.
	 *
	 * @param mixed $recipients The config value.
	 * @param array $json The item's json.
	 * @param bool $acceptAddresses Whether addresses are candidates (email) or unknowns.
	 *
	 * @return array{uids: array<int, string>, addresses: array<int, array{address: string, name: string}>, unknown: array<int, string>}
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) Whether the channel takes addresses is a
	 * fact about the channel, not a mode of this method's own.
	 *
	 * @spec openspec/specs/flow-send-email-external-recipients/spec.md#requirement-a-send-email-step-reaches-an-address-only-as-far-as-the-step-allows
	 */
	public function resolveRecipients(mixed $recipients, array $json, bool $acceptAddresses = false): array {
		return $this->resolveAll(recipients: $recipients, lookup: new RecipientLookup(json: $json, acceptAddresses: $acceptAddresses));
	}//end resolveRecipients()

	/**
	 * Resolve every recipient entry against one item, de-duplicated.
	 *
	 * @param mixed           $recipients The config value: one entry or a list.
	 * @param RecipientLookup $lookup     The item's json, and whether addresses are candidates.
	 *
	 * @return array{uids: array<int, string>, addresses: array<int, array{address: string, name: string}>, unknown: array<int, string>}
	 *
	 * @spec openspec/specs/flow-send-email-external-recipients/spec.md#requirement-a-send-email-step-reaches-an-address-only-as-far-as-the-step-allows
	 */
	public function resolveAll(mixed $recipients, RecipientLookup $lookup): array {
		$uids = [];
		$addresses = [];
		$unknown = [];
		if (is_string($recipients) === true) {
			$recipients = [$recipients];
		}

		foreach ((array)$recipients as $entry) {
			$resolved = $this->resolveEntry(entry: $entry, lookup: $lookup);
			array_push($uids, ...($resolved['uids'] ?? []));
			array_push($addresses, ...($resolved['addresses'] ?? []));
			array_push($unknown, ...($resolved['unknown'] ?? []));
		}

		return ['uids' => array_values(array_unique($uids)), 'addresses' => $addresses, 'unknown' => array_values(array_unique($unknown))];
	}//end resolveAll()

	/**
	 * Resolve one recipient entry: a `{{ field }}` template or a literal.
	 *
	 * @param mixed           $entry  The entry.
	 * @param RecipientLookup $lookup The item's json, and whether addresses are candidates.
	 *
	 * @return array{uids: array<int, string>, addresses: array<int, array{address: string, name: string}>, unknown: array<int, string>}|null
	 *
	 * @spec openspec/specs/flow-send-email-external-recipients/spec.md#requirement-a-send-email-step-reaches-an-address-only-as-far-as-the-step-allows
	 */
	private function resolveEntry(mixed $entry, RecipientLookup $lookup): ?array {
		if (is_string($entry) === false || trim($entry) === '') {
			return null;
		}

		$entry = trim($entry);

		$matches = [];
		if (preg_match('/^\{\{\s*(?:item\.)?([a-zA-Z0-9_.-]+)\s*\}\}$/', $entry, $matches) === 1) {
			return $this->resolveTemplate(field: $matches[1], lookup: $lookup);
		}

		return $this->resolveLiteral(entry: $entry, acceptAddresses: $lookup->acceptAddresses);
	}//end resolveEntry()

	/**
	 * A recipient source carrying only data: no object, no trigger context, no role assignment.
	 *
	 * @param array<string, mixed> $data The data the spec reads.
	 *
	 * @return RecipientSource The source.
	 */
	private static function sourceOf(array $data): RecipientSource {
		return new RecipientSource(data: $data, object: null, context: [], roleGroups: []);
	}//end sourceOf()
}//end class
