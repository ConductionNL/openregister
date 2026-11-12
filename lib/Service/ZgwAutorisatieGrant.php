<?php

/**
 * One ZGW Applicatie grant: scopes for a group, optionally capped at a confidentiality level.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

/**
 * One ZGW Applicatie grant: the scopes a group holds, optionally capped at a
 * maximum confidentiality level read from a property of the schema.
 *
 * ADR-011 Rule 2 operation object for {@see CaseTypeAuthorizationService::mapGrant()}.
 *
 * @spec openspec/specs/rbac-zaaktype/spec.md
 */
final class ZgwAutorisatieGrant {
	/**
	 * Build the grant.
	 *
	 * @param array<int, string> $scopes   ZGW scopes, e.g. ['zaken.lezen', 'zaken.aanmaken'].
	 * @param string             $group    The Nextcloud group representing the ZGW Applicatie.
	 * @param string|null        $maxLevel Optional maxVertrouwelijkheidaanduiding.
	 * @param string             $property Confidentiality property name on the schema.
	 *
	 * @spec openspec/specs/rbac-zaaktype/spec.md
	 */
	public function __construct(
		public readonly array $scopes,
		public readonly string $group,
		public readonly ?string $maxLevel = null,
		public readonly string $property = 'vertrouwelijkheidaanduiding',
	) {
	}//end __construct()
}//end class
