<?php

/**
 * The refusal a hardening write raises when the session is not elevated.
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
 * @spec openspec/changes/instance-hardening-controls/specs/instance-hardening/spec.md#requirement-administration-requires-a-fresh-expiring-authentication-req-ihc-002
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Hardening;

use Throwable;

/**
 * The refusal a hardening write raises when the session is not elevated.
 *
 * Implemented by ElevationRequiredException; callers outside Service catch this type instead of the class.
 */
interface ElevationRequiredExceptionInterface extends Throwable {

	/**
	 * The refusal as a client reads it.
	 *
	 * @return array{error: string, elevationRequired: bool, periodSeconds: int} The body.
	 *
	 * @spec openspec/changes/instance-hardening-controls/specs/instance-hardening/spec.md#requirement-administration-requires-a-fresh-expiring-authentication-req-ihc-002
	 */
	public function toArray(): array;
}//end interface
