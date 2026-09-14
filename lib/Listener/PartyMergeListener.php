<?php

/**
 * Two party records that turn out to be one person, merged.
 *
 * `mdm-merge` already has the preview, the atomic execution, the reversal
 * window and the merge register, and it is entity-type-agnostic by
 * requirement. Writing a party merge beside it would be two merges that
 * disagree about what happened. So this listener adds only the party
 * vocabulary: the roles both parties held carry over onto the survivor,
 * their addresses union, and the reversal puts both back.
 *
 * Every carried-over row records the operation that moved it, so the
 * reversal is a read of the rows themselves rather than surgery on the
 * merge snapshot.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Listener
 * @package  OCA\OpenRegister\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/party-roles-beyond-the-requester/specs/mdm-merge/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Listener;

use OCA\OpenRegister\Db\ContactLink;
use OCA\OpenRegister\Db\ContactLinkMapper;
use OCA\OpenRegister\Event\ObjectsMergedEvent;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\Party\PartyDefinition;
use OCA\OpenRegister\Service\Party\PartyService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Carries party roles and addresses across a merge, and back on a reversal.
 *
 * @template-implements IEventListener<ObjectsMergedEvent>
 *
 * @spec openspec/changes/party-roles-beyond-the-requester/specs/mdm-merge/spec.md#requirement-parties-merge-through-the-existing-merge-primitive-req-prm-005
 */
class PartyMergeListener implements IEventListener {

	/**
	 * The metadata key recording which merge moved a link.
	 */
	public const MEMO_KEY = 'partyMerge';

