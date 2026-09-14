<?php

/**
 * Hand every object in a selection to one handler.
 *
 * The distributive act from the coordinator's werkvoorraad: four hundred
 * cases move to a colleague, or a departing handler's caseload is released.
 * It declares that a justification is required, so the job cannot commit
 * without the reason the operator typed, and that reason lands in the audit
 * entry of every member (D-7).
 *
 * It declares no homogeneity guard on purpose. Refusing a redistribution
 * because the caseload spans two schema versions would block the act the
 * candidate asks for, and the versions have nothing to do with who holds
 * the case (D-6).
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
 * The built-in bulk redistribution.
 */
class AssignAction extends AbstractPropertyWriteAction {

	/**
	 * The action id.
	 *
	 * @var string
	 */
	public const ID = 'openregister:assign';

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
		return 'Hand over to a handler';
	}//end getLabel()

	/**
	 * One sentence saying what the action does.
	 *
	 * @return string The description.
	 *
	 * @spec openspec/changes/bulk-action-jobs/specs/bulk-action-jobs/spec.md
	 */
	public function getDescription(): string {
		return 'Names one handler on every selected object. The reason you type is recorded against each one.';
	}//end getDescription()

	/**
	 * A distribution without a recorded reason is an audit finding waiting
	 * to happen.
	 *
	 * @return bool True.
	 *
	 * @spec openspec/changes/bulk-action-jobs/specs/bulk-action-jobs/spec.md
	 */
	public function requiresJustification(): bool {
		return true;
	}//end requiresJustification()

	/**
	 * The guards the engine enforces for this action.
	 *
	 * @return array<int, string> No guards.
	 *
	 * @spec openspec/changes/bulk-action-jobs/specs/bulk-action-jobs/spec.md
	 */
	public function getGuards(): array {
		return [];
	}//end getGuards()

	/**
	 * Check the parameters before a job is created.
	 *
	 * @param array<string, mixed> $parameters The parameters the caller sent.
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException When the property or the value is missing.
	 *
	 * @spec openspec/changes/bulk-action-jobs/specs/bulk-action-jobs/spec.md
	 */
	public function validateParameters(array $parameters): void {
		$property = ($parameters['property'] ?? null);

		if (is_string($property) === false || trim($property) === '') {
			throw new InvalidArgumentException(
				'The action '.self::ID.' needs a property naming the field that holds the handler.'
			);
		}

		if (array_key_exists('value', $parameters) === false) {
			throw new InvalidArgumentException(
				'The action '.self::ID.' needs a value naming the handler to hand the objects to.'
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
		$property = (string)($parameters['property'] ?? '');

		if ($property === '') {
			return [];
		}

		return [$property => ($parameters['value'] ?? null)];
	}//end patchFor()

	/**
	 * The reason given when the object already names this handler.
	 *
	 * @return string The skip reason.
	 */
	protected function alreadyThereReason(): string {
		return 'the object already names this handler';
	}//end alreadyThereReason()
}//end class
