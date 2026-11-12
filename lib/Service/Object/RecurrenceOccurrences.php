<?php

/**
 * OpenRegister RecurrenceOccurrences
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Object
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/add-openregister-discovered-capabilities/specs/extended-field-types/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Object;

use OCA\OpenRegister\Formats\RecurrenceWindow;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Formats\ExtendedFieldTypeValidatorInterface;
use OCA\OpenRegister\Formats\FormatFactory;
use OCP\IRequest;

/**
 * Puts the next occurrences of a recurrence property on the rendered object.
 *
 * The RRULE itself stays unchanged. With one recurrence property the list is
 * `_occurrences`; with several, each is `<prop>_occurrences`.
 *
 * @spec openspec/changes/add-openregister-discovered-capabilities/specs/extended-field-types/spec.md
 */
final class RecurrenceOccurrences {

	/**
	 * Constructor.
	 *
	 * @param IRequest|null $request The request, for `?recurrenceOccurrences=N`.
	 *
	 * @spec openspec/changes/add-openregister-discovered-capabilities/specs/extended-field-types/spec.md
	 */
	public function __construct(private readonly ?IRequest $request) {
	}//end __construct()

	/**
	 * The object data with its occurrence lists.
	 *
	 * @param array  $objectData The object data being rendered.
	 * @param Schema $schema     The schema for the object.
	 *
	 * @return array The object data, possibly enriched.
	 *
	 * @spec openspec/changes/add-openregister-discovered-capabilities/specs/extended-field-types/spec.md
	 */
	public function enrich(array $objectData, Schema $schema): array {
		$rules = self::rules(objectData: $objectData, schema: $schema);
		if ($rules === []) {
			return $objectData;
		}

		$count = $this->requestedCount();
		$validator = FormatFactory::extendedFieldTypes();
		foreach ($rules as $propertyName => $rrule) {
			$key = self::occurrencesKey(propertyName: $propertyName, ruleCount: count($rules));
			$objectData[$key] = $validator->occurrencesIn(window: new RecurrenceWindow(rrule: $rrule, count: $count));
		}

		return $objectData;
	}//end enrich()

	/**
	 * The key of an occurrence list: plain for a lone rule, named by property otherwise.
	 *
	 * @param string $propertyName The recurrence property.
	 * @param int    $ruleCount    The number of rules on the object.
	 *
	 * @return string The key.
	 *
	 * @spec openspec/changes/add-openregister-discovered-capabilities/specs/extended-field-types/spec.md
	 */
	private static function occurrencesKey(string $propertyName, int $ruleCount): string {
		if ($ruleCount === 1) {
			return '_occurrences';
		}

		return $propertyName . '_occurrences';
	}//end occurrencesKey()

	/**
	 * The recurrence properties present with a string value.
	 *
	 * @param array  $objectData The object data.
	 * @param Schema $schema     The schema.
	 *
	 * @return array<string, string> The RRULEs by property.
	 *
	 * @spec openspec/changes/add-openregister-discovered-capabilities/specs/extended-field-types/spec.md
	 */
	private static function rules(array $objectData, Schema $schema): array {
		$rules = [];
		foreach (($schema->getProperties() ?? []) as $propertyName => $propertyConfig) {
			$value = ($objectData[$propertyName] ?? null);
			if (($propertyConfig['type'] ?? null) === 'recurrence' && is_string($value) === true && $value !== '') {
				$rules[$propertyName] = $value;
			}
		}

		return $rules;
	}//end rules()

	/**
	 * The requested number of occurrences; the validator clamps it.
	 *
	 * @return int The occurrence count.
	 *
	 * @spec openspec/changes/add-openregister-discovered-capabilities/specs/extended-field-types/spec.md
	 */
	private function requestedCount(): int {
		$raw = $this->request?->getParam('recurrenceOccurrences', null);
		if (is_numeric($raw) === false) {
			return ExtendedFieldTypeValidatorInterface::DEFAULT_OCCURRENCES;
		}

		return (int)$raw;
	}//end requestedCount()
}//end class
