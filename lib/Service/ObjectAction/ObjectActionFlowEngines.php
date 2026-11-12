<?php

/**
 * The collaborators a flow or steps body runs through.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\ObjectAction
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/object-actions-declared-and-run/specs/object-actions/spec.md#requirement-inline-steps-are-a-straight-line-lowered-to-the-one-flow-engine
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\ObjectAction;

use OCA\OpenRegister\Service\Flow\FlowService;
use OCA\OpenRegister\Service\Flow\MacroActionResolver;

/**
 * The steps store, the flow engine and the macro resolver.
 *
 * @spec openspec/changes/object-actions-declared-and-run/specs/object-actions/spec.md#requirement-inline-steps-are-a-straight-line-lowered-to-the-one-flow-engine
 */
final class ObjectActionFlowEngines {

	/**
	 * Constructor.
	 *
	 * @param ObjectActionStepsFlow $steps  The stored flow inline steps run as.
	 * @param FlowService           $flows  Runs a flow or steps body.
	 * @param MacroActionResolver   $macros Resolves a flow by name, its `next` and its changes.
	 */
	public function __construct(
		public readonly ObjectActionStepsFlow $steps,
		public readonly FlowService $flows,
		public readonly MacroActionResolver $macros,
	) {
	}//end __construct()
}//end class
