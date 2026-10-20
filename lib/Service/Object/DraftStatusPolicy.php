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
 * Leaving draft goes through the form submit or through the owner's own
 * activation (decision 216, DraftActivation). Both run full validation first
 * and then allow exactly one promotion for that object. The allowance is
 * request-scoped (a static that PHP resets per request) and used once.
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
use OCA\OpenRegister\Exception\DraftAlreadyLeftException;
use OCA\OpenRegister\Exception\ValidationException;
use OCA\OpenRegister\Service\Form\FormDestinationValidator;

/**
 * Set, keep, leave and hide drafts.
 *
 * @SuppressWarnings(PHPMD.TooManyPublicMethods) One rule set (decisions 180 and 216): every save path and both list
 * emitters ask one of these questions, and none of them has a second home to move to.
 *
 * @spec openspec/changes/form-destination-validator/specs/form-destination/spec.md#requirement-an-object-must-be-able-to-carry-the-explicit-lifecycle-status-draft
 */
class DraftStatusPolicy {

	/**
	 * The schema configuration key that holds how many days a draft may go unchanged (decision 216).
	 *
	 * @var string
	 */
	public const LIFETIME_KEY = 'draftLifetimeDays';

	/**
	 * How many days a draft may go unchanged when its schema does not say (decisions 201 and 228).
	 *
	 * @var integer
	 */
	public const DEFAULT_LIFETIME_DAYS = 30;

	/**
	 * Object uuids the submit has validated in full and may promote, once.
	 *
	 * @var array<string, true>
	 */
	private static array $promotions = [];

	/**
	 * Object uuids whose save is moving them out of draft right now.
	 *
	 * Set by the one save that used the allowance and read by the mapper,
	 * which makes the stored status part of its UPDATE (see isLeaving()).
	 *
	 * @var array<string, true>
	 */
	private static array $leaving = [];

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
	 * Whether the save that followed allowPromotion() used the allowance for an object.
	 *
	 * A save that leaves draft consumes the allowance. One that did not (the
	 * stored object had already left draft, because a parallel request won)
	 * leaves it standing, and the caller must not announce a creation again.
	 *
	 * @param string $uuid The object.
	 *
	 * @return bool True when no allowance for the object remains.
	 *
	 * @spec openspec/changes/draft-creation-side-effects/specs/object-events/spec.md#requirement-creation-side-effects-must-be-held-for-a-draft-and-run-once-when-it-leaves-draft
	 */
	public static function promotionWasUsed(string $uuid): bool {
		return isset(self::$promotions[$uuid]) === false;
	}//end promotionWasUsed()

	/**
	 * Forget every allowance (tests, and a submit that was refused after allowing).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/form-destination-validator/specs/form-destination/spec.md#requirement-an-object-must-be-able-to-carry-the-explicit-lifecycle-status-draft
	 */
	public static function resetPromotions(): void {
		self::$promotions = [];
		self::$leaving = [];
	}//end resetPromotions()

	/**
	 * Whether the save in progress moves this object out of draft.
	 *
	 * The allowance is request-scoped, so two requests on one draft can both
	 * pass the read that says it is still a draft and both use their own
	 * allowance. The mapper asks here and, when the answer is yes, writes the
	 * status change as a compare-and-swap (`... AND _status = 'draft'`): the
	 * database lets exactly one of them change a row, and the other finds
	 * nothing to change and is refused as DraftAlreadyLeftException, so only
	 * one request announces the creation.
	 *
	 * @param string $uuid The object.
	 *
	 * @return bool True while a save that leaves draft is writing it.
	 *
	 * @spec openspec/changes/draft-creation-side-effects/specs/object-events/spec.md#requirement-creation-side-effects-must-be-held-for-a-draft-and-run-once-when-it-leaves-draft
	 */
	public static function isLeaving(string $uuid): bool {
		return isset(self::$leaving[$uuid]) === true;
	}//end isLeaving()

