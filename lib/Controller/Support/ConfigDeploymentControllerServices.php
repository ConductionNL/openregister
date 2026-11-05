<?php

/**
 * OpenRegister configuration deployment controller services
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

use OCA\OpenRegister\Service\ConfigurationDeployment\ConfigurationDraftService;
use OCA\OpenRegister\Service\ConfigurationDeployment\ConfigurationExplainer;
use OCA\OpenRegister\Service\ConfigurationDeployment\DeploymentPreviewService;
use OCA\OpenRegister\Service\ConfigurationDeployment\DeploymentService;

/**
 * The services of the configuration deployment API.
 *
 * @spec openspec/changes/configuration-as-a-deployment/specs/configuration-deployment/spec.md
 */
final class ConfigDeploymentControllerServices {
	/**
	 * Bundle the services.
	 *
	 * @param ConfigurationDraftService $drafts      The pending values.
	 * @param DeploymentPreviewService  $previews    The diff.
	 * @param DeploymentService         $deployments The apply and the rollback.
	 * @param ConfigurationExplainer    $explainer   The effective-configuration read.
	 *
	 * @spec openspec/changes/configuration-as-a-deployment/specs/configuration-deployment/spec.md
	 */
	public function __construct(
		public readonly ConfigurationDraftService $drafts,
		public readonly DeploymentPreviewService $previews,
		public readonly DeploymentService $deployments,
		public readonly ConfigurationExplainer $explainer,
	) {
	}//end __construct()
}//end class
