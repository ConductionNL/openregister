<?php

/**
 * PersonRegisterSource
 *
 * The configured persons register and place register a person detection is matched against (REQ-DKP-003).
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Anonymisation
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/detection-knows-the-person/tasks.md#task-3.1
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Anonymisation;

use OCA\OpenRegister\Exception\PersonRegisterPurposeMissingException;

/**
 * Normalises the `anonymisation.personRegister`, `anonymisation.personRegisterBoost`
 * and `anonymisation.placeRegister` settings.
 *
 * A persons register is an OpenRegister schema with a name property, or the
 * Nextcloud contacts of a named address book, and always carries the purpose
 * the lookup is made for. A place register is an OpenRegister schema with a
 * name property. A value of another shape is no register.
 *
 * @spec openspec/changes/detection-knows-the-person/specs/person-aware-detection/spec.md#requirement-a-person-detection-is-checked-against-the-organisations-own-registers-before-it-is-proposed-req-dkp-003
 */
final class PersonRegisterSource {

	/**
	 * A source that is an OpenRegister schema.
	 *
	 * @var string
	 */
	public const SCHEMA = 'schema';

	/**
	 * A source that is a Nextcloud address book.
	 *
	 * @var string
	 */
	public const CONTACTS = 'contacts';

	/**
	 * The confidence a match adds when no boost is configured.
	 *
	 * @var float
	 */
	public const DEFAULT_BOOST = 0.2;

	/**
	 * The highest confidence a boost can raise a detection to.
	 *
	 * @var float
	 */
	public const MAX_CONFIDENCE = 0.99;

	/**
	 * The persons register as stored, or null when none is configured.
	 *
	 * A stored value without a purpose is no register: a lookup is only made
	 * for a declared purpose.
	 *
	 * @param mixed $value The stored value.
	 *
	 * @return array<string, string>|null
	 *
	 * @spec openspec/changes/detection-knows-the-person/tasks.md#task-3.1
	 */
	public static function personRegister(mixed $value): ?array {
		$source = self::source(value: $value, contacts: true);
		if ($source === null) {
			return null;
		}

		$purpose = trim((string) ($value['purpose'] ?? ''));
		if ($purpose === '') {
			return null;
		}

		return $source + ['purpose' => $purpose];
	}//end personRegister()

	/**
	 * The persons register of a save; refuses a source without a purpose.
	 *
	 * @param mixed $value The submitted value.
	 *
	 * @return array<string, string>|null Null when the save names no source.
	 *
	 * @throws PersonRegisterPurposeMissingException When a source is named without a purpose.
	 *
	 * @spec openspec/changes/detection-knows-the-person/tasks.md#task-3.1
	 */
	public static function submittedPersonRegister(mixed $value): ?array {
		$source = self::source(value: $value, contacts: true);
		if ($source === null) {
			return null;
		}

		$register = self::personRegister(value: $value);
		if ($register === null) {
			throw new PersonRegisterPurposeMissingException(
				message: 'anonymisation.personRegister needs a purpose: the persons register is only read for a declared purpose'
			);
		}

		return $register;
	}//end submittedPersonRegister()

	/**
	 * The place register as stored, or null when none is configured.
	 *
	 * @param mixed $value The stored value.
	 *
	 * @return array<string, string>|null
	 *
	 * @spec openspec/changes/detection-knows-the-person/tasks.md#task-3.1
	 */
	public static function placeRegister(mixed $value): ?array {
		return self::source(value: $value, contacts: false);
	}//end placeRegister()

	/**
	 * The boost a persons register match adds: a number above 0 and at most 1, else the default.
	 *
	 * @param mixed $value The stored value.
	 *
	 * @return float
	 *
	 * @spec openspec/changes/detection-knows-the-person/tasks.md#task-3.1
	 */
	public static function boost(mixed $value): float {
		if (is_int($value) === false && is_float($value) === false && (is_string($value) === false || is_numeric($value) === false)) {
			return self::DEFAULT_BOOST;
		}

		$boost = (float) $value;
		if ($boost <= 0.0 || $boost > 1.0) {
			return self::DEFAULT_BOOST;
		}

		return $boost;
	}//end boost()

	/**
	 * A label naming a source, as recorded on a matched relation: no record id or value.
	 *
	 * @param array<string, string> $source A normalised source.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/detection-knows-the-person/tasks.md#task-3.2
	 */
	public static function label(array $source): string {
		if (($source['type'] ?? null) === self::CONTACTS) {
			return self::CONTACTS.':'.$source['addressBook'];
		}

		return self::SCHEMA.':'.$source['register'].'/'.$source['schema'];
	}//end label()

	/**
	 * The source part of a register: a schema with a name property, or an address book.
	 *
	 * @param mixed   $value    The value.
	 * @param boolean $contacts Whether an address book is an accepted source.
	 *
	 * @return array<string, string>|null
	 */
	private static function source(mixed $value, bool $contacts): ?array {
		if (is_array($value) === false) {
			return null;
		}

		$type = $value['type'] ?? null;
		if ($type === self::CONTACTS && $contacts === true) {
			$addressBook = trim((string) ($value['addressBook'] ?? ''));
			if ($addressBook === '') {
				return null;
			}

			return ['type' => self::CONTACTS, 'addressBook' => $addressBook];
		}

		if ($type !== self::SCHEMA) {
			return null;
		}

		$register = trim((string) ($value['register'] ?? ''));
		$schema = trim((string) ($value['schema'] ?? ''));
		$nameProperty = trim((string) ($value['nameProperty'] ?? ''));
		if ($register === '' || $schema === '') {
			return null;
		}

		if ($nameProperty === '') {
			$nameProperty = 'name';
		}

		return ['type' => self::SCHEMA, 'register' => $register, 'schema' => $schema, 'nameProperty' => $nameProperty];
	}//end source()
}//end class
