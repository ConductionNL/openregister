<?php

/**
 * RunFlowOperationInterface: the OpenRegister operation of the Nextcloud Flow rule builder.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category WorkflowEngine
 * @package  OCA\OpenRegister\WorkflowEngine
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\WorkflowEngine;

use OCP\WorkflowEngine\ISpecificOperation;

/**
 * The "run an OpenRegister flow" operation of the Nextcloud Flow rule builder.
 */
interface RunFlowOperationInterface extends ISpecificOperation {

	/**
	 * The container service id of the operation.
	 *
	 * Nextcloud Flow stores this class name with every rule and asks the
	 * container for it when the rule fires, so it must stay the class name.
	 */
	public const OPERATION_ID = RunFlowOperation::class;
}//end interface
