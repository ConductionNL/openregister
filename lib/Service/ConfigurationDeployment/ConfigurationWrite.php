<?php

/**
 * OpenRegister ConfigurationWrite
 *
 * A value to make live, and the deployment and actor writing it.
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
 * A value to make live at an address, and the deployment and actor writing it.
 *
 * Passed between the write helpers of {@see ConfigurationValueStore}.
 *
 * @spec openspec/changes/configuration-as-a-deployment/specs/configuration-deployment/spec.md
 */
final class ConfigurationWrite {

	/**
	 * Name the write.
	 *
	 * @param mixed       $value          The value to make live.
	 * @param string|null $deploymentUuid The deployment doing the writing.
	 * @param string|null $actor          Who is doing the writing.
	 *
	 * @spec openspec/changes/configuration-as-a-deployment/specs/configuration-deployment/spec.md
	 */
	public function __construct(
		public readonly mixed $value,
		public readonly ?string $deploymentUuid,
		public readonly ?string $actor,
	) {
	}//end __construct()
}//end class
