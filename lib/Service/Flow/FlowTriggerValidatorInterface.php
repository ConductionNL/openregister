<?php

/**
 * FlowTriggerValidatorInterface: what callers outside the Service component ask of FlowTriggerValidator.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Flow
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

namespace OCA\OpenRegister\Service\Flow;

use OCA\OpenRegister\Db\Flow;

/**
 * Trigger-node config validation, as the scheduled-flow store uses it. Only
 * the method that caller calls.
 */
interface FlowTriggerValidatorInterface {

	/**
	 * Let every resolvable trigger node reject its own config.
	 *
	 * @param Flow $flow The flow about to be written.
	 *
	 * @return void
	 *
	 * @throws \InvalidArgumentException When a trigger node rejects its config.
	 */
	public function validate(Flow $flow): void;
}//end interface