	/**
	 * Constructor.
	 *
	 * @param ContactLinkMapper $links The link rows.
	 * @param PartyService $parties The party records.
	 * @param ObjectService $objects The object layer, for the address union.
	 * @param LoggerInterface $logger Diagnostics; a party that will not carry over never fails the merge.
	 */
	public function __construct(
		private readonly ContactLinkMapper $links,
		private readonly PartyService $parties,
		private readonly ObjectService $objects,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * React to a merge or its reversal.
	 *
	 * @param Event $event The event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/party-roles-beyond-the-requester/specs/mdm-merge/spec.md#requirement-parties-merge-through-the-existing-merge-primitive-req-prm-005
	 */
	public function handle(Event $event): void {
		if ($event instanceof ObjectsMergedEvent === false) {
			return;
		}

		try {
			if ($event->isReversal() === true) {
				$this->reverse(operationId: $event->getMergeOperationId());
				return;
			}

			$this->carryOver(
				survivorUuid: $event->getSurvivorUuid(),
				mergedFromUuids: $event->getMergedFromUuids(),
				operationId: $event->getMergeOperationId()
			);
		} catch (Throwable $e) {
			// The merge itself is already committed and reversible. A party
			// vocabulary that cannot be applied is a defect to read about, not
			// a reason to leave the merge half-done.
			$this->logger->error(
				'[PartyMergeListener] party vocabulary failed for merge "'
				. $event->getMergeOperationId() . '": ' . $e->getMessage()
			);
		}//end try
	}//end handle()

	/**
	 * Move every role the merged-away parties held onto the survivor.
	 *
	 * @param string $survivorUuid The surviving party.
	 * @param array<int, string> $mergedFromUuids The parties merged away.
	 * @param string $operationId The merge operation.
	 *
	 * @return void
	 */
	private function carryOver(string $survivorUuid, array $mergedFromUuids, string $operationId): void {
		$survivor = $this->parties->find(partyUuid: $survivorUuid);
		if ($survivor === null) {
			return;
		}

		$definition = $this->parties->definitionFor(party: $survivor);
		if ($definition === null) {
			return;
		}

		foreach ($mergedFromUuids as $fromUuid) {
			$this->carryOverOne(
				survivorUuid: $survivorUuid,
				fromUuid: $fromUuid,
				operationId: $operationId,
				definition: $definition
			);
		}
	}//end carryOver()

	/**
	 * Move one merged-away party's roles and addresses onto the survivor.
	 *
	 * @param string $survivorUuid The surviving party.
	 * @param string $fromUuid The party merged away.
	 * @param string $operationId The merge operation.
	 * @param PartyDefinition $definition The survivor's party declaration.
	 *
	 * @return void
	 */
	private function carryOverOne(
		string $survivorUuid,
		string $fromUuid,
		string $operationId,
		PartyDefinition $definition,
	): void {
		foreach ($this->links->findByPartyUuid(partyUuid: $fromUuid) as $link) {
			$objectUuid = (string)($link->getObjectUuid() ?? '');
			$held = $this->links->findByObjectContactAndRole(
				objectUuid: $objectUuid,
				contactUid: ContactLink::partyUid(partyUuid: $survivorUuid),
				role: $link->getRole()
			);

			if ($held !== null) {
				// The survivor already holds that role on that object, which
				// is the duplicate the merge exists to remove. Absorb the
				// losing row into the one that stays, so the reversal can put
				// it back and the unique key is never violated.
				$this->absorb(into: $held, loser: $link, fromUuid: $fromUuid, operationId: $operationId);
				continue;
			}

			$link->setPartyUuid($survivorUuid);
			$link->setContactUid(ContactLink::partyUid(partyUuid: $survivorUuid));
			$link->setPartyKind($definition->kind());
			$link->setMetadata(
				self::withMemo(
					metadata: $link->getMetadata(),
					memo: ['operation' => $operationId, 'from' => $fromUuid, 'mode' => 'moved']
				)
			);
			$this->links->update($link);
		}//end foreach

		$this->unionAddresses(survivorUuid: $survivorUuid, fromUuid: $fromUuid, definition: $definition);
	}//end carryOverOne()

	/**
	 * Record a losing duplicate on the link that stays, then remove it.
	 *
	 * @param ContactLink $into The survivor's link on that object and role.
	 * @param ContactLink $loser The merged-away party's link.
	 * @param string $fromUuid The party merged away.
	 * @param string $operationId The merge operation.
	 *
	 * @return void
	 */
	private function absorb(ContactLink $into, ContactLink $loser, string $fromUuid, string $operationId): void {
		$memo = [
			'operation' => $operationId,
			'from' => $fromUuid,
			'mode' => 'absorbed',
			'absorbed' => [
				'objectUuid' => $loser->getObjectUuid(),
				'registerId' => $loser->getRegisterId(),
				'schemaId' => $loser->getSchemaId(),
				'role' => $loser->getRole(),
				'displayName' => $loser->getDisplayName(),
				'email' => $loser->getEmail(),
				'partyKind' => $loser->getPartyKind(),
				'primaryParty' => ($loser->getPrimaryParty() === true),
				'validFrom' => $loser->getValidFrom()?->format('Y-m-d'),
				'validUntil' => $loser->getValidUntil()?->format('Y-m-d'),
				'note' => $loser->getNote(),
				'linkedBy' => $loser->getLinkedBy(),
			],
		];

		$into->setMetadata(self::withMemo(metadata: $into->getMetadata(), memo: $memo));
		$this->links->update($into);
		$this->links->delete($loser);
	}//end absorb()

	/**
	 * Union the merged-away party's addresses onto the survivor.
	 *
	 * A party is reachable at every address it has ever given, and a merge
	 * that dropped one would make the surviving record less reachable than
	 * either of the two it replaced.
	 *
	 * @param string $survivorUuid The surviving party.
	 * @param string $fromUuid The party merged away.
	 * @param PartyDefinition $definition The survivor's party declaration.
	 *
	 * @return void
	 */
	private function unionAddresses(string $survivorUuid, string $fromUuid, PartyDefinition $definition): void {
		if ($definition->unionAddresses() === false) {
			return;
		}

		$survivor = $this->parties->find(partyUuid: $survivorUuid);
		$loser = $this->parties->find(partyUuid: $fromUuid);
		if ($survivor === null || $loser === null) {
			return;
		}

		$property = $definition->addressesProperty();
		$data = $survivor->getObject();
		$existing = ($data[$property] ?? []);
		if (is_array($existing) === false) {
			$existing = [];
		}

		$held = [];
		foreach ($this->parties->addresses(party: $survivor, definition: $definition) as $address) {
			$held[] = mb_strtolower($address['value']);
		}

		$added = false;
		foreach ($this->parties->addresses(party: $loser, definition: $definition) as $address) {
			if (in_array(mb_strtolower($address['value']), $held, true) === true) {
				continue;
			}

			$existing[] = $address;
			$held[] = mb_strtolower($address['value']);
			$added = true;
		}

		if ($added === false) {
			return;
		}

		$data[$property] = array_values($existing);
		$this->objects->saveObject(
			object: $data,
			register: $survivor->getRegister(),
			schema: $survivor->getSchema(),
			uuid: $survivorUuid
		);
	}//end unionAddresses()

	/**
	 * Put every role a merge moved back where it was.
	 *
	 * @param string $operationId The merge operation being reversed.
	 *
	 * @return void
	 */
	private function reverse(string $operationId): void {
		foreach ($this->links->findByOperationMemo(operationId: $operationId) as $link) {
			$memo = self::memoOf(metadata: $link->getMetadata());
			if (($memo['operation'] ?? null) !== $operationId) {
				continue;
			}

			if (($memo['mode'] ?? '') === 'absorbed') {
				$this->restoreAbsorbed(link: $link, memo: $memo);
				continue;
			}

			$fromUuid = (string)($memo['from'] ?? '');
			if ($fromUuid === '') {
				continue;
			}

			$link->setPartyUuid($fromUuid);
			$link->setContactUid(ContactLink::partyUid(partyUuid: $fromUuid));
			$link->setMetadata(self::withoutMemo(metadata: $link->getMetadata()));
			$this->links->update($link);
		}//end foreach
	}//end reverse()

	/**
	 * Re-create the duplicate row a merge absorbed.
	 *
	 * @param ContactLink $link The surviving link carrying the memo.
	 * @param array<string, mixed> $memo The memo.
	 *
	 * @return void
	 */
	private function restoreAbsorbed(ContactLink $link, array $memo): void {
		$absorbed = ($memo['absorbed'] ?? null);
		$fromUuid = (string)($memo['from'] ?? '');
		if (is_array($absorbed) === false || $fromUuid === '') {
			return;
		}

		$restored = new ContactLink();
		$restored->setObjectUuid((string)($absorbed['objectUuid'] ?? ''));
		$restored->setContactUid(ContactLink::partyUid(partyUuid: $fromUuid));
		$restored->setPartyUuid($fromUuid);
		$restored->setPartyKind(self::textOrNull(value: ($absorbed['partyKind'] ?? null)));
		$restored->setRegisterId((int)($absorbed['registerId'] ?? 0));
		$restored->setSchemaId(self::intOrNull(value: ($absorbed['schemaId'] ?? null)));
		$restored->setRole(self::textOrNull(value: ($absorbed['role'] ?? null)));
		$restored->setDisplayName(self::textOrNull(value: ($absorbed['displayName'] ?? null)));
		$restored->setEmail(self::textOrNull(value: ($absorbed['email'] ?? null)));
		$restored->setNote(self::textOrNull(value: ($absorbed['note'] ?? null)));
		$restored->setPrimaryParty((($absorbed['primaryParty'] ?? false) === true));
		$restored->setLinkedBy((string)($absorbed['linkedBy'] ?? ''));
		$restored->setLinkedAt(new \DateTime());
		$restored->setValidFrom(self::dateOrNull(value: ($absorbed['validFrom'] ?? null)));
		$restored->setValidUntil(self::dateOrNull(value: ($absorbed['validUntil'] ?? null)));
		$this->links->insert($restored);

		$link->setMetadata(self::withoutMemo(metadata: $link->getMetadata()));
		$this->links->update($link);
	}//end restoreAbsorbed()

	/**
	 * The merge memo on a link's metadata, [] when there is none.
	 *
	 * @param string|null $metadata The JSON-encoded metadata.
	 *
	 * @return array<string, mixed> The memo.
	 */
	private static function memoOf(?string $metadata): array {
		$decoded = self::decode(metadata: $metadata);
		$memo = ($decoded[self::MEMO_KEY] ?? null);
		if (is_array($memo) === false) {
			return [];
		}

		return $memo;
	}//end memoOf()

	/**
	 * The metadata with the merge memo written into it.
	 *
	 * @param string|null $metadata The JSON-encoded metadata.
	 * @param array<string, mixed> $memo The memo.
	 *
	 * @return string The JSON-encoded metadata.
	 */
	private static function withMemo(?string $metadata, array $memo): string {
		$decoded = self::decode(metadata: $metadata);
		$decoded[self::MEMO_KEY] = $memo;

		return (string)json_encode($decoded);
	}//end withMemo()

	/**
	 * The metadata with the merge memo taken out again.
	 *
	 * @param string|null $metadata The JSON-encoded metadata.
	 *
	 * @return string|null The JSON-encoded metadata, null when nothing is left.
	 */
	private static function withoutMemo(?string $metadata): ?string {
		$decoded = self::decode(metadata: $metadata);
		unset($decoded[self::MEMO_KEY]);
		if ($decoded === []) {
			return null;
		}

		return (string)json_encode($decoded);
	}//end withoutMemo()

	/**
	 * A link's metadata as an array.
	 *
	 * @param string|null $metadata The JSON-encoded metadata.
	 *
	 * @return array<string, mixed> The metadata.
	 */
	private static function decode(?string $metadata): array {
		if ($metadata === null || $metadata === '') {
			return [];
		}

		try {
			$decoded = json_decode($metadata, true, 512, JSON_THROW_ON_ERROR);
		} catch (\JsonException) {
			return [];
		}

		if (is_array($decoded) === false) {
			return [];
		}

		return $decoded;
	}//end decode()

	/**
	 * A trimmed non-empty string, or null.
	 *
	 * @param mixed $value The raw value.
	 *
	 * @return string|null The text.
	 */
	private static function textOrNull(mixed $value): ?string {
		if (is_scalar($value) === false) {
			return null;
		}

		$text = trim((string)$value);
		if ($text === '') {
			return null;
		}

		return $text;
	}//end textOrNull()

	/**
	 * An int, or null.
	 *
	 * @param mixed $value The raw value.
	 *
	 * @return int|null The id.
	 */
	private static function intOrNull(mixed $value): ?int {
		if (is_numeric($value) === false) {
			return null;
		}

		return (int)$value;
	}//end intOrNull()

	/**
	 * A date, or null.
	 *
	 * @param mixed $value A `Y-m-d` string.
	 *
	 * @return \DateTime|null The date.
	 */
	private static function dateOrNull(mixed $value): ?\DateTime {
		$text = self::textOrNull(value: $value);
		if ($text === null) {
			return null;
		}

		try {
			return new \DateTime($text);
		} catch (Throwable) {
			return null;
		}
	}//end dateOrNull()
}//end class
