<?php

/**
 * Optional companion to IFlowNode: a step that needs a runtime outside Nextcloud.
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
 * @spec openspec/changes/flow-code-step-in-a-sidecar/specs/flow-engine/spec.md#requirement-without-the-runner-the-code-step-is-hidden-and-refused
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow;

/**
 * A step type that can only run while something outside the Nextcloud process answers.
 *
 * Optional, like {@see IFlowNodeRequiresRight}: adding a method to IFlowNode would be a
 * fatal error for every node another app ships. The flow preflight asks it, so a flow
 * holding such a step is refused with the runtime named, rather than saved and left to
 * fail halfway through a run.
 *
 * @spec openspec/changes/flow-code-step-in-a-sidecar/specs/flow-engine/spec.md#requirement-without-the-runner-the-code-step-is-hidden-and-refused
 */
interface IFlowNodeRuntime {

	/**
	 * Why this step cannot run on this instance now, or null when its runtime answers.
	 *
	 * @return string|null A sentence naming what is missing, or null.
	 *
	 * @spec openspec/changes/flow-code-step-in-a-sidecar/specs/flow-engine/spec.md#requirement-without-the-runner-the-code-step-is-hidden-and-refused
	 */
	public function unavailableReason(): ?string;
}//end interface
