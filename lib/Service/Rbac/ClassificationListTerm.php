<?php

/**
 * ClassificationListTerm: the labels a list query may return to one caller, as a match block.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Rbac
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Rbac;

use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper\SchemaAnnotation;

/**
 * The list-path half of a schema's `x-openregister-classification` block.
 *
 * The find path ({@see ClassificationReadGate}) refuses one object; the list
 * path cannot refuse rows after the query, because the page size and the
 * total would then be wrong. So the same two refusals are expressed as the
 * labels a caller may still see, which the list query compiles into an `IN`
 * over the declared levels. That needs no ordinal column and no migration.
 *
 * - A read admits the labels at or below the caller's clearance.
 * - A ceiling on the `public` scope removes, for an anonymous caller and on
 *   every action, the labels at or above the ceiling.
 *
 * An absent, empty or unknown label is not a declared level, so it is never
 * in the list: such a row is visible only to a caller who needs no term at
 * all, which is a caller cleared to the top with no ceiling in the way.
 *
 * Administrators never reach this class; the list emitters let them through
 * before any term is built, which is the existing administrator bypass.
 *
 * @spec openspec/changes/classification-clearance-on-an-object/specs/classification-clearance/spec.md#requirement-read-above-a-callers-clearance-is-refused-on-both-layers
 */
final class ClassificationListTerm {

	/**
	 * Constructor.
	 *
	 * @param ClearanceResolver $clearances Resolves the caller's clearance.
	 */
	public function __construct(
		private readonly ClearanceResolver $clearances,
	) {
	}//end __construct()

	/**
	 * The match block narrowing a list to what this caller may see.
	 *
	 * @param Schema               $schema The schema of the rows listed.
	 * @param ClassificationCaller $caller Who lists, for what action.
	 *
	 * @return array<string, array{'$in': array<int, string>}>|null The block `{property: {$in: [labels]}}`,
	 *                                                              or null when classification narrows nothing.
	 *                                                              An empty `$in` list admits no row.
	 *
	 * @spec openspec/changes/classification-clearance-on-an-object/specs/classification-clearance/spec.md#requirement-read-above-a-callers-clearance-is-refused-on-both-layers
	 */
	public function matchBlock(Schema $schema, ClassificationCaller $caller): ?array {
		$block = (new SchemaAnnotation(schema: $schema, key: ClassificationScale::ANNOTATION))->value();
		if (is_array($block) === false) {
			return null;
		}

		$scale = ClassificationScale::fromBlock(block: $block);
		if ($scale->property() === '' || count($scale->levels()) < 2) {
			return null;
		}

		$admitted = $this->admittedLabels(scale: $scale, limits: ClassificationLimits::fromBlock(scale: $scale, block: $block), caller: $caller);
		if ($admitted === null) {
			return null;
		}

		return [$scale->property() => ['$in' => $admitted]];
	}//end matchBlock()

	/**
	 * The labels this caller may see, or null when every label is admitted.
	 *
	 * @param ClassificationScale  $scale  The declared levels.
	 * @param ClassificationLimits $limits The declared ceilings.
	 * @param ClassificationCaller $caller Who lists.
	 *
	 * @return array<int, string>|null
	 *
	 * @spec openspec/changes/classification-clearance-on-an-object/specs/classification-clearance/spec.md#requirement-a-ceiling-refuses-a-scope-outright
	 */
	private function admittedLabels(ClassificationScale $scale, ClassificationLimits $limits, ClassificationCaller $caller): ?array {
		$levels = $scale->levels();
		$admitted = $levels;
		if ($caller->action === 'read') {
			$clearance = $this->clearances->resolve(scale: $scale, principal: $caller->userId, groups: $caller->groups);
			$admitted = $scale->admittedLabels(clearance: $clearance);
		}

		if ($caller->userId === null) {
			$admitted = array_values(
				array_filter(
					$admitted,
					static fn (string $label): bool => $limits->ceilingRefusing(scope: ClassificationReadGate::PUBLIC_SCOPE, label: $label) === null
				)
			);
		}

		if ($admitted === $levels) {
			return null;
		}

		return $admitted;
	}//end admittedLabels()
}//end class
