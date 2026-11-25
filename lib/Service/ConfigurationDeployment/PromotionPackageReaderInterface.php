<?php

/**
 * OpenRegister PromotionPackageReaderInterface
 *
 * Reads the configuration a promotion carries out of the source organisation.
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
 * @spec openspec/changes/configuration-as-a-deployment/tasks.md#8-promotion-between-environments-row-op-otap
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\ConfigurationDeployment;

/**
 * The source organisation's schemas, registers, mappings, sources, webhooks and endpoints.
 */
interface PromotionPackageReaderInterface {

	/**
	 * Every component the organisation holds, in a stable order: registers, schemas, mappings, sources, webhooks, endpoints.
	 *
	 * @param string $organisationUuid The source organisation.
	 *
	 * @return list<PromotionComponent> The components.
	 *
	 * @spec openspec/changes/configuration-as-a-deployment/tasks.md#8-promotion-between-environments-row-op-otap
	 */
	public function componentsOf(string $organisationUuid): array;
}//end interface
