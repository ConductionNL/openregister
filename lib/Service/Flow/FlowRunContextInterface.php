<?php

/**
 * FlowRunContextInterface: the FlowRunContext as the audit attribution, the delete guard and the objects controller read it.
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

use OCA\OpenRegister\Kernel\Contracts\Core\CurrentFlowRunInterface;

/**
 * The executing flow step, read-only, for the callers outside the Service component
 * that attribute or authorise a write by the run that makes it.
 *
 * Declares only what those callers use; push() and pop() stay the engine's own.
 * currentRunUuid() comes from the Core contract CurrentFlowRunInterface, which the
 * Core delete guard types against.
 * Bound in ContractBindings to FlowRunContext, whose FlowRegistrar registration is
 * shared, so every caller reads the one ambient stack.
 */
interface FlowRunContextInterface extends CurrentFlowRunInterface {

	/**
	 * The frame to attribute a write to, or null when nothing is executing.
	 *
	 * @return array{run: string, node: string, step: int}|null The innermost frame, or null outside any run.
	 *
	 * @spec openspec/changes/flow-object-attribution/specs/flow-object-attribution/spec.md
	 */
	public function current(): ?array;
}//end interface
