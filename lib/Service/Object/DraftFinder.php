<?php

/**
 * Finds an owner's drafts: one by id, or the newest in a destination (decisions 180 and 195).
 *
 * Both lookups read with RBAC off on purpose and then admit only what
 * DraftOwner::owns() accepts: a draft whose stored owner is exactly the
 * asserted owner. A scope-value owner such as `portal:<subjectRef>` has no
 * Nextcloud user for RBAC to judge, and an administrator's session bypasses
 * RBAC, so the owner comparison here is the check, for everyone alike.
 * Anything else is "not found", which callers answer with one 404.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Object
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/form-destination-validator/specs/form-destination/spec.md#requirement-a-draft-must-be-able-to-be-owned-by-a-scope-value
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Object;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindOptions;
use Throwable;

/**
 * One owner's drafts, by id or by destination.
 *
 * @spec openspec/changes/form-destination-validator/specs/form-destination/spec.md#requirement-a-draft-must-be-able-to-be-owned-by-a-scope-value
 */
class DraftFinder {

	/**
	 * How many of one owner's objects in a destination a search reads.
	 *
	 * @var integer
	 */
	public const SEARCH_LIMIT = 50;

	/**
	 * Constructor.
	 *
	 * @param ObjectOperationsInterface $objects Reads objects.
	 */
	public function __construct(
		private readonly ObjectOperationsInterface $objects,
	) {

	}//end __construct()

	/**
	 * The owner's draft with this id, or null when it is unknown, not a draft, or someone else's.
	 *
	 * @param Register        $register The destination register.
	 * @param Schema          $schema   The destination schema.
	 * @param string          $draftId  The draft's uuid.
	 * @param DraftOwner|null $owner    The asserted owner; null finds nothing.
	 *
	 * @return ObjectEntity|null The draft.
	 *
	 * @spec openspec/changes/form-destination-validator/specs/form-destination/spec.md#requirement-a-draft-must-be-able-to-be-owned-by-a-scope-value
	 */
	public function own(Register $register, Schema $schema, string $draftId, ?DraftOwner $owner): ?ObjectEntity {
		if ($owner === null) {
			return null;
		}

		return $this->ownedBy(draft: $this->findDraft(address: new DraftAddress(register: $register, schema: $schema, draftId: $draftId)), owner: $owner);
	}//end own()

	/**
	 * Read a draft by id with RBAC off; a failed read is no draft.
	 *
	 * @param DraftAddress $address The register, the schema and the draft id.
	 *
	 * @return ObjectEntity|null The object, or null when it cannot be read
	 *
	 * @spec openspec/changes/form-destination-validator/specs/form-destination/spec.md#requirement-a-draft-must-be-able-to-be-owned-by-a-scope-value
	 */
	private function findDraft(DraftAddress $address): ?ObjectEntity {
		try {
			return $this->objects->find(
				id: $address->draftId,
				options: new ObjectFindOptions(register: $address->register, schema: $address->schema, audit: false),
				_rbac: false,
				_multitenancy: false
			);
		} catch (Throwable) {
			return null;
		}
	}//end findDraft()

	/**
	 * The object when the owner owns it, else null.
	 *
	 * @param ObjectEntity|null $draft The object read.
	 * @param DraftOwner        $owner The owner asking.
	 *
	 * @return ObjectEntity|null The owned object
	 *
	 * @spec openspec/changes/form-destination-validator/specs/form-destination/spec.md#requirement-a-draft-must-be-able-to-be-owned-by-a-scope-value
	 */
	private function ownedBy(?ObjectEntity $draft, DraftOwner $owner): ?ObjectEntity {
		if ($draft === null || $owner->owns(object: $draft) === false) {
			return null;
		}

		return $draft;
	}//end ownedBy()

	/**
	 * The owner's most recently changed draft in a destination whose data holds each `$match` value exactly.
	 *
	 * @param Register             $register The destination register.
	 * @param Schema               $schema   The destination schema.
	 * @param DraftOwner           $owner    The asserted owner.
	 * @param array<string, mixed> $match    Values the draft's data must hold, such as a form's fixed values.
	 *
	 * @return ObjectEntity|null The draft, or null.
	 *
	 * @spec openspec/changes/form-destination-validator/specs/form-destination/spec.md#requirement-a-draft-must-be-able-to-be-owned-by-a-scope-value
	 */
	public function newest(Register $register, Schema $schema, DraftOwner $owner, array $match = []): ?ObjectEntity {
		return $this->newestFor(search: new OwnDraftSearch(register: $register, schema: $schema, owner: $owner, match: $match));
	}//end newest()

	/**
	 * The newest draft one search finds, or null.
	 *
	 * @param OwnDraftSearch $search The destination, the owner and the values to match.
	 *
	 * @return ObjectEntity|null The draft, or null.
	 *
	 * @spec openspec/changes/form-destination-validator/specs/form-destination/spec.md#requirement-a-draft-must-be-able-to-be-owned-by-a-scope-value
	 */
	private function newestFor(OwnDraftSearch $search): ?ObjectEntity {
		$found = $this->searchOwnDrafts(search: $search);
		if ($found === null) {
			return null;
		}

		return $this->newestOf(found: $found, search: $search);
	}//end newestFor()

