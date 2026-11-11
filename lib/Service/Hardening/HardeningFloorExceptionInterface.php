<?php

/**
 * The refusal a hardening write raises when it would weaken a control below its floor.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Hardening
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/instance-hardening-controls/specs/instance-hardening/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Hardening;

use Throwable;

/**
 * The refusal a hardening write raises when it would weaken a control below its floor.
 *
 * Implemented by HardeningFloorException; callers outside Service catch this type instead of the class.
 */
interface HardeningFloorExceptionInterface extends Throwable {

	/**
	 * The refusal as the API returns it.
	 *
	 * @return array<string, mixed> The refusal body.
	 *
	 * @spec openspec/changes/instance-hardening-controls/specs/instance-hardening/spec.md#requirement-the-instance-reports-every-control-against-a-declared-floor-and-refuses-a-change-that-weakens-one-req-ihc-006
	 */
	public function toArray(): array;
}//end interface
