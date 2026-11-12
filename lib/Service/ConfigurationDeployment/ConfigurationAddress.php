<?php

/**
 * OpenRegister ConfigurationAddress
 *
 * Where one configuration value lives: its layer, the subject the layer
 * addresses, and its key.
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
 * Where one configuration value lives.
 *
 * The layer, its reference and the key travel together through the draft
 * path of {@see ConfigurationDraftService}, so they are passed as one.
 *
 * @spec openspec/changes/configuration-as-a-deployment/specs/configuration-deployment/spec.md
 */
final class ConfigurationAddress {

	/**
	 * Name the address.
	 *
	 * @param string      $layer     The layer.
	 * @param string|null $layerRef  The layer reference, null at instance level.
	 * @param string      $configKey The configuration key.
	 *
	 * @spec openspec/changes/configuration-as-a-deployment/specs/configuration-deployment/spec.md
	 */
	public function __construct(
		public readonly string $layer,
		public readonly ?string $layerRef,
		public readonly string $configKey,
	) {
	}//end __construct()
}//end class
