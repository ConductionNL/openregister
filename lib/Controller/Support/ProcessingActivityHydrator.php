<?php

/**
 * Writes a request payload onto a processing activity.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller\Support
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/verwerkingsregister-api/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Controller\Support;

use OCA\OpenRegister\Db\Verwerkingsactiviteit;

/**
 * Hydrate (or update) a Verwerkingsactiviteit from a request payload.
 *
 * Only fields present in the payload are written. A string field is cast to
 * string (null stays null); an array field takes the value when it is an
 * array and null otherwise.
 *
 * @spec openspec/specs/verwerkingsregister-api/spec.md
 */
final class ProcessingActivityHydrator {

	/**
	 * Payload key to setter, for the string fields.
	 *
	 * @var array<string, string>
	 */
	private const STRING_FIELDS = [
		'code' => 'setCode',
		'name' => 'setName',
		'description' => 'setDescription',
		'purpose' => 'setPurpose',
		'legalBasis' => 'setLegalBasis',
		'retentionPeriod' => 'setRetentionPeriod',
		'technicalMeasures' => 'setTechnicalMeasures',
		'organisationalMeasures' => 'setOrganisationalMeasures',
		'organisationId' => 'setOrganisationId',
		'status' => 'setStatus',
	];

	/**
	 * Payload key to setter, for the array fields.
	 *
	 * @var array<string, string>
	 */
	private const ARRAY_FIELDS = [
		'dataSubjectCategories' => 'setDataSubjectCategories',
		'personalDataCategories' => 'setPersonalDataCategories',
		'recipients' => 'setRecipients',
		'internationalTransfers' => 'setInternationalTransfers',
		'controller' => 'setController',
		'dpoContactDetails' => 'setDpoContactDetails',
	];

	/**
	 * Write the payload onto the entity.
	 *
	 * @param Verwerkingsactiviteit $entity  Fresh or existing entity.
	 * @param array                 $payload Request body / query params.
	 *
	 * @return Verwerkingsactiviteit Hydrated entity (NOT yet persisted).
	 *
	 * @spec openspec/specs/verwerkingsregister-api/spec.md
	 */
	public function hydrate(Verwerkingsactiviteit $entity, array $payload): Verwerkingsactiviteit {
		foreach (self::STRING_FIELDS as $field => $setter) {
			if (array_key_exists($field, $payload) === true) {
				$entity->{$setter}($this->stringValue(value: $payload[$field]));
			}
		}

		foreach (self::ARRAY_FIELDS as $field => $setter) {
			if (array_key_exists($field, $payload) === true) {
				$entity->{$setter}($this->arrayValue(value: $payload[$field]));
			}
		}

		return $entity;
	}//end hydrate()

	/**
	 * A string field's value: null stays null, anything else is cast.
	 *
	 * @param mixed $value The payload value.
	 *
	 * @return string|null The value to write.
	 *
	 * @spec openspec/specs/verwerkingsregister-api/spec.md
	 */
	private function stringValue(mixed $value): ?string {
		if ($value === null) {
			return null;
		}

		return (string)$value;
	}//end stringValue()

	/**
	 * An array field's value: the array, or null for anything else.
	 *
	 * @param mixed $value The payload value.
	 *
	 * @return array|null The value to write.
	 *
	 * @spec openspec/specs/verwerkingsregister-api/spec.md
	 */
	private function arrayValue(mixed $value): ?array {
		if (is_array($value) === true) {
			return $value;
		}

		return null;
	}//end arrayValue()
}//end class
