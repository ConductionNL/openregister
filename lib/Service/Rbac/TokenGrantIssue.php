<?php

/**
 * One request to issue a scoped token grant.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Rbac
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/scoped-api-tokens/specs/auth-system/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Rbac;

use DateTimeInterface;

/**
 * The grant as submitted, the verbs its issuer holds, and the moment of issue.
 *
 * @spec openspec/changes/scoped-api-tokens/specs/auth-system/spec.md
 */
final class TokenGrantIssue {
	/**
	 * Describe the issue.
	 *
	 * @param array<string, mixed> $grant       The submitted grant.
	 * @param array<int, string>   $issuerVerbs The verbs the issuer themselves holds.
	 * @param DateTimeInterface    $now         The moment of issue.
	 */
	public function __construct(
		public readonly array $grant,
		public readonly array $issuerVerbs,
		public readonly DateTimeInterface $now,
	) {
	}//end __construct()
}//end class