	/**
	 * List the owner's drafts of a schema, or null when the list cannot be read.
	 *
	 * @param OwnDraftSearch $search The destination and the owner.
	 *
	 * @return array|null The listed objects
	 *
	 * @spec openspec/changes/form-destination-validator/specs/form-destination/spec.md#requirement-a-draft-must-be-able-to-be-owned-by-a-scope-value
	 */
	private function searchOwnDrafts(OwnDraftSearch $search): ?array {
		$self = [
			'register' => (string)$search->register->getId(),
			'schema' => (string)$search->schema->getId(),
			'owner' => $search->owner->value(),
		];
		try {
			$found = $this->objects->searchObjects(
				query: [
					'@self' => $self,
					'_limit' => self::SEARCH_LIMIT,
					// A list hides drafts unless asked (decision 201), and this
					// owner has no session for the lens to recognise as "own".
					DraftListLens::PARAMETER => 'true',
				],
				_rbac: false,
				_multitenancy: false
			);
		} catch (Throwable) {
			return null;
		}

		if (is_array($found) === false) {
			return null;
		}

		return $found;
	}//end searchOwnDrafts()

	/**
	 * The most recently changed qualifying draft of a list; the first one wins a tie.
	 *
	 * @param array          $found  The listed objects.
	 * @param OwnDraftSearch $search The owner, and the property values the draft must carry.
	 *
	 * @return ObjectEntity|null The newest draft
	 *
	 * @spec openspec/changes/form-destination-validator/specs/form-destination/spec.md#requirement-a-draft-must-be-able-to-be-owned-by-a-scope-value
	 */
	private function newestOf(array $found, OwnDraftSearch $search): ?ObjectEntity {
		$qualifying = array_filter(
			$found,
			fn (mixed $candidate): bool => $this->qualifies(candidate: $candidate, search: $search)
		);

		$newest = null;
		foreach ($qualifying as $candidate) {
			if (self::isNewer(candidate: $candidate, newest: $newest) === true) {
				$newest = $candidate;
			}
		}

		return $newest;
	}//end newestOf()

	/**
	 * Whether a candidate is newer than the newest so far.
	 *
	 * @param ObjectEntity      $candidate The candidate.
	 * @param ObjectEntity|null $newest    The newest so far.
	 *
	 * @return bool True when the candidate replaces it
	 *
	 * @spec openspec/changes/form-destination-validator/specs/form-destination/spec.md#requirement-a-draft-must-be-able-to-be-owned-by-a-scope-value
	 */
	private static function isNewer(ObjectEntity $candidate, ?ObjectEntity $newest): bool {
		return $newest === null || self::changedAt(object: $candidate) > self::changedAt(object: $newest);
	}//end isNewer()

	/**
	 * An object's data without its metadata.
	 *
	 * @param ObjectEntity $object The object.
	 *
	 * @return array<string, mixed> The data.
	 *
	 * @spec openspec/changes/form-destination-validator/specs/form-destination/spec.md#requirement-a-draft-must-be-able-to-be-owned-by-a-scope-value
	 */
	public static function dataOf(ObjectEntity $object): array {
		$data = [];
		foreach ($object->getObject() as $property => $value) {
			if ($property !== 'id' && $property !== '@self') {
				$data[(string)$property] = $value;
			}
		}

		return $data;
	}//end dataOf()

	/**
	 * A draft as callers get it: id, status, data and when it last changed (for a caller's own retention).
	 *
	 * @param ObjectEntity $draft The draft.
	 *
	 * @return array{id: string, status: string, data: array<string, mixed>, updatedAt: string} The draft.
	 *
	 * @spec openspec/changes/form-destination-validator/specs/form-destination/spec.md#requirement-a-draft-must-be-able-to-be-owned-by-a-scope-value
	 */
	public static function answer(ObjectEntity $draft): array {
		$updatedAt = '';
		if (self::changedAt(object: $draft) > 0) {
			$updatedAt = ($draft->getUpdated() ?? $draft->getCreated())->format(DATE_ATOM);
		}

		return [
			'id' => (string)$draft->getUuid(),
			'status' => ObjectEntity::STATUS_DRAFT,
			'data' => self::dataOf(object: $draft),
			'updatedAt' => $updatedAt,
		];
	}//end answer()

	/**
	 * Whether a search hit is the owner's draft and holds every match value.
	 *
	 * @param mixed          $candidate The search hit.
	 * @param OwnDraftSearch $search    The owner, and the property values to hold.
	 *
	 * @return bool True when it qualifies.
	 *
	 * @psalm-assert-if-true ObjectEntity $candidate
	 */
	private function qualifies(mixed $candidate, OwnDraftSearch $search): bool {
		if ($candidate instanceof ObjectEntity === false || $search->owner->owns(object: $candidate) === false) {
			return false;
		}

		return self::carries(data: $candidate->getObject(), match: $search->match);
	}//end qualifies()

	/**
	 * Whether data carries every matched property with exactly the matched value.
	 *
	 * @param array $data  The object data.
	 * @param array $match Property values to carry.
	 *
	 * @return bool True when every value matches
	 *
	 * @spec openspec/changes/form-destination-validator/specs/form-destination/spec.md#requirement-a-draft-must-be-able-to-be-owned-by-a-scope-value
	 */
	private static function carries(array $data, array $match): bool {
		foreach ($match as $property => $value) {
			if (array_key_exists((string)$property, $data) === false || $data[(string)$property] !== $value) {
				return false;
			}
		}

		return true;
	}//end carries()

	/**
	 * The object's last change as a timestamp.
	 *
	 * @param ObjectEntity $object The object.
	 *
	 * @return int Seconds since the epoch; 0 when unknown.
	 */
	private static function changedAt(ObjectEntity $object): int {
		$moment = $object->getUpdated() ?? $object->getCreated();
		if ($moment === null) {
			return 0;
		}

		return $moment->getTimestamp();
	}//end changedAt()
}//end class
