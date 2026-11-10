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
 * @spec openspec/specs/mdm-merge/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Listener;

use DateTime;
use JsonException;
use OCA\OpenRegister\Db\ContactLink;
use OCA\OpenRegister\Db\ContactLinkMapperInterface;
use OCA\OpenRegister\Event\ObjectsMergedEvent;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCA\OpenRegister\Listener\Support\PartyCarryOver;
use OCA\OpenRegister\Service\Party\PartyDefinition;
use OCA\OpenRegister\Service\Party\PartyServiceInterface;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;
use Throwable;
use OCA\OpenRegister\Service\Object\ObjectSaveOptions;
use OCA\OpenRegister\Service\Object\ObjectFindTarget;

/**
 * Carries party roles and addresses across a merge, and back on a reversal.
 *
 * @template-implements IEventListener<ObjectsMergedEvent>
 *
 * @spec openspec/specs/mdm-merge/spec.md#requirement-parties-merge-through-the-existing-merge-primitive-req-prm-005
 *
 * @SuppressWarnings(PHPMD.StaticAccess) `ContactLink::partyUid()` is the entity's
 *   named constructor for the uid a party link is stored under. Spelling
 *   `'party:' . $uuid` by hand in four places is the duplication the constant
 *   exists to prevent, and a mistyped prefix is a link nothing ever finds.
 */
class PartyMergeListener implements IEventListener {

	/**
	 * The metadata key recording which merge moved a link.
	 */
	public const MEMO_KEY = 'partyMerge';

