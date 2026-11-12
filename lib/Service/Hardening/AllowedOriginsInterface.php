<?php

/**
 * AllowedOriginsInterface: what the public-API CORS middleware asks of HardeningPolicy.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Hardening
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Hardening;

/**
 * The administered origin allowlist of the public surface. Only the method PublicApiCorsMiddleware calls.
 */
interface AllowedOriginsInterface {

	/**
	 * The origins a browser on another site may read a public answer from.
	 *
	 * An empty list means this instance has not bound its public surface.
	 *
	 * @return array<int, string> The allowlisted origins, lowercased, in declaration order.
	 *
	 * @spec openspec/changes/instance-hardening-controls/specs/instance-hardening/spec.md#requirement-the-instance-reports-every-control-against-a-declared-floor-and-refuses-a-change-that-weakens-one-req-ihc-006
	 */
	public function allowedOrigins(): array;
}//end interface
