<?php

/**
 * OpenRegister DeploymentValues
 *
 * The values a deployment moves: the pending drafts, their preview and the live store.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
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

/**
 * The values a deployment moves: the pending drafts, their preview and the live store.
 *
 * @spec openspec/changes/configuration-as-a-deployment/specs/configuration-deployment/spec.md
 */
final readonly class DeploymentValues {
	/**
	 * Bundle the collaborators.
	 *
	 * @param ConfigurationDraftService $drafts   The pending values.
	 * @param DeploymentPreviewService  $previews The diff and its refusals.
	 * @param ConfigurationValueStore   $store    The live values.
	 */
	public function __construct(
		public ConfigurationDraftService $drafts,
		public DeploymentPreviewService $previews,
		public ConfigurationValueStore $store,
	) {
	}//end __construct()
}//end class
