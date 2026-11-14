<?php

/**
 * Saving a flow was refused: it holds a step whose right the author lacks.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Flow
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/flow-powerful-steps-need-a-right/specs/flow-engine/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow;

use RuntimeException;

/**
 * A refusal naming the step type, the step and the right it needs.
 */
class FlowNodeRightRefused extends RuntimeException {

	/**
	 * Constructor.
	 *
	 * @param string $nodeType The step type.
	 * @param string $step     The step's label, id or position.
	 * @param string $right    The right the author lacks.
	 *
	 * @spec openspec/changes/flow-powerful-steps-need-a-right/specs/flow-engine/spec.md#requirement-a-flow-with-a-restricted-step-is-saved-only-by-someone-who-holds-its-right
	 */
	public function __construct(
		public readonly string $nodeType,
		public readonly string $step,
		public readonly string $right,
	) {
		parent::__construct(
			message: sprintf(
				'The step "%s" (%s) needs the "%s" right, which you do not have. Ask an administrator to grant it.',
				$step,
				$nodeType,
				$right
			)
		);
	}//end __construct()

	/**
	 * The refusal as a response body.
	 *
	 * @return array{error: string, nodeType: string, step: string, right: string}
	 *
	 * @spec openspec/changes/flow-powerful-steps-need-a-right/specs/flow-engine/spec.md#requirement-a-flow-with-a-restricted-step-is-saved-only-by-someone-who-holds-its-right
	 */
	public function body(): array {
		return ['error' => $this->getMessage(), 'nodeType' => $this->nodeType, 'step' => $this->step, 'right' => $this->right];
	}//end body()
}//end class
