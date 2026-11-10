<?php

/**
 * OpenRegister ConfigurationProvenance
 *
 * Where a configuration value came from: the deployment that last moved it
 * and who moved it. Both null means no deployment ever moved the value.
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
 * The deployment and author that last moved one value.
 *
 * @spec openspec/changes/configuration-as-a-deployment/specs/configuration-deployment/spec.md
 */
final class ConfigurationProvenance {

	/**
	 * Constructor.
	 *
	 * @param string|null $deploymentUuid The deployment that last moved the value.
	 * @param string|null $updatedBy      Who last moved it.
	 */
	public function __construct(
		public readonly ?string $deploymentUuid,
		public readonly ?string $updatedBy=null,
	) {
	}//end __construct()
}//end class
