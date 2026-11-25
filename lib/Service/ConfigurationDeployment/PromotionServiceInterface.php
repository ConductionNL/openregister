<?php

/**
 * Promotes one organisation's configuration into another as a draft set.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\ConfigurationDeployment
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/configuration-as-a-deployment/tasks.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\ConfigurationDeployment;

/**
 * Configuration promotion between OTAP environments.
 *
 * @spec openspec/changes/configuration-as-a-deployment/tasks.md
 */
interface PromotionServiceInterface {

	/**
	 * Promote the source organisation's configuration into the target as a draft set.
	 *
	 * @param string $sourceUuid The organisation whose configuration travels.
	 * @param string $targetUuid The organisation it lands in.
	 *
	 * @return array<string, mixed> The draft set, the component counts and the uuid map.
	 *
	 * @throws DeploymentRefusedException When the promotion is refused; the status code says why.
	 *
	 * @spec openspec/changes/configuration-as-a-deployment/tasks.md
	 */
	public function promote(string $sourceUuid, string $targetUuid): array;
}//end interface
