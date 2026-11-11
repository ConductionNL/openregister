<?php

/**
 * A flow lifecycle transition was refused, with a machine-readable reason.
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
 * @spec openspec/specs/flow-definition-versioning/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow;

use Throwable;

/**
 * A flow lifecycle transition was refused, with a machine-readable reason.
 *
 * Implemented by FlowLifecycleRefused; callers outside Service catch this type instead of the class.
 *
 * @spec openspec/specs/flow-definition-versioning/spec.md
 */
interface FlowLifecycleRefusedInterface extends Throwable {

	/**
	 * The machine-readable reason, one of the FlowLifecycleRefused reasons.
	 *
	 * @return string The reason.
	 *
	 * @spec openspec/specs/flow-definition-versioning/spec.md
	 */
	public function getReason(): string;

	/**
	 * The flow the refusal is about.
	 *
	 * @return string The flow id.
	 *
	 * @spec openspec/specs/flow-definition-versioning/spec.md
	 */
	public function getFlowId(): string;

	/**
	 * The lifecycle state the version was in, when known.
	 *
	 * @return ?string The state, or null.
	 *
	 * @spec openspec/specs/flow-definition-versioning/spec.md
	 */
	public function getState(): ?string;
}//end interface
