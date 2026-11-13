<?php

/**
 * DraftService — named draft versions of an object: create, update, list, discard.
 *
 * A draft stores ONLY the delta: the fields whose value differs from the live
 * object (spec: "Drafts MUST store only the delta"). Creating or updating a draft
 * never touches the live object. The key `main` is reserved for the published
 * version, and a key is lower-case letters, digits and single hyphens.
 *
 * Visibility (spec: "accessible only by its creator and users with write
 * permissions on the object's register/schema"): a draft is visible to, and may
 * be changed or discarded by, its creator and any caller with `update` on the
 * object. Every other caller is refused, and a draft they may not see is left
 * out of their list rather than shown and refused.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Object
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/specs/content-versioning/spec.md#requirement-objects-must-support-a-draftpublished-lifecycle
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Object;

use DateTime;
use InvalidArgumentException;
use OCA\OpenRegister\Db\DraftVersion;
use OCA\OpenRegister\Db\DraftVersionMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Exception\NotAuthorizedException;
use Symfony\Component\Uid\Uuid;

/**
 * The draft lifecycle short of promotion.
 *
 * @spec openspec/specs/content-versioning/spec.md#requirement-objects-must-support-a-draftpublished-lifecycle
 */
class DraftService {

	/**
	 * The key reserved for the published version.
	 *
	 * @var string
	 */
	public const MAIN = 'main';

	/**
	 * A draft key: lower-case letters and digits, joined by single hyphens.
	 *
	 * @var string
	 */
	private const KEY_PATTERN = '/^[a-z0-9]+(-[a-z0-9]+)*$/';

	/**
	 * The longest draft key, the width of its column.
	 *
	 * @var int
	 */
	private const KEY_MAX = 64;

	/**
	 * Constructor.
	 *
	 * @param DraftVersionMapper       $drafts      The draft store.
	 * @param PermissionCheckInterface $permissions Decides whether a caller may update the object.
	 */
	public function __construct(
		private readonly DraftVersionMapper $drafts,
		private readonly PermissionCheckInterface $permissions,
	) {
	}//end __construct()

	/**
	 * Make a named draft of an object, storing only the fields that differ from the live object.
	 *
	 * @param ObjectEntity         $object  The live object.
	 * @param string               $key     The draft key.
	 * @param string               $name    A human-readable name; the key when empty.
	 * @param array<string, mixed> $changes The changed fields.
	 * @param string               $actor   The user making the draft.
	 *
	 * @return DraftVersion The stored draft.
	 *
	 * @throws InvalidArgumentException When the key is malformed, reserved or already used on this object.
	 *
	 * @spec openspec/specs/content-versioning/spec.md#requirement-objects-must-support-a-draftpublished-lifecycle
	 */
	public function create(ObjectEntity $object, string $key, string $name, array $changes, string $actor): DraftVersion {
		$key = self::validKey(key: $key);
		$objectUuid = (string)$object->getUuid();
		if ($this->drafts->findByKey(objectUuid: $objectUuid, key: $key) !== null) {
			throw new InvalidArgumentException('This object already has a draft with the key "' . $key . '".');
		}

		$now = new DateTime();
		$draft = new DraftVersion();
		$draft->setUuid(Uuid::v4()->toRfc4122());
		$draft->setObjectUuid($objectUuid);
		$draft->setObjectRegister(self::idOrNull(value: $object->getRegister()));
		$draft->setObjectSchema(self::idOrNull(value: $object->getSchema()));
		$draft->setDraftKey($key);
		$draft->setName(trim($name));
		if (trim($name) === '') {
			$draft->setName($key);
		}

		$draft->setCreatedBy($actor);
		$draft->setBaseVersion(self::idOrNull(value: $object->getVersion()));
		$draft->setDelta(self::deltaAgainst(live: self::liveData(object: $object), changes: $changes));
		$draft->setCreated($now);
		$draft->setUpdated($now);

		return $this->drafts->insert(entity: $draft);
	}//end create()

