<?php

/**
 * OpenRegister RuleDescriptorJson
 *
 * The inventory entry a rule descriptor serialises to.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Rules
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/flow-engine/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Rules;

/**
 * Shapes a {@see RuleDescriptor}'s fields into the entry the inventory returns.
 *
 * The descriptor is read from all over the engine, so it stays a value and
 * its accessors; the response shape, with its renamed keys and the derived id
 * and order, is built here.
 *
 * @spec openspec/specs/flow-engine/spec.md
 */
final class RuleDescriptorJson {

	/**
	 * The descriptor's fields in response order, each with the key it is returned under.
	 *
	 * @var array<string, string>
	 */
	private const KEYS = [
		'kind' => 'kind',
		'schemaSlug' => 'schema',
		'key' => 'key',
		'label' => 'label',
		'source' => 'source',
		'enabled' => 'enabled',
		'actions' => 'actions',
		'condition' => 'condition',
		'maxObjects' => 'maxObjects',
		'scope' => 'scope',
	];

	/**
	 * The entry as the inventory returns it, before the summary is merged on.
	 *
	 * @param array<string, mixed> $fields The descriptor's fields, keyed by property name.
	 *
	 * @return array<string, mixed> The entry: the id, the fields, and the kind's evaluation order.
	 *
	 * @spec openspec/specs/flow-engine/spec.md
	 */
	public function serialize(array $fields): array {
		$entry = [
			'id' => RuleDescriptor::idFor(kind: $fields['kind'], schemaSlug: $fields['schemaSlug'], key: $fields['key']),
		];
		foreach (self::KEYS as $field => $key) {
			$entry[$key] = $fields[$field];
		}

		$entry['order'] = (new RuleVocabulary())->orderOf(kind: $fields['kind']);

		return $entry;
	}//end serialize()
}//end class
