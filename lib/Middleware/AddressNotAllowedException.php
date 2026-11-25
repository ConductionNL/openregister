<?php

/**
 * AddressNotAllowedException: a request from an address its surface is not bound to.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Middleware
 * @package  OCA\OpenRegister\Middleware
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/instance-hardening-controls/specs/instance-hardening/spec.md#requirement-a-scope-may-require-a-second-factor-and-a-surface-may-be-bound-to-addresses-req-ihc-003
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Middleware;

use Exception;

/**
 * Carries which surface refused, for the log; the response never shows it.
 *
 * @spec openspec/changes/instance-hardening-controls/specs/instance-hardening/spec.md#requirement-a-scope-may-require-a-second-factor-and-a-surface-may-be-bound-to-addresses-req-ihc-003
 */
class AddressNotAllowedException extends Exception {

	/**
	 * Constructor.
	 *
	 * @param string $surface The surface whose allowlist did not hold the address.
	 *
	 * @return void
	 */
	public function __construct(public readonly string $surface) {
		parent::__construct(message: 'Access forbidden');
	}//end __construct()
}//end class
