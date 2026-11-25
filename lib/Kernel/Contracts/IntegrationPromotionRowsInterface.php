<?php

/**
 * OpenRegister IntegrationPromotionRowsInterface
 *
 * What a promotion needs from the Integrations component: one organisation's mappings, webhooks and endpoints.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Kernel
 * @package  OCA\OpenRegister\Kernel\Contracts
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

namespace OCA\OpenRegister\Kernel\Contracts;

/**
 * One organisation's integration rows, serialised, without the fields bound to the instance.
 *
 * @spec openspec/specs/environment-otap/spec.md#requirement-configuration-promotion-must-transfer-settings-between-otap-environments
 */
interface IntegrationPromotionRowsInterface {

	/**
	 * Fields that belong to the source instance and never travel with a promotion.
	 *
	 * @var list<string>
	 */
	public const INSTANCE_FIELDS = ['id', 'uuid', 'organisation', 'created', 'updated', 'owner'];

	/**
	 * The organisation's mappings, webhooks and endpoints, in that order.
	 *
	 * @param string $organisationUuid The organisation.
	 *
	 * @return list<array{type: string, uuid: string|null, definition: array<string, mixed>}> The rows: type is mapping, webhook or endpoint.
	 *
	 * @spec openspec/changes/configuration-as-a-deployment/tasks.md#8-promotion-between-environments-row-op-otap
	 */
	public function rowsOf(string $organisationUuid): array;
}//end interface
