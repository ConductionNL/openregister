<?php

/**
 * OpenRegister dsar case domain
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller\Support
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

namespace OCA\OpenRegister\Controller\Support;

use OCA\OpenRegister\Service\Gdpr\Identity\IdentityVerifyRegistryInterface;
use OCA\OpenRegister\Service\Gdpr\Regulator\RegulatorEscalateRegistryInterface;

/**
 * The domain services of the dsar case API.
 *
 * @spec openspec/specs/dsar-case-api/spec.md
 */
final class DsarCaseRegistries {
	/**
	 * Bundle the services.
	 *
	 * @param IdentityVerifyRegistryInterface    $identityRegistry  Identity-verify seam registry (pack-selector-driven, fail-closed).
	 * @param RegulatorEscalateRegistryInterface $regulatorRegistry Regulator-escalate seam registry (pack-selector-driven, fail-closed).
	 *
	 * @spec openspec/specs/dsar-case-api/spec.md
	 */
	public function __construct(
		public readonly IdentityVerifyRegistryInterface $identityRegistry,
		public readonly RegulatorEscalateRegistryInterface $regulatorRegistry,
	) {
	}//end __construct()
}//end class
