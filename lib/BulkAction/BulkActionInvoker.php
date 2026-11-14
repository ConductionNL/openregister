<?php

/**
 * OpenRegister BulkActionInvoker
 *
 * Calls a bulk action in whichever form it implements.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category BulkAction
 * @package  OCA\OpenRegister\BulkAction
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\BulkAction;

use OCA\OpenRegister\Kernel\Compat\ImplementationForm;
use RuntimeException;

/**
 * Applies a bulk action written in either published form.
 *
 * @spec openspec/specs/bulk-action-jobs/spec.md
 */
final class BulkActionInvoker {

	/**
	 * Constructor
	 *
	 * @param ImplementationForm $form Tells the two forms apart.
	 */
	public function __construct(
		private readonly ImplementationForm $form=new ImplementationForm(),
	) {
	}//end __construct()

	/**
	 * Apply the action to one object.
	 *
	 * @param BulkActionInterface $action The action.
	 * @param BulkStep            $step   The object, the job's parameters, rehearsal or commit, and the actor.
	 *
	 * @return BulkActionResult What happened, or what would happen.
	 *
	 * @throws RuntimeException When the action declares no apply().
	 */
	public function apply(BulkActionInterface $action, BulkStep $step): BulkActionResult {
		if ($action instanceof StepBulkActionInterface) {
			return $action->apply($step);
		}

		if ($action instanceof LegacyBulkActionInterface) {
			return $action->apply($step->object, $step->parameters, $step->commit, $step->actor);
		}

		if (method_exists($action, 'apply') === false) {
			throw new RuntimeException(sprintf('Bulk action %s declares no apply().', $action::class));
		}

		$apply = [$action, 'apply'];
		if ($this->form->takesObject(implementer: $action, method: 'apply', parameterClass: BulkStep::class) === true) {
			return $apply($step);
		}

		return $apply($step->object, $step->parameters, $step->commit, $step->actor);
	}//end apply()
}//end class
