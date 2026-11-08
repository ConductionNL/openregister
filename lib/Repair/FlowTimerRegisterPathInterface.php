<?php

/**
 * FlowTimerRegisterPathInterface: the flow-timer register descriptor path callers outside Repair read.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Repair
 * @package  OCA\OpenRegister\Repair
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

namespace OCA\OpenRegister\Repair;

/**
 * Where the flow-timer register descriptor lives, for callers outside Repair.
 * SeedFlowTimerRegister implements this, so its own REGISTER_PATH still resolves
 * to the same value.
 */
interface FlowTimerRegisterPathInterface {

	/**
	 * App-relative path to the register descriptor imported by this step.
	 *
	 * @var string
	 */
	public const REGISTER_PATH = '/lib/Settings/flow_timer_register.json';
}//end interface