	/**
	 * Apply further changes to a draft; a field changed back to its live value leaves the delta.
	 *
	 * @param DraftVersion         $draft   The draft.
	 * @param ObjectEntity         $object  The live object.
	 * @param Schema               $schema  The object's schema.
	 * @param array<string, mixed> $changes The changed fields.
	 * @param string|null          $caller  The calling user.
	 *
	 * @return DraftVersion The stored draft.
	 *
	 * @throws NotAuthorizedException When the caller may not see the draft.
	 *
	 * @spec openspec/specs/content-versioning/spec.md#requirement-objects-must-support-a-draftpublished-lifecycle
	 */
	public function update(DraftVersion $draft, ObjectEntity $object, Schema $schema, array $changes, ?string $caller): DraftVersion {
		$this->assertMayAccess(draft: $draft, object: $object, schema: $schema, caller: $caller);

		$merged = array_replace(($draft->getDelta() ?? []), $changes);
		$draft->setDelta(self::deltaAgainst(live: self::liveData(object: $object), changes: $merged));
		$draft->setUpdated(new DateTime());

		return $this->drafts->update(entity: $draft);
	}//end update()

	/**
	 * The drafts of an object the caller may see; the others are left out.
	 *
	 * @param ObjectEntity $object The live object.
	 * @param Schema       $schema The object's schema.
	 * @param string|null  $caller The calling user.
	 *
	 * @return array<int, DraftVersion> The visible drafts, oldest first.
	 *
	 * @spec openspec/specs/content-versioning/spec.md#requirement-objects-must-support-a-draftpublished-lifecycle
	 */
	public function visibleDrafts(ObjectEntity $object, Schema $schema, ?string $caller): array {
		$drafts = $this->drafts->findForObject(objectUuid: (string)$object->getUuid());
		if ($drafts === [] || $this->mayWrite(object: $object, schema: $schema, caller: $caller) === true) {
			return $drafts;
		}

		return array_values(
			array_filter(
				$drafts,
				static fn (DraftVersion $draft): bool => $caller !== null && $draft->getCreatedBy() === $caller
			)
		);
	}//end visibleDrafts()

	/**
	 * Delete a draft the caller may see.
	 *
	 * @param DraftVersion $draft  The draft.
	 * @param ObjectEntity $object The live object.
	 * @param Schema       $schema The object's schema.
	 * @param string|null  $caller The calling user.
	 *
	 * @return void
	 *
	 * @throws NotAuthorizedException When the caller may not see the draft.
	 *
	 * @spec openspec/specs/content-versioning/spec.md#requirement-objects-must-support-a-draftpublished-lifecycle
	 */
	public function discard(DraftVersion $draft, ObjectEntity $object, Schema $schema, ?string $caller): void {
		$this->assertMayAccess(draft: $draft, object: $object, schema: $schema, caller: $caller);
		$this->drafts->delete(entity: $draft);
	}//end discard()

	/**
	 * The live data with the draft's delta on top.
	 *
	 * @param ObjectEntity $object The live object.
	 * @param DraftVersion $draft  The draft.
	 *
	 * @return array<string, mixed> The merged data.
	 *
	 * @spec openspec/specs/content-versioning/spec.md#requirement-objects-must-support-a-draftpublished-lifecycle
	 */
	public function merged(ObjectEntity $object, DraftVersion $draft): array {
		return array_replace(self::liveData(object: $object), ($draft->getDelta() ?? []));
	}//end merged()

	/**
	 * Whether the caller may see and change a draft: its creator, or a caller with `update` on the object.
	 *
	 * @param DraftVersion $draft  The draft.
	 * @param ObjectEntity $object The live object.
	 * @param Schema       $schema The object's schema.
	 * @param string|null  $caller The calling user.
	 *
	 * @return bool True when the caller may.
	 *
	 * @spec openspec/specs/content-versioning/spec.md#requirement-objects-must-support-a-draftpublished-lifecycle
	 */
	public function mayAccess(DraftVersion $draft, ObjectEntity $object, Schema $schema, ?string $caller): bool {
		if ($caller !== null && $draft->getCreatedBy() === $caller) {
			return true;
		}

		return $this->mayWrite(object: $object, schema: $schema, caller: $caller);
	}//end mayAccess()

