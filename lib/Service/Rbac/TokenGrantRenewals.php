<?php

/**
 * The recorded renewals of a token grant.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Rbac
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Rbac;

/**
 * Renewing a grant is a recorded act (REQ-SAT-003).
 *
 * A changed end date appends one entry (`by`, `at`, `from`, `expiresAt`) to
 * the renewals a grant already carries; an unchanged end date or a first
 * grant appends nothing. Only the last {@see self::MAX} entries are kept.
 * Used for a Consumer's grant, which keeps its renewals next to the grant
 * in its authorization configuration under {@see self::KEY}.
 *
 * @spec openspec/changes/scoped-api-tokens/specs/auth-system/spec.md#requirement-a-token-carries-a-required-end-date-and-is-warned-before-it-lapses-req-sat-003
 */
final class TokenGrantRenewals {

	/**
	 * The key the renewals are carried under, next to the grant.
	 *
	 * @var string
	 */
	public const KEY = 'grantRenewals';

	/**
	 * How many renewals are kept; the oldest drop off first.
	 *
	 * @var int
	 */
	public const MAX = 20;

	/**
	 * The renewals after a grant was written.
	 *
	 * @param mixed  $previousGrant The grant stored before, or null when there was none.
	 * @param array  $grant         The grant being written.
	 * @param mixed  $renewals      The renewals stored before.
	 * @param string $actor         Who wrote it.
	 * @param string $at            When, as an ATOM date.
	 *
	 * @return array<int, array<string, mixed>> The renewals to store.
	 *
	 * @spec openspec/changes/scoped-api-tokens/specs/auth-system/spec.md#requirement-a-token-carries-a-required-end-date-and-is-warned-before-it-lapses-req-sat-003
	 */
	public static function after(mixed $previousGrant, array $grant, mixed $renewals, string $actor, string $at): array {
		$kept = [];
		if (is_array($renewals) === true) {
			$kept = array_values(array_filter($renewals, 'is_array'));
		}

		if (is_array($previousGrant) === false) {
			return $kept;
		}

		$oldEnd = ($previousGrant['expiresAt'] ?? null);
		$newEnd = ($grant['expiresAt'] ?? null);
		if ($oldEnd === $newEnd) {
			return $kept;
		}

		$kept[] = ['by' => $actor, 'at' => $at, 'from' => $oldEnd, 'expiresAt' => $newEnd];

		return array_values(array_slice($kept, -self::MAX));
	}//end after()
}//end class
