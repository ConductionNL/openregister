<?php

/**
 * The options a filtered reference property may offer.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Schemas
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/fields-a-user-adds-and-choices-a-record-narrows/specs/schema-vocabulaire/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Schemas;

use OCA\OpenRegister\Db\Schema;

/**
 * The read behind a filtered reference picker.
 *
 * 🔑 IT CALLS THE SAME `resolve()` THE SAVE PATH CALLS, and that is the whole
 * reason this class is thin. A picker that offers one set while the save path
 * accepts another is two evaluators of one rule: the user picks something the
 * form offered and the server refuses it, or worse, the form offers something
 * the server then accepts and should not have.
 *
 * 🔴 NO OPTIONS IS NOT EVERY OPTION. When an operand the filter depends on has
 * no value yet, this returns an EMPTY list and names the property it is waiting
 * for. Returning the unfiltered set instead would be the same defect in the
 * direction that discloses: the picker would show every contact in the register
 * to somebody who had not yet chosen an organisation, and each of those is a
 * value they were never meant to browse.
 *
 * @spec openspec/changes/fields-a-user-adds-and-choices-a-record-narrows/specs/schema-vocabulaire/spec.md
 */
class ReferenceOptionsReader {

	/**
	 * The default page size.
	 */
	public const DEFAULT_LIMIT = 50;

	/**
	 * The largest page this endpoint will hand back.
	 *
	 * A picker reads a page at a time, and an unbounded limit turns a picker
	 * into a bulk export of the referenced register with a different name on it.
	 */
	public const MAX_LIMIT = 200;

	/**
	 * What the reader answers for one property.
	 *
	 * @param Schema               $schema   The schema of the record being edited.
	 * @param string               $property The reference property.
	 * @param array<string, mixed> $record   The record being edited, saved or draft.
	 *
	 * @return array{filtered: bool, needs: array<int, string>, filter: array<string, mixed>, target: array{schema: ?string, register: ?string}} The plan.
	 *
	 * @throws ReferenceFilterException When the declaration cannot be honoured.
	 */
	public function plan(Schema $schema, string $property, array $record): array {
		$properties = ($schema->getProperties() ?? []);
		$config     = ($properties[$property] ?? null);

		if (is_array($config) === false) {
			throw new ReferenceFilterException(
				sprintf('There is no property \'%s\' on this schema to read options for.', $property)
			);
		}

		$target = [
			'schema'   => $this->targetSchema(config: $config),
			'register' => ($config['register'] ?? null),
		];

		$declaration = ReferenceFilterDeclaration::fromProperty(property: $config, path: $property);

		if ($declaration === null) {
			// No filter declared: every option the caller may read is on offer,
			// which is what an unfiltered reference has always meant.
			return [
				'filtered' => false,
				'needs'    => [],
				'filter'   => [],
				'target'   => $target,
			];
		}

		$answer = $declaration->resolve(record: $record);

		return [
			'filtered' => true,
			'needs'    => $answer['needs'],
			'filter'   => $answer['filter'],
			'target'   => $target,
		];
	}//end plan()

	/**
	 * Whether the plan can be turned into a list at all.
	 *
	 * @param array<string, mixed> $plan The plan.
	 *
	 * @return bool Whether options may be listed.
	 */
	public function isAnswerable(array $plan): bool {
		return (($plan['needs'] ?? []) === []);
	}//end isAnswerable()

	/**
	 * The page size to use, clamped.
	 *
	 * 🔑 A LIMIT OF ZERO IS NOT UNLIMITED. `_limit=0` reaching a query builder
	 * produces `LIMIT 0`, an empty page with an HTTP 200 and no explanation,
	 * which is the same failure `QueryLimit::normalise()` was written for. Here
	 * it means "the default", because a picker asking for nothing is a picker
	 * that did not say.
	 *
	 * @param mixed $requested What the caller asked for.
	 *
	 * @return int The page size.
	 */
	public function limitFor(mixed $requested): int {
		if (is_numeric($requested) === false) {
			return self::DEFAULT_LIMIT;
		}

		$limit = (int)$requested;
		if ($limit < 1) {
			return self::DEFAULT_LIMIT;
		}

		return min($limit, self::MAX_LIMIT);
	}//end limitFor()

	/**
	 * The query the options read runs, given a resolved plan.
	 *
	 * The filter goes in as ordinary object-query keys, so the search path
	 * applies the caller's own row access to it. Nothing here bypasses RBAC,
	 * and nothing here re-implements it.
	 *
	 * @param array<string, mixed> $plan   The plan.
	 * @param int                  $limit  The page size.
	 * @param int                  $offset Where the page starts.
	 *
	 * @return array<string, mixed> The query.
	 */
	public function queryFor(array $plan, int $limit, int $offset): array {
		$query = ($plan['filter'] ?? []);

		$query['_limit']  = $limit;
		$query['_offset'] = max(0, $offset);

		return $query;
	}//end queryFor()

	/**
	 * The schema a reference property points at.
	 *
	 * @param array<string, mixed> $config The property configuration.
	 *
	 * @return string|null The reference, or null when the property names none.
	 */
	private function targetSchema(array $config): ?string {
		foreach (['$ref', 'schema'] as $key) {
			$value = ($config[$key] ?? null);
			if (is_string($value) === true && $value !== '') {
				return $value;
			}
		}

		// An array of references points at its item shape.
		$items = ($config['items'] ?? null);
		if (is_array($items) === true) {
			return $this->targetSchema(config: $items);
		}

		return null;
	}//end targetSchema()
}//end class
