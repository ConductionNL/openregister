<?php

/**
 * OpenRegister PromotionLandingInterface
 *
 * What a promotion needs from the Configuration component: a register's package export and a draft set to land the package in.
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

use OCA\OpenRegister\Db\Register;

/**
 * Export a register as a package, and land promoted values as drafts.
 *
 * @spec openspec/specs/environment-otap/spec.md#requirement-configuration-promotion-must-transfer-settings-between-otap-environments
 */
interface PromotionLandingInterface {

	/**
	 * The layer a register-wide value is drafted at.
	 *
	 * @var string
	 */
	public const LAYER_REGISTER = 'register';

	/**
	 * The layer a value of one subject (a schema, a mapping, ...) is drafted at.
	 *
	 * @var string
	 */
	public const LAYER_SUBJECT = 'subject';

	/**
	 * One register's package, as the configuration export writes it (OpenAPI, references by slug, no ids).
	 *
	 * @param Register $register The register.
	 *
	 * @return array<string, mixed> The export document.
	 *
	 * @spec openspec/changes/configuration-as-a-deployment/tasks.md#8-promotion-between-environments-row-op-otap
	 */
	public function exportRegister(Register $register): array;

	/**
	 * Open a draft set and draft every value into it; nothing goes live.
	 *
	 * @param string                                                                              $name        The set's name.
	 * @param string                                                                              $description What the set is for.
	 * @param list<array{layer: string, layerRef: string, configKey: string, value: mixed}> $drafts      The values, each at its address.
	 *
	 * @return array<string, mixed> The opened set, serialised.
	 *
	 * @throws PromotionRefusedException When the set cannot be opened or a value cannot be drafted.
	 *
	 * @spec openspec/changes/configuration-as-a-deployment/tasks.md#8-promotion-between-environments-row-op-otap
	 */
	public function land(string $name, string $description, array $drafts): array;
}//end interface
