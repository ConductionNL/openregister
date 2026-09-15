<?php

/**
 * Write the same properties on every object in a selection.
 *
 * This is the attribute write, and it declares the homogeneity guard: a
 * selection spanning two schema versions is refused before anything is
 * touched, naming both versions and their counts. The same guard on a
 * redistribution would be wrong, which is exactly why it belongs to the
 * action (D-6).
 *
 * @category BulkAction
 * @package  OCA\OpenRegister\BulkAction
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\BulkAction;

use InvalidArgumentException;

/**
 * The built-in bulk attribute write.
 */
class SetPropertiesAction extends AbstractPropertyWriteAction {

	/**
	 * The action id.
	 *
	 * @var string
	 */
	public const ID = 'openregister:set-properties';

	/**
	 * The action's stable id.
	 *
	 * @return string The action id.
	 *
	 * @spec openspec/changes/bulk-action-jobs/specs/bulk-action-jobs/spec.md
	 */
	public function getId(): string {
		return self::ID;
	}//end getId()

	/**
	 * The label an operator reads.
	 *
	 * @return string The label.
	 *
	 * @spec openspec/changes/bulk-action-jobs/specs/bulk-action-jobs/spec.md
	 */
	public function getLabel(): string {
		return 'Set properties';
	}//end getLabel()

	/**
	 * One sentence saying what the action does.
	 *
	 * @return string The description.
	 *
	 * @spec openspec/changes/bulk-action-jobs/specs/bulk-action-jobs/spec.md
	 */
	public function getDescription(): string {
		return 'Writes the same properties on every selected object. Objects that already carry those values are skipped.';
	}//end getDescription()

	/**
	 * An attribute write needs no written reason.
	 *
	 * @return bool False.
	 *
	 * @spec openspec/changes/bulk-action-jobs/specs/bulk-action-jobs/spec.md
	 */
	public function requiresJustification(): bool {
		return false;
	}//end requiresJustification()

	/**
	 * The guards the engine enforces for this action.
	 *
	 * @return array<int, string> The homogeneity guard.
	 *
	 * @spec openspec/changes/bulk-action-jobs/specs/bulk-action-jobs/spec.md
	 */
	public function getGuards(): array {
		return [self::GUARD_HOMOGENEITY];
	}//end getGuards()

	/**
	 * Check the parameters before a job is created.
	 *
	 * @param array<string, mixed> $parameters The parameters the caller sent.
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException When no properties were given.
	 *
	 * @spec openspec/changes/bulk-action-jobs/specs/bulk-action-jobs/spec.md
	 */
	public function validateParameters(array $parameters): void {
		$properties = ($parameters['properties'] ?? null);

		if (is_array($properties) === false || $properties === []) {
			throw new InvalidArgumentException(
				'The action '.self::ID.' needs a properties object holding at least one property to write.'
			);
		}
	}//end validateParameters()

	/**
	 * The merge patch this action writes.
	 *
	 * @param array<string, mixed> $parameters The job's parameters.
	 *
	 * @return array<string, mixed> The merge patch.
	 */
	protected function patchFor(array $parameters): array {
		$properties = ($parameters['properties'] ?? []);

		if (is_array($properties) === false) {
			return [];
		}

		return $properties;
	}//end patchFor()

	/**
	 * The reason given when the object already carries the target values.
	 *
	 * @return string The skip reason.
	 */
	protected function alreadyThereReason(): string {
		return 'the object already carries these values';
	}//end alreadyThereReason()
}//end class