	/**
	 * Constructor.
	 *
	 * @param ContactLinkMapperInterface         $links   The link rows.
	 * @param PartyServiceInterface     $parties The party records.
	 * @param ObjectOperationsInterface $objects The object layer, for the address union.
	 * @param LoggerInterface           $logger  Diagnostics; a party that will not carry over never fails the merge.
	 */
	public function __construct(
		private readonly ContactLinkMapperInterface $links,
		private readonly PartyServiceInterface $parties,
		private readonly ObjectOperationsInterface $objects,
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
	 * @spec openspec/specs/mdm-merge/spec.md#requirement-parties-merge-through-the-existing-merge-primitive-req-prm-005
	 */
	public function handle(Event $event): void {
		if ($event instanceof ObjectsMergedEvent === false) {
			return;
		}

		try {
			$this->apply(event: $event);
		} catch (Throwable $e) {
			// The merge itself is already committed and reversible. A party
			// vocabulary that cannot be applied is a defect to read about, not
			// a reason to leave the merge half-done.
			$this->logger->error(
				'[PartyMergeListener] party vocabulary failed for merge "'
				. $event->getMergeOperationId() . '": ' . $e->getMessage()
			);
		}
	}//end handle()

	/**
	 * Carry the parties over, or put them back on a reversal.
	 *
	 * @param ObjectsMergedEvent $event The merge event.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/mdm-merge/spec.md#requirement-parties-merge-through-the-existing-merge-primitive-req-prm-005
	 */
	private function apply(ObjectsMergedEvent $event): void {
		if ($event->isReversal() === true) {
			$this->reverse(operationId: $event->getMergeOperationId());
			return;
		}

		$this->carryOver(event: $event);
	}//end apply()

	/**
	 * Move every role the merged-away parties held onto the survivor.
	 *
	 * @param ObjectsMergedEvent $event The merge event: survivor, parties merged away and operation.
	 *
	 * @return void
	 */
	private function carryOver(ObjectsMergedEvent $event): void {
		$survivorUuid = $event->getSurvivorUuid();
		$definition = $this->survivorDefinition(survivorUuid: $survivorUuid);
		if ($definition === null) {
			return;
		}

		foreach ($event->getMergedFromUuids() as $fromUuid) {
			$step = new PartyCarryOver(survivorUuid: $survivorUuid, fromUuid: $fromUuid, operationId: $event->getMergeOperationId(), definition: $definition);
			$this->carryOverOne(step: $step);
		}
	}//end carryOver()

	/**
	 * The survivor's party declaration, null when it is gone or not a party.
	 *
	 * @param string $survivorUuid The surviving party.
	 *
	 * @return PartyDefinition|null The declaration.
	 */
	private function survivorDefinition(string $survivorUuid): ?PartyDefinition {
		$survivor = $this->parties->find(partyUuid: $survivorUuid);
		if ($survivor === null) {
			return null;
		}

		return $this->parties->definitionFor(party: $survivor);
	}//end survivorDefinition()

	/**
	 * Move one merged-away party's roles and addresses onto the survivor.
	 *
	 * @param PartyCarryOver $step The party carried over.
	 *
	 * @return void
	 */
	private function carryOverOne(PartyCarryOver $step): void {
		foreach ($this->links->findByPartyUuid(partyUuid: $step->fromUuid) as $link) {
			$this->carryLink(link: $link, step: $step);
		}

		$this->unionAddresses(step: $step);
	}//end carryOverOne()

	/**
	 * Carry one link of the merged-away party: absorb it into the survivor's
	 * link on the same object and role, or re-point it to the survivor.
	 *
	 * @param ContactLink    $link The merged-away party's link.
	 * @param PartyCarryOver $step The party carried over.
	 *
	 * @return void
	 */
	private function carryLink(ContactLink $link, PartyCarryOver $step): void {
		$held = $this->links->findByObjectContactAndRole(
			objectUuid: (string)($link->getObjectUuid() ?? ''),
			contactUid: ContactLink::partyUid(partyUuid: $step->survivorUuid),
			role: $link->getRole()
		);

		if ($held !== null) {
			// The survivor already holds that role on that object, which
			// is the duplicate the merge exists to remove. Absorb the
			// losing row into the one that stays, so the reversal can put
			// it back and the unique key is never violated.
			$this->absorb(into: $held, memo: self::absorbedMemo(loser: $link, step: $step));
			$this->links->delete($link);
			return;
		}

		$this->moveLink(link: $link, step: $step);
	}//end carryLink()

	/**
	 * Re-point a link to the survivor, recording the move.
	 *
	 * @param ContactLink    $link The merged-away party's link.
	 * @param PartyCarryOver $step The party carried over.
	 *
	 * @return void
	 */
	private function moveLink(ContactLink $link, PartyCarryOver $step): void {
		$link->setPartyUuid($step->survivorUuid);
		$link->setContactUid(ContactLink::partyUid(partyUuid: $step->survivorUuid));
		$link->setPartyKind($step->definition->kind());
		$link->setMetadata(self::withMemo(metadata: $link->getMetadata(), memo: $step->memo(mode: 'moved')));
		$this->links->update($link);
	}//end moveLink()

	/**
	 * Record a losing duplicate on the link that stays; the caller then removes the duplicate.
	 *
	 * @param ContactLink          $into The survivor's link on that object and role.
	 * @param array<string, mixed> $memo The absorbed memo, from {@see self::absorbedMemo()}.
	 *
	 * @return void
	 */
	private function absorb(ContactLink $into, array $memo): void {
		$into->setMetadata(self::withMemo(metadata: $into->getMetadata(), memo: $memo));
		$this->links->update($into);
	}//end absorb()

	/**
	 * The memo recording an absorbed duplicate, with the full snapshot a reversal needs.
	 *
	 * @param ContactLink    $loser The merged-away party's link.
	 * @param PartyCarryOver $step  The party carried over.
	 *
	 * @return array<string, mixed> The memo.
	 */
	private static function absorbedMemo(ContactLink $loser, PartyCarryOver $step): array {
		$memo = $step->memo(mode: 'absorbed');
		$memo['absorbed'] = array_merge(self::placementOf(link: $loser), self::detailsOf(link: $loser));
		return $memo;
	}//end absorbedMemo()

	/**
	 * Where an absorbed link sat: its object, register, schema, role and contact.
	 *
	 * @param ContactLink $link The absorbed link.
	 *
	 * @return array<string, mixed> The first half of the absorbed snapshot.
	 */
	private static function placementOf(ContactLink $link): array {
		return [
			'objectUuid' => $link->getObjectUuid(),
			'registerId' => $link->getRegisterId(),
			'schemaId' => $link->getSchemaId(),
			'role' => $link->getRole(),
			'displayName' => $link->getDisplayName(),
			'email' => $link->getEmail(),
		];
	}//end placementOf()

	/**
	 * How an absorbed link was held: kind, primacy, validity, note and who linked it.
	 *
	 * @param ContactLink $link The absorbed link.
	 *
	 * @return array<string, mixed> The second half of the absorbed snapshot.
	 */
	private static function detailsOf(ContactLink $link): array {
		return [
			'partyKind' => $link->getPartyKind(),
			'primaryParty' => ($link->getPrimaryParty() === true),
			'validFrom' => $link->getValidFrom()?->format('Y-m-d'),
			'validUntil' => $link->getValidUntil()?->format('Y-m-d'),
			'note' => $link->getNote(),
			'linkedBy' => $link->getLinkedBy(),
		];
	}//end detailsOf()

	/**
	 * Union the merged-away party's addresses onto the survivor.
	 *
	 * A party is reachable at every address it has ever given, and a merge
	 * that dropped one would make the surviving record less reachable than
	 * either of the two it replaced.
	 *
	 * @param PartyCarryOver $step The party carried over.
	 *
	 * @return void
	 */
	private function unionAddresses(PartyCarryOver $step): void {
		if ($step->definition->unionAddresses() === false) {
			return;
		}

		$survivor = $this->parties->find(partyUuid: $step->survivorUuid);
		$loser = $this->parties->find(partyUuid: $step->fromUuid);
		if ($survivor === null || $loser === null) {
			return;
		}

		$run = new PartyAddressUnionRun();
		$run->survivor = $survivor;
		$run->loser = $loser;
		$run->step = $step;
		$this->saveUnion(run: $run);
	}//end unionAddresses()

	/**
	 * Save the survivor with the loser's new addresses added, when there are any.
	 *
	 * @param PartyAddressUnionRun $run The survivor, the party merged away and the carry-over.
	 *
	 * @return void
	 */
	private function saveUnion(PartyAddressUnionRun $run): void {
		$united = $this->unitedAddresses(run: $run);
		if ($united === null) {
			return;
		}

		$survivor = $run->survivor;
		$data = $survivor->getObject();
		$data[$run->step->definition->addressesProperty()] = $united;
		$this->objects->saveObject(
			object: $data,
			options: new ObjectSaveOptions(
				target: new ObjectFindTarget(register: $survivor->getRegister(), schema: $survivor->getSchema()),
				uuid: $run->step->survivorUuid
			)
		);
	}//end saveUnion()

	/**
	 * The survivor's stored addresses followed by the loser's addresses it
	 * does not hold yet, or null when the loser adds none.
	 *
	 * @param PartyAddressUnionRun $run The survivor, the party merged away and the carry-over.
	 *
	 * @return array<int, mixed>|null The united address list.
	 */
	private function unitedAddresses(PartyAddressUnionRun $run): ?array {
		$definition = $run->step->definition;
		$added = self::newAddresses(
			held: $this->addressKeys(party: $run->survivor, definition: $definition),
			candidates: $this->parties->addresses(party: $run->loser, definition: $definition)
		);
		if ($added === []) {
			return null;
		}

		$existing = ($run->survivor->getObject()[$definition->addressesProperty()] ?? []);
		if (is_array($existing) === false) {
			$existing = [];
		}

		return array_values(array_merge($existing, $added));
	}//end unitedAddresses()

	/**
	 * A party's address values, lower-cased for comparison.
	 *
	 * @param ObjectEntity    $party      The party.
	 * @param PartyDefinition $definition The party declaration.
	 *
	 * @return array<int, string> The comparison keys.
	 */
	private function addressKeys(ObjectEntity $party, PartyDefinition $definition): array {
		return array_values(
			array_map(
				static fn (array $address): string => mb_strtolower($address['value']),
				$this->parties->addresses(party: $party, definition: $definition)
			)
		);
	}//end addressKeys()

	/**
	 * The candidate addresses whose value is not held yet, each value taken once.
	 *
	 * @param array<int, string>               $held       Lower-cased values already held.
	 * @param array<int, array<string, mixed>> $candidates The addresses to add.
	 *
	 * @return array<int, array<string, mixed>> The addresses to add, in order.
	 */
	private static function newAddresses(array $held, array $candidates): array {
		$added = [];
		foreach ($candidates as $address) {
			$key = mb_strtolower($address['value']);
			if (in_array($key, $held, true) === false) {
				$added[] = $address;
				$held[] = $key;
			}
		}

		return $added;
	}//end newAddresses()

	/**
	 * Put every role a merge moved back where it was.
	 *
	 * @param string $operationId The merge operation being reversed.
	 *
	 * @return void
	 */
	private function reverse(string $operationId): void {
		foreach ($this->links->findByOperationMemo(operationId: $operationId) as $link) {
			$this->reverseLink(link: $link, operationId: $operationId);
		}
	}//end reverse()

	/**
	 * Undo what one merge did to one link; a link whose memo names another merge is left alone.
	 *
	 * @param ContactLink $link        The link carrying a memo.
	 * @param string      $operationId The merge operation being reversed.
	 *
	 * @return void
	 */
	private function reverseLink(ContactLink $link, string $operationId): void {
		$memo = self::memoOf(metadata: $link->getMetadata());
		if (($memo['operation'] ?? null) !== $operationId) {
			return;
		}

		if (($memo['mode'] ?? '') === 'absorbed') {
			$this->restoreAbsorbed(link: $link, memo: $memo);
			return;
		}

		$fromUuid = (string)($memo['from'] ?? '');
		if ($fromUuid !== '') {
			$this->moveBack(link: $link, fromUuid: $fromUuid);
		}
	}//end reverseLink()

	/**
	 * Re-point a moved link to the party it came from and drop the memo.
	 *
	 * @param ContactLink $link     The moved link.
	 * @param string      $fromUuid The party it came from.
	 *
	 * @return void
	 */
	private function moveBack(ContactLink $link, string $fromUuid): void {
		$link->setPartyUuid($fromUuid);
		$link->setContactUid(ContactLink::partyUid(partyUuid: $fromUuid));
		$link->setMetadata(self::withoutMemo(metadata: $link->getMetadata()));
		$this->links->update($link);
	}//end moveBack()

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

		$this->links->insert(self::restoredLink(absorbed: $absorbed, fromUuid: $fromUuid));

		$link->setMetadata(self::withoutMemo(metadata: $link->getMetadata()));
		$this->links->update($link);
	}//end restoreAbsorbed()