	/**
	 * Whether this write is the one that moves the object out of draft, and forget it.
	 *
	 * The first write after the status resolved to leave draft carries the
	 * status change, so only that write is conditional. A later write of the
	 * same object in the same request (the file write of a file-bearing save,
	 * an automatic transition, a created-event listener) finds the row already
	 * active and must not be refused for it.
	 *
	 * @param string $uuid The object.
	 *
	 * @return bool True for the write that leaves draft.
	 *
	 * @spec openspec/changes/draft-creation-side-effects/specs/object-events/spec.md#requirement-creation-side-effects-must-be-held-for-a-draft-and-run-once-when-it-leaves-draft
	 */
	public static function consumeLeaving(string $uuid): bool {
		$leaving = isset(self::$leaving[$uuid]) === true;
		unset(self::$leaving[$uuid]);

		return $leaving;
	}//end consumeLeaving()

	/**
	 * The status a save stores, given what the entity holds and what `@self` asks.
	 *
	 * @param ObjectEntity         $entity   The object being saved (new when it has no id).
	 * @param array<string, mixed> $selfData The `@self` the caller sent.
	 *
	 * @return string|null The status to store; null keeps the date-deduced status.
	 *
	 * @throws ValidationException When the move is not allowed, or (DraftAlreadyLeftException) the draft to promote already left draft.
	 *
	 * @spec openspec/changes/form-destination-validator/specs/form-destination/spec.md#requirement-an-object-must-be-able-to-carry-the-explicit-lifecycle-status-draft
	 */
	public function resolveStatus(ObjectEntity $entity, array $selfData): ?string {
		$current = $entity->getStatus();

		// An allowance for an object that is no longer a draft means a parallel
		// request sent it first. This save would be an ordinary update that
		// announces the creation a second time, so it is refused.
		if ($entity->getId() !== null
			&& $current !== ObjectEntity::STATUS_DRAFT
			&& isset(self::$promotions[(string)$entity->getUuid()]) === true
		) {
			throw new DraftAlreadyLeftException(message: 'This draft has already left draft.');
		}

		if (array_key_exists('status', $selfData) === false || $selfData['status'] === null || $selfData['status'] === '') {
			return $current;
		}

		$asked = (string)$selfData['status'];
		if ($asked === $current) {
			return $current;
		}

		return $this->resolveMove(entity: $entity, asked: $asked);
		}//end resolveStatus()

	/**
	 * The status a requested move lands on, or a refusal.
	 *
	 * @param ObjectEntity $entity The object being saved.
	 * @param string       $asked  The status asked for, different from the stored one.
	 *
	 * @return string The status to store.
	 *
	 * @throws ValidationException When the move is not allowed.
	 */
	private function resolveMove(ObjectEntity $entity, string $asked): string {
		if ($asked === ObjectEntity::STATUS_DRAFT) {
			if ($entity->getId() === null) {
				return ObjectEntity::STATUS_DRAFT;
			}

			throw new ValidationException(message: 'A saved object cannot go back to draft.');
		}

		if ($asked === ObjectEntity::STATUS_ACTIVE && $entity->isDraft() === true) {
			$uuid = (string)$entity->getUuid();
			if (isset(self::$promotions[$uuid]) === true) {
				unset(self::$promotions[$uuid]);
				self::$leaving[$uuid] = true;

				return ObjectEntity::STATUS_ACTIVE;
			}

			throw new ValidationException(
				message: 'A draft leaves draft through the form submit or its owner\'s activate action, which check every required field first.'
			);
		}

		throw new ValidationException(message: sprintf('Status "%s" is not one an object can be given here.', $asked));
	}//end resolveMove()

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
	 * The properties the server fills, excused from `required` while a payload is judged in full.
	 *
	 * A property with a default, a computation, a generated identifier
	 * (`x-openregister-generated`) or the serverSet marker is filled on save or
	 * by a creating listener, so a payload without it is complete. The form submit and the owner's activation excuse the same
	 * set, so a draft that one accepts the other accepts too.
	 *
	 * @param Schema               $schema The schema.
	 * @param array<string, mixed> $object The object as it will be saved.
	 *
	 * @return array<string, string> Property => reason, in ValidateObject's notSupplied shape.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) FormDestinationValidator::isServerFilled() reads a schema property; there is no instance to inject.
	 *
	 * @spec openspec/changes/draft-owner-activation-and-lifetime/specs/form-destination/spec.md#requirement-a-drafts-owner-must-be-able-to-make-it-definitive-through-the-object-api
	 */
	public function serverFilledExcusals(Schema $schema, array $object): array {
		$excused = [];
		foreach ($schema->getProperties() as $name => $property) {
			if (is_array($property) === false || array_key_exists((string)$name, $object) === true) {
				continue;
			}

			if (FormDestinationValidator::isServerFilled(property: $property) === true) {
				$excused[(string)$name] = 'server-set';
			}
		}

		return $excused;
	}//end serverFilledExcusals()

