<?php

/**
 * OpenRegister GrantExceedsGranterException
 *
 * The refusal of a grant that gives more than its granter holds.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Exception
 * @package  OCA\OpenRegister\Exception
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/rights-administration-hardening/specs/rbac-scopes/spec.md#requirement-nobody-grants-a-right-they-do-not-hold-req-rah-002
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Exception;

use Exception;

/**
 * Thrown when a grant gives an action its granter does not hold.
 *
 * HTTP 403 with code `grant-exceeds-granter` and every excess right, so the
 * person granting sees all of what they may not give at once.
 *
 * @spec openspec/changes/rights-administration-hardening/specs/rbac-scopes/spec.md#requirement-nobody-grants-a-right-they-do-not-hold-req-rah-002
 */
class GrantExceedsGranterException extends Exception {

	/**
	 * The error code the refusal answers with.
	 */
	public const CODE = 'grant-exceeds-granter';

	/**
	 * Build the refusal.
	 *
	 * @param array<int, array{register: string|null, schema: string, action: string}> $excess The rights given beyond the granter's own.
	 */
	public function __construct(private readonly array $excess) {
		parent::__construct(message: 'The grant gives rights the granter does not hold.');
	}//end __construct()

	/**
	 * The rights given beyond the granter's own.
	 *
	 * @return array<int, array{register: string|null, schema: string, action: string}> The excess.
	 *
	 * @spec openspec/changes/rights-administration-hardening/specs/rbac-scopes/spec.md#requirement-nobody-grants-a-right-they-do-not-hold-req-rah-002
	 */
	public function getExcess(): array {
		return $this->excess;
	}//end getExcess()

	/**
	 * The answer body: the code and the excess.
	 *
	 * @return array{error: string, excess: array<int, array{register: string|null, schema: string, action: string}>} The body.
	 *
	 * @spec openspec/changes/rights-administration-hardening/specs/rbac-scopes/spec.md#requirement-nobody-grants-a-right-they-do-not-hold-req-rah-002
	 */
	public function body(): array {
		return ['error' => self::CODE, 'excess' => $this->excess];
	}//end body()
}//end class
