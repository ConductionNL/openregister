<?php

/**
 * A flow was refused a run because nothing named whose rights it uses.
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
 * @spec openspec/specs/delegated-identity/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow;

use Throwable;

/**
 * A flow was refused a run because nothing named whose rights it uses.
 *
 * Implemented by FlowUnattributed; callers outside Service catch this type instead of the class.
 *
 * @spec openspec/specs/delegated-identity/spec.md
 */
interface FlowUnattributedInterface extends Throwable {

	/**
	 * The flow that was refused.
	 *
	 * @return string The flow id.
	 *
	 * @spec openspec/specs/delegated-identity/spec.md
	 */
	public function getFlowId(): string;

	/**
	 * The trigger that named no acting identity.
	 *
	 * @return string The trigger.
	 *
	 * @spec openspec/specs/delegated-identity/spec.md
	 */
	public function getTrigger(): string;
}//end interface
