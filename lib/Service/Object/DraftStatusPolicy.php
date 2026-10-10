<?php

/**
 * The rules of the explicit lifecycle status `draft` (decision 180).
 *
 * Ruben, 10 October 2026: a draft IS the destination object, saved with an
 * explicit status `draft`. While draft it may miss required data, but it is
 * never type-invalid. This class holds the generic rules every save path
 * asks: may this write set or keep `draft`, may it leave `draft`, which
 * `required` entries a draft is excused from, and the SQL clause that keeps
 * other people's drafts out of a list. It knows nothing of any app or
 * procedure (decision 182).
 *
 * Leaving draft goes through the form submit, which runs full validation
 * first and then allows exactly one promotion for that object. The allowance
 * is request-scoped (a static that PHP resets per request) and used once.
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
 * @spec openspec/changes/form-destination-validator/specs/form-destination/spec.md#requirement-an-object-must-be-able-to-carry-the-explicit-lifecycle-status-draft
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Object;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Exception\ValidationException;

/**
 * Set, keep, leave and hide drafts.
 *
 * @spec openspec/changes/form-destination-validator/specs/form-destination/spec.md#requirement-an-object-must-be-able-to-carry-the-explicit-lifecycle-status-draft
 */
class DraftStatusPolicy {

	/**
	 * Object uuids the submit has validated in full and may promote, once.
	 *
	 * @var array<string, true>
	 */
	private static array $promotions = [];

	/**
	 * Allow one promotion out of draft for an object, after full validation.
	 *
	 * @param string $uuid The object.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/form-destination-validator/specs/form-destination/spec.md#requirement-an-object-must-be-able-to-carry-the-explicit-lifecycle-status-draft
	 */
	public static function allowPromotion(string $uuid): void {
		self::$promotions[$uuid] = true;
	}//end allowPromotion()

	/**
	 * Forget every allowance (tests, and a submit that was refused after allowing).
	 *
	 * @return void
	 */
	public static function resetPromotions(): void {
		self::$promotions = [];
	}//end resetPromotions()

	/**
	 * The status a save stores, given what the entity holds and what `@self` asks.
	 *
	 * @param ObjectEntity         $entity   The object being saved (new when it has no id).
	 * @param array<string, mixed> $selfData The `@self` the caller sent.
	 *
	 * @return string|null The status to store; null keeps the date-deduced status.
	 *
	 * @throws ValidationException When the move is not allowed.
	 *
	 * @spec openspec/changes/form-destination-validator/specs/form-destination/spec.md#requirement-an-object-must-be-able-to-carry-the-explicit-lifecycle-status-draft
	 */
	public function resolveStatus(ObjectEntity $entity, array $selfData): ?string {
		$current = $entity->getStatus();
		if (array_key_exists('status', $selfData) === false || $selfData['status'] === null || $selfData['status'] === '') {
			return $current;
		}

		$asked = (string)$selfData['status'];
		if ($asked === $current) {
			return $current;
		}

		if ($asked === ObjectEntity::STATUS_DRAFT) {
			if ($entity->getId() === null) {
				return ObjectEntity::STATUS_DRAFT;
			}

			throw new ValidationException(message: 'A saved object cannot go back to draft.');
		}

		if ($asked === ObjectEntity::STATUS_ACTIVE && $current === ObjectEntity::STATUS_DRAFT) {
			$uuid = (string)$entity->getUuid();
			if (isset(self::$promotions[$uuid]) === true) {
				unset(self::$promotions[$uuid]);

				return ObjectEntity::STATUS_ACTIVE;
			}

			throw new ValidationException(message: 'A draft leaves draft through the form submit, which checks every required field first.');
		}

		throw new ValidationException(message: sprintf('Status "%s" is not one an object can be given here.', $asked));
	}//end resolveStatus()

	/**
	 * Whether a write saves a draft: the payload asks for draft, or the stored object is one and the payload does not leave it.
	 *
	 * @param array<string, mixed> $object   The payload, with `@self` when the caller sent one.
	 * @param ObjectEntity|null    $existing The stored object, null on create.
	 *
	 * @return bool True when the write is a draft write.
	 *
	 * @spec openspec/changes/form-destination-validator/specs/form-destination/spec.md#requirement-an-object-must-be-able-to-carry-the-explicit-lifecycle-status-draft
	 */
	public function isDraftWrite(array $object, ?ObjectEntity $existing): bool {
		$asked = null;
		if (is_array($object['@self'] ?? null) === true && is_string($object['@self']['status'] ?? null) === true) {
			$asked = $object['@self']['status'];
		}

		if ($asked !== null) {
			return $asked === ObjectEntity::STATUS_DRAFT;
		}

		return $existing !== null && $existing->isDraft() === true;
	}//end isDraftWrite()

	/**
	 * The `required` entries a draft is excused from: all of them, list and property-level.
	 *
	 * @param Schema $schema The schema.
	 *
	 * @return array<string, string> Property => reason, in ValidateObject's notSupplied shape.
	 *
	 * @spec openspec/changes/form-destination-validator/specs/form-destination/spec.md#requirement-an-object-must-be-able-to-carry-the-explicit-lifecycle-status-draft
	 */
	public function requiredExcusals(Schema $schema): array {
		$excused = [];
		foreach ($schema->getRequired() as $name) {
			$excused[(string)$name] = ObjectEntity::STATUS_DRAFT;
		}

		foreach ($schema->getProperties() as $name => $property) {
			if (is_array($property) === true && ($property['required'] ?? false) === true) {
				$excused[(string)$name] = ObjectEntity::STATUS_DRAFT;
			}
		}

		return $excused;
	}//end requiredExcusals()

	/**
	 * Whether the schema lets people other than the owner see its drafts.
	 *
	 * @param Schema $schema The schema.
	 *
	 * @return bool True when its configuration sets `draftsVisible: true`.
	 *
	 * @spec openspec/changes/form-destination-validator/specs/form-destination/spec.md#requirement-an-object-must-be-able-to-carry-the-explicit-lifecycle-status-draft
	 */
	public function draftsVisibleToOthers(Schema $schema): bool {
		return (($schema->getConfiguration() ?? [])['draftsVisible'] ?? false) === true;
	}//end draftsVisibleToOthers()

	/**
	 * The SQL clause true for rows that are not drafts, for both list emitters.
	 *
	 * @param string $columnPrefix The table alias with its dot, or empty.
	 *
	 * @return string The predicate.
	 *
	 * @spec openspec/changes/form-destination-validator/specs/form-destination/spec.md#requirement-an-object-must-be-able-to-carry-the-explicit-lifecycle-status-draft
	 */
	public function notADraftSql(string $columnPrefix): string {
		$column = $columnPrefix . '_status';

		return '(' . $column . ' IS NULL OR ' . $column . " <> '" . ObjectEntity::STATUS_DRAFT . "')";
	}//end notADraftSql()
}//end class
