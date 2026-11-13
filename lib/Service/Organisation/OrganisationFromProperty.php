<?php

/**
 * OrganisationFromProperty: an object's organisation taken from one of its own values.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Organisation
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/object-organisation-from-a-property/specs/saas-multi-tenant/spec.md#requirement-a-schema-can-derive-an-objects-organisation-from-a-property-req-oop-001
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Organisation;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Exception\OrganisationFromPropertyException;
use OCA\OpenRegister\Service\OrganisationServiceInterface;
use OCP\IGroupManager;
use OCP\IUserSession;
use Throwable;

/**
 * Sets `@self.organisation` from the property the schema names.
 *
 * For a schema that declares `x-openregister-organisation`, the unit a record
 * NAMES is the unit its rights follow. There is deliberately no fallback to
 * the caller's active organisation: that fallback is exactly the disagreement
 * this change removes, so a value that cannot be honoured is refused instead
 * of quietly scoped somewhere else.
 *
 * An empty property is filled with the organisation the save would stamp
 * anyway, so the property and `@self.organisation` are equal after every save
 * from the very first one.
 *
 * @spec openspec/changes/object-organisation-from-a-property/specs/saas-multi-tenant/spec.md#requirement-a-schema-can-derive-an-objects-organisation-from-a-property-req-oop-001
 */
class OrganisationFromProperty {

	/**
	 * Constructor.
	 *
	 * @param OrganisationServiceInterface $organisations Resolves an organisation and answers membership.
	 * @param IUserSession                 $userSession   The caller.
	 * @param IGroupManager|null           $groupManager  Answers whether the caller is an administrator.
	 */
	public function __construct(
		private readonly OrganisationServiceInterface $organisations,
		private readonly IUserSession $userSession,
		private readonly ?IGroupManager $groupManager = null,
	) {
	}//end __construct()

	/**
	 * Apply the schema's declaration to an object being saved.
	 *
	 * @param ObjectEntity         $object   The object being created or updated, its data already set.
	 * @param Schema|null          $schema   The object's schema.
	 * @param array<string, mixed> $selfData The `@self` block as the client sent it.
	 *
	 * @return void
	 *
	 * @throws OrganisationFromPropertyException When the property contradicts `@self`, names no organisation,
	 *                                           or names one the caller is not a member of.
	 *
	 * @spec openspec/changes/object-organisation-from-a-property/specs/saas-multi-tenant/spec.md#requirement-a-schema-can-derive-an-objects-organisation-from-a-property-req-oop-001
	 */
	public function apply(ObjectEntity $object, ?Schema $schema, array $selfData): void {
		$property = OrganisationAnnotation::propertyOf(schema: $schema);
		if ($property === null) {
			return;
		}

		$named = self::organisationIn(value: ($object->getObject()[$property] ?? null));
		if ($named === null) {
			$this->fillEmptyProperty(object: $object, property: $property);
			return;
		}

		$claimed = ($selfData['organisation'] ?? null);
		if (is_string($claimed) === true && $claimed !== '' && $claimed !== $named) {
			throw OrganisationFromPropertyException::contradiction(
				property: $property,
				organisation: $named,
				selfSubmission: $claimed
			);
		}

		if ($this->organisations->findContextOrganisation($named) === null) {
			throw OrganisationFromPropertyException::unknown(property: $property, organisation: $named);
		}

		if ($this->mayActFor(organisation: $named) === false) {
			throw OrganisationFromPropertyException::notAMember(property: $property, organisation: $named);
		}

		$object->setOrganisation($named);
	}//end apply()

	/**
	 * The organisation an object's property names, or null when it names none.
	 *
	 * @param ObjectEntity $object   The stored object.
	 * @param string       $property The property the schema names.
	 *
	 * @return string|null The organisation uuid, or null.
	 *
	 * @spec openspec/changes/object-organisation-from-a-property/specs/saas-multi-tenant/spec.md#requirement-existing-disagreements-are-reported-and-reconciled-req-oop-002
	 */
	public static function named(ObjectEntity $object, string $property): ?string {
		return self::organisationIn(value: ($object->getObject()[$property] ?? null));
	}//end named()

	/**
	 * The organisation a stored object's property names when it is not the one its rights follow.
	 *
	 * @param ObjectEntity $object   The stored object.
	 * @param string       $property The property the schema names.
	 *
	 * @return string|null The organisation the property names, or null when the two already agree.
	 *
	 * @spec openspec/changes/object-organisation-from-a-property/specs/saas-multi-tenant/spec.md#requirement-existing-disagreements-are-reported-and-reconciled-req-oop-002
	 */
	public static function disagreement(ObjectEntity $object, string $property): ?string {
		$named = self::named(object: $object, property: $property);
		if ($named === null || $named === $object->getOrganisation()) {
			return null;
		}

		return $named;
	}//end disagreement()

	/**
	 * Write the organisation the save stamps into an empty property, so the two agree.
	 *
	 * @param ObjectEntity $object   The object being saved.
	 * @param string       $property The property the schema names.
	 *
	 * @return void
	 */
	private function fillEmptyProperty(ObjectEntity $object, string $property): void {
		$stamped = $object->getOrganisation();
		if ($stamped === null || $stamped === '') {
			$stamped = $this->organisations->getOrganisationForNewEntity();
		}

		if ($stamped === null || $stamped === '') {
			// Nothing would be stamped either, so there is nothing to agree with.
			return;
		}

		$data = $object->getObject();
		if (($data['id'] ?? null) === $object->getUuid()) {
			// The uuid getObject() injects as `id`: writing it back would store a key the object never had.
			unset($data['id']);
		}

		$data[$property] = $stamped;
		$object->setObject($data);
		$object->setOrganisation($stamped);
	}//end fillEmptyProperty()

	/**
	 * Whether the caller may give an object to this organisation.
	 *
	 * An administrator may; anyone else only an organisation they are a member
	 * of. A membership question that cannot be answered counts as "not a
	 * member", so the write is refused rather than scoped on a guess.
	 *
	 * @param string $organisation The organisation uuid.
	 *
	 * @return bool True when the caller may.
	 */
	private function mayActFor(string $organisation): bool {
		if ($this->callerIsAdmin() === true) {
			return true;
		}

		try {
			return $this->organisations->hasAccessToOrganisation($organisation);
		} catch (Throwable $e) {
			return false;
		}
	}//end mayActFor()

	/**
	 * Whether the caller is a Nextcloud administrator.
	 *
	 * @return bool True when they are.
	 */
	private function callerIsAdmin(): bool {
		if ($this->groupManager === null) {
			return false;
		}

		try {
			$user = $this->userSession->getUser();
			if ($user === null) {
				return false;
			}

			return $this->groupManager->isAdmin($user->getUID());
		} catch (Throwable $e) {
			return false;
		}
	}//end callerIsAdmin()

	/**
	 * The organisation uuid a property value names: the value, or a reference's `uuid` or `id`.
	 *
	 * @param mixed $value The property's value.
	 *
	 * @return string|null The uuid, or null when the value names none.
	 */
	private static function organisationIn(mixed $value): ?string {
		if (is_object($value) === true) {
			$value = (array)$value;
		}

		if (is_array($value) === true) {
			$value = (($value['uuid'] ?? null) ?? ($value['id'] ?? null));
			if (is_object($value) === true || is_array($value) === true) {
				return null;
			}
		}

		if (is_string($value) === false || trim($value) === '') {
			return null;
		}

		return trim($value);
	}//end organisationIn()
}//end class
