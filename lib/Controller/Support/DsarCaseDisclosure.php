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

use OCA\OpenRegister\Service\Gdpr\Evidence\EvidenceHarvestService;
use OCA\OpenRegister\Service\Gdpr\Export\ExportBundleService;
use OCA\OpenRegister\Service\Gdpr\Policy\DsarPolicyPackResolver;
use OCA\OpenRegister\Service\Gdpr\Redaction\RedactionWriteService;

/**
 * The domain services of the dsar case API.
 *
 * @spec openspec/specs/dsar-case-api/spec.md
 */
final class DsarCaseDisclosure {
	/**
	 * Bundle the services.
	 *
	 * @param EvidenceHarvestService $harvestService   Evidence-collection service.
	 * @param RedactionWriteService  $redactionService Field-level redaction write path.
	 * @param ExportBundleService    $bundleService    Export-bundle + dossier service.
	 * @param DsarPolicyPackResolver $packResolver     Resolves the case's active pack seam selectors.
	 *
	 * @spec openspec/specs/dsar-case-api/spec.md
	 */
	public function __construct(
		public readonly EvidenceHarvestService $harvestService,
		public readonly RedactionWriteService $redactionService,
		public readonly ExportBundleService $bundleService,
		public readonly DsarPolicyPackResolver $packResolver,
	) {
	}//end __construct()
}//end class
