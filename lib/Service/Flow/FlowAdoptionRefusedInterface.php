<?php

/**
 * Adopting a flow was refused, with a machine-readable reason.
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
 * @spec openspec/specs/flow-storage/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow;

use Throwable;

/**
 * Adopting a flow was refused, with a machine-readable reason.
 *
 * Implemented by FlowAdoptionRefused; callers outside Service catch this type instead of the class.
 *
 * @spec openspec/specs/flow-storage/spec.md
 */
interface FlowAdoptionRefusedInterface extends Throwable {

	/**
	 * The machine-readable reason, one of the FlowAdoptionRefused reasons.
	 *
	 * @return string The reason.
	 *
	 * @spec openspec/specs/flow-storage/spec.md
	 */
	public function getReason(): string;
}//end interface
