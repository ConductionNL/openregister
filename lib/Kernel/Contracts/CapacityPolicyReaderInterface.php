<?php

/**
 * CapacityPolicyReaderInterface: the organisation's capacity policy, as a decision reads it.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Contract
 * @package  OCA\OpenRegister\Kernel\Contracts
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Kernel\Contracts;


/**
 * Reads the capacity policy the organisation stored in its file settings.
 *
 * A relation decision (Processes) fills in the decision and ground of a
 * person's capacity from this policy; the settings that hold it belong to
 * Configuration, which binds its settings handler to this contract.
 *
 * @spec openspec/changes/detection-knows-the-person/tasks.md#task-2.1
 */
interface CapacityPolicyReaderInterface {

	/**
	 * The stored capacity policy, or the shipped default when none is stored.
	 *
	 * @return CapacityPolicy
	 *
	 * @spec openspec/changes/detection-knows-the-person/tasks.md#task-2.1
	 */
	public function capacityPolicy(): CapacityPolicy;
}//end interface
