<?php

/**
 * OpenRegister ConsentEvidenceSource
 *
 * Who wrote a consent entry and from where, as stamped on it as evidence.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Consent
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/specs/consent-evidence-envelope/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Consent;

/**
 * Who wrote a consent entry and from where.
 *
 * The three values the caller already resolved and that
 * {@see ConsentEnvelopeEvaluator} stamps, unchanged, on every newly appended
 * entry as `by`, `ip` and `userAgent`.
 *
 * @spec openspec/specs/consent-evidence-envelope/spec.md
 */
final class ConsentEvidenceSource {

	/**
	 * Name the source.
	 *
	 * @param string|null $actingIdentity The resolved acting identity ("by").
	 * @param string|null $ipAddress      The caller's remote address.
	 * @param string|null $userAgent      The caller's User-Agent header.
	 *
	 * @spec openspec/specs/consent-evidence-envelope/spec.md
	 */
	public function __construct(
		public readonly ?string $actingIdentity,
		public readonly ?string $ipAddress,
		public readonly ?string $userAgent,
	) {
	}//end __construct()
}//end class