	/**
	 * The absorbed row rebuilt from its snapshot, held by the party it came from.
	 *
	 * @param array<string, mixed> $absorbed The absorbed snapshot.
	 * @param string               $fromUuid The party it came from.
	 *
	 * @return ContactLink The row to insert.
	 */
	private static function restoredLink(array $absorbed, string $fromUuid): ContactLink {
		$restored = new ContactLink();
		$restored->setObjectUuid((string)($absorbed['objectUuid'] ?? ''));
		$restored->setContactUid(ContactLink::partyUid(partyUuid: $fromUuid));
		$restored->setPartyUuid($fromUuid);
		$restored->setPartyKind(self::textOrNull(value: ($absorbed['partyKind'] ?? null)));
		$restored->setRegisterId((int)($absorbed['registerId'] ?? 0));
		$restored->setSchemaId(self::intOrNull(value: ($absorbed['schemaId'] ?? null)));
		$restored->setRole(self::textOrNull(value: ($absorbed['role'] ?? null)));
		self::restoreDetails(restored: $restored, absorbed: $absorbed);

		return $restored;
	}//end restoredLink()

	/**
	 * Copy the contact, note, primacy, linker and validity of the snapshot onto the rebuilt row.
	 *
	 * @param ContactLink          $restored The row being rebuilt.
	 * @param array<string, mixed> $absorbed The absorbed snapshot.
	 *
	 * @return void
	 */
	private static function restoreDetails(ContactLink $restored, array $absorbed): void {
		$restored->setDisplayName(self::textOrNull(value: ($absorbed['displayName'] ?? null)));
		$restored->setEmail(self::textOrNull(value: ($absorbed['email'] ?? null)));
		$restored->setNote(self::textOrNull(value: ($absorbed['note'] ?? null)));
		$restored->setPrimaryParty((($absorbed['primaryParty'] ?? false) === true));
		$restored->setLinkedBy((string)($absorbed['linkedBy'] ?? ''));
		$restored->setLinkedAt(new DateTime());
		$restored->setValidFrom(self::dateOrNull(value: ($absorbed['validFrom'] ?? null)));
		$restored->setValidUntil(self::dateOrNull(value: ($absorbed['validUntil'] ?? null)));
	}//end restoreDetails()

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
	 * A link's metadata as an array; absent, empty or malformed metadata is [].
	 *
	 * @param string|null $metadata The JSON-encoded metadata.
	 *
	 * @return array<string, mixed> The metadata.
	 */
	private static function decode(?string $metadata): array {
		try {
			$decoded = json_decode((string)$metadata, true, 512, JSON_THROW_ON_ERROR);
		} catch (JsonException) {
			$decoded = null;
		}

		if (is_array($decoded) === true) {
			return $decoded;
		}

		return [];
	}//end decode()

	/**
	 * A trimmed non-empty string, or null.
	 *
	 * @param mixed $value The raw value.
	 *
	 * @return string|null The text.
	 */
	private static function textOrNull(mixed $value): ?string {
		$text = '';
		if (is_scalar($value) === true) {
			$text = trim((string)$value);
		}

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
	 * @return DateTime|null The date.
	 */
	private static function dateOrNull(mixed $value): ?DateTime {
		$text = self::textOrNull(value: $value);
		try {
			return match ($text) {
				null => null,
				default => new DateTime($text),
			};
		} catch (Throwable) {
			return null;
		}
	}//end dateOrNull()
}//end class