	/**
	 * How many days a draft of this schema may go unchanged before the cleanup removes it.
	 *
	 * Thirty days unless the schema says otherwise (decision 228). The
	 * schema's configuration sets another whole number of days, or `false`
	 * to keep its drafts. A stored value that is neither (left by hand in
	 * the database, or by an older version) keeps the drafts too: nothing is
	 * removed on a value nobody can read.
	 *
	 * @param Schema $schema The schema.
	 *
	 * @return int|null The days, or null when the schema keeps its drafts.
	 *
	 * @spec openspec/changes/draft-owner-activation-and-lifetime/specs/form-destination/spec.md#requirement-a-draft-lifetime-must-default-to-thirty-days-and-a-schema-must-be-able-to-change-it-or-opt-out
	 */
	public function draftLifetimeDays(Schema $schema): ?int {
		$configuration = ($schema->getConfiguration() ?? []);
		if (array_key_exists(self::LIFETIME_KEY, $configuration) === false || $configuration[self::LIFETIME_KEY] === null) {
			return self::DEFAULT_LIFETIME_DAYS;
		}

		$days = $configuration[self::LIFETIME_KEY];
		if (is_int($days) === false || $days < 1) {
			return null;
		}

		return $days;
	}//end draftLifetimeDays()

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
	 * Whether a stored draft is kept from a caller: it is a draft, its schema keeps drafts to their owner, and the caller is not that owner.
	 *
	 * The read rule (MagicRbacHandler) already hides such a draft. A write
	 * that looks its target up past that rule asks here, so somebody who
	 * cannot read a draft cannot change it either.
	 *
	 * @param ObjectEntity $object The stored object.
	 * @param Schema       $schema Its schema.
	 * @param string|null  $userId The caller, null when nobody is signed in.
	 *
	 * @return bool True when the caller may not touch it.
	 *
	 * @spec openspec/changes/draft-owner-activation-and-lifetime/specs/form-destination/spec.md#requirement-a-draft-must-not-be-changed-by-somebody-who-cannot-read-it
	 */
	public function keptFrom(ObjectEntity $object, Schema $schema, ?string $userId): bool {
		return $object->isDraft() === true
			&& $this->draftsVisibleToOthers(schema: $schema) === false
			&& $object->getOwner() !== $userId;
	}//end keptFrom()

	/**
	 * Whether the schema's lists show the caller their own drafts by default.
	 *
	 * True unless the schema's configuration sets `draftsListed: false`. A
	 * schema whose drafts live on an entry screen of their own (a quick log
	 * that offers its draft back) sets it, so a half-typed entry never sits
	 * in the caller's work list, its total or its facets (decision 216).
	 *
	 * @param Schema $schema The schema.
	 *
	 * @return bool False when the schema keeps the caller's own drafts out of its lists.
	 *
	 * @spec openspec/changes/draft-owner-activation-and-lifetime/specs/form-destination/spec.md#requirement-a-schema-must-be-able-to-keep-the-callers-own-drafts-out-of-its-lists
	 */
	public function ownDraftsListed(Schema $schema): bool {
		return (($schema->getConfiguration() ?? [])['draftsListed'] ?? true) !== false;
	}//end ownDraftsListed()

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
