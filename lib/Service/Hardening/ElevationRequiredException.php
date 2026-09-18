<?php

/**
 * Thrown when administration is attempted without a fresh authentication.
 *
 * Carries the period that lapsed so the client can say how long an elevated
 * session lasts on this instance, and nothing about the identity: the refusal
 * is about the session, never about who the caller is.
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

use RuntimeException;

/**
 * The refusal that asks for the password again.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Hardening
 */
class ElevationRequiredException extends RuntimeException {

	/**
	 * Constructor.
	 *
	 * @param int $periodSeconds How long an elevated session lasts here.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly int $periodSeconds,
	) {
		parent::__construct(
			message: 'Administration needs a fresh sign-in. Confirm your password, then try again.'
		);

	}//end __construct()

	/**
	 * How long an elevated session lasts on this instance.
	 *
	 * @return int The period, in seconds.
	 *
	 * @spec openspec/changes/instance-hardening-controls/specs/instance-hardening/spec.md#requirement-administration-requires-a-fresh-expiring-authentication-req-ihc-002
	 */
	public function getPeriodSeconds(): int {
		return $this->periodSeconds;

	}//end getPeriodSeconds()

	/**
	 * The refusal as a client reads it.
	 *
	 * @return array{error: string, elevationRequired: bool, periodSeconds: int} The body.
	 *
	 * @spec openspec/changes/instance-hardening-controls/specs/instance-hardening/spec.md#requirement-administration-requires-a-fresh-expiring-authentication-req-ihc-002
	 */
	public function toArray(): array {
		return [
			'error' => $this->getMessage(),
			'elevationRequired' => true,
			'periodSeconds' => $this->periodSeconds,
		];

	}//end toArray()
}//end class
