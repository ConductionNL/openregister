<?php

/**
 * A flow was refused a run because a token could not leave one of its nodes.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Flow
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/specs/flow-engine/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow;

use Throwable;

/**
 * A flow was refused a run because a token could not leave one of its nodes.
 *
 * Implemented by FlowDeadEnd; callers outside Service catch this type instead of the class.
 *
 * @spec openspec/specs/flow-engine/spec.md
 */
interface FlowDeadEndInterface extends Throwable {

	/**
	 * The nodes a token cannot leave.
	 *
	 * @return array<int, string> The node ids.
	 *
	 * @spec openspec/specs/flow-engine/spec.md
	 */
	public function getNodeIds(): array;
}//end interface