	/**
	 * A draft key, validated.
	 *
	 * @param string $key The key as given.
	 *
	 * @return string The key.
	 *
	 * @throws InvalidArgumentException When the key is malformed or reserved.
	 *
	 * @spec openspec/changes/records-draft-versions/tasks.md
	 */
	public static function validKey(string $key): string {
		if ($key === self::MAIN) {
			throw new InvalidArgumentException('The key "main" is reserved for the published version.');
		}

		if (strlen($key) > self::KEY_MAX || preg_match(self::KEY_PATTERN, $key) !== 1) {
			throw new InvalidArgumentException(
				'A draft key is lower-case letters and digits joined by single hyphens, at most ' . self::KEY_MAX . ' characters.'
			);
		}

		return $key;
	}//end validKey()

	/**
	 * Refuse a caller who may not see the draft.
	 *
	 * @param DraftVersion $draft  The draft.
	 * @param ObjectEntity $object The live object.
	 * @param Schema       $schema The object's schema.
	 * @param string|null  $caller The calling user.
	 *
	 * @return void
	 *
	 * @throws NotAuthorizedException When the caller may not.
	 */
	private function assertMayAccess(DraftVersion $draft, ObjectEntity $object, Schema $schema, ?string $caller): void {
		if ($this->mayAccess(draft: $draft, object: $object, schema: $schema, caller: $caller) === false) {
			throw new NotAuthorizedException(message: 'Only the draft\'s creator or a user who may change the object may do this.');
		}
	}//end assertMayAccess()

	/**
	 * Whether the caller may update the object.
	 *
	 * @param ObjectEntity $object The live object.
	 * @param Schema       $schema The object's schema.
	 * @param string|null  $caller The calling user.
	 *
	 * @return bool True when they may.
	 */
	private function mayWrite(ObjectEntity $object, Schema $schema, ?string $caller): bool {
		if ($caller === null) {
			return false;
		}

		return $this->permissions->hasPermission(
			schema: $schema,
			action: 'update',
			userId: $caller,
			objectOwner: $object->getOwner(),
			object: $object
		);
	}//end mayWrite()

	/**
	 * The fields of `changes` whose value differs from the live data.
	 *
	 * @param array<string, mixed> $live    The live data.
	 * @param array<string, mixed> $changes The proposed values.
	 *
	 * @return array<string, mixed> The delta.
	 */
	private static function deltaAgainst(array $live, array $changes): array {
		$delta = [];
		foreach ($changes as $field => $value) {
			$field = (string)$field;
			if ($field === '' || $field[0] === '@' || in_array($field, ['id', 'uuid'], true) === true) {
				continue;
			}

			if (array_key_exists($field, $live) === true && $live[$field] === $value) {
				continue;
			}

			$delta[$field] = $value;
		}

		return $delta;
	}//end deltaAgainst()

	/**
	 * The object's own data, without metadata.
	 *
	 * @param ObjectEntity $object The object.
	 *
	 * @return array<string, mixed> The data.
	 */
	private static function liveData(ObjectEntity $object): array {
		$data = $object->getObject();
		if (is_array($data) === false) {
			return [];
		}

		unset($data['@self']);
		return $data;
	}//end liveData()

	/**
	 * A scalar id as a string, or null.
	 *
	 * @param mixed $value The value.
	 *
	 * @return string|null The id.
	 */
	private static function idOrNull(mixed $value): ?string {
		if (is_string($value) === true || is_int($value) === true) {
			return (string)$value;
		}

		return null;
	}//end idOrNull()
}//end class
