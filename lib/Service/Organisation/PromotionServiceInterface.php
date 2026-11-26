<?php

/**
 * OpenRegister PromotionServiceInterface
 *
 * Promotes an organisation's configuration to the next OTAP environment as a draft set.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Organisation
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

namespace OCA\OpenRegister\Service\Organisation;

/**
 * Promote configuration from one organisation to another, in OTAP order.
 */
interface PromotionServiceInterface {

	/**
	 * Export the source organisation's configuration and land it in the target as a draft set.
	 *
	 * @param string $sourceUuid The organisation promoted from.
	 * @param string $targetUuid The organisation promoted to.
	 *
	 * @return array{set: array<string, mixed>, source: string, target: string, components: array<string, int>, remapped: array<string, string>}
	 *     The landed set, both organisations, the drafted components by kind and target uuid by source uuid.
	 *
	 * @throws \OCA\OpenRegister\Kernel\Contracts\PromotionRefusedException When an organisation is unknown,
	 *     the OTAP order is not followed or there is nothing to promote.
	 *
	 * @spec openspec/changes/configuration-as-a-deployment/tasks.md#8-promotion-between-environments-row-op-otap
	 */
	public function promote(string $sourceUuid, string $targetUuid): array;
}//end interface
