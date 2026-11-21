<?php

/**
 * OpenRegister CaseStepCall
 *
 * A case step acting on one object: which object (and its register and schema) and as whom.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Case\Nodes
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/flow-cases/spec.md#requirement-a-flow-step-opens-or-advances-a-case
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Case\Nodes;

use OCA\OpenRegister\Service\Case\CaseAnchor;

/**
 * The target object and the acting identity of one case step's work.
 *
 * @spec openspec/specs/flow-cases/spec.md#requirement-a-flow-step-opens-or-advances-a-case
 */
final class CaseStepCall {

	/**
	 * Constructor.
	 *
	 * @param CaseAnchor $target The object the case is, with its register and schema when they are its own.
	 * @param string     $uid    The identity the step acts as.
	 */
	public function __construct(
		public readonly CaseAnchor $target,
		public readonly string $uid,
	) {
	}//end __construct()
}//end class
