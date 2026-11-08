<?php

/**
 * The elevated administration session the hardening endpoints check.
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

/**
 * The elevated administration session the hardening endpoints check.
 *
 * Bound to ElevationService in ContractBindings. Every method is described on ElevationService.
 */
interface ElevationServiceInterface {

	/**
	 * How long an elevated session lasts on this instance.
	 *
	 * @return int The period, in seconds.
	 *
	 * @spec openspec/changes/instance-hardening-controls/specs/instance-hardening/spec.md#requirement-administration-requires-a-fresh-expiring-authentication-req-ihc-002
	 */
	public function periodSeconds(): int;

	/**
	 * Confirm the password of the signed-in account, and start the period.
	 *
	 * The account is taken from the session and never from the request: an
	 * elevation request naming a user id would let anybody elevate anybody by
	 * guessing one password, and the whole point is that the session and the
	 * secret are held by the same person.
	 *
	 * @param string $password The password, as the person typed it.
	 *
	 * @return bool True when the session is now elevated.
	 *
	 * @spec openspec/changes/instance-hardening-controls/specs/instance-hardening/spec.md#requirement-administration-requires-a-fresh-expiring-authentication-req-ihc-002
	 */
	public function elevate(string $password): bool;

	/**
	 * How much of the elevated period is left, in seconds.
	 *
	 * @return int The seconds left, and zero when the session is not elevated.
	 *
	 * @spec openspec/changes/instance-hardening-controls/specs/instance-hardening/spec.md#requirement-administration-requires-a-fresh-expiring-authentication-req-ihc-002
	 */
	public function remainingSeconds(): int;

	/**
	 * Refuse an administration write unless the period is running.
	 *
	 * @return void
	 *
	 * @throws ElevationRequiredException When the session is not elevated.
	 *
	 * @spec openspec/changes/instance-hardening-controls/specs/instance-hardening/spec.md#requirement-administration-requires-a-fresh-expiring-authentication-req-ihc-002
	 */
	public function requireElevated(): void;
}//end interface
