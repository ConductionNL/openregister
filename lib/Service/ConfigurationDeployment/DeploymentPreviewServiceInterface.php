<?php

/**
 * DeploymentPreviewServiceInterface: what callers outside the component use of DeploymentPreviewService.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\ConfigurationDeployment
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/configuration-as-a-deployment/specs/configuration-deployment/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\ConfigurationDeployment;

use OCA\OpenRegister\Db\ConfigurationDraftSet;

/**
 * What deploying a draft set would change.
 *
 * Declares only what callers outside the component use; the rest of the
 * class stays its own.
 */
interface DeploymentPreviewServiceInterface {

	/**
	 * What deploying a set would change.
	 *
	 * @param ConfigurationDraftSet $set The set to preview.
	 *
	 * @return array<string, mixed> The diff, the refusals and the counts.
	 *
	 * @spec openspec/changes/configuration-as-a-deployment/specs/configuration-deployment/spec.md
	 */
	public function preview(ConfigurationDraftSet $set): array;
}//end interface
