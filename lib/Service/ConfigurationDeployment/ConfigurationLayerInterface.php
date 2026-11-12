<?php

/**
 * ConfigurationLayerInterface: the configuration layer callers outside the component name.
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

/**
 * The configuration layer read outside the component.
 *
 * ConfigurationLayer implements this, so `ConfigurationLayer::INSTANCE`
 * stays the one home of the value.
 *
 * @spec openspec/changes/configuration-as-a-deployment/specs/configuration-deployment/spec.md
 */
interface ConfigurationLayerInterface {

	/**
	 * The whole instance. One address per key, so it carries no reference.
	 *
	 * @var string
	 */
	public const INSTANCE = 'instance';
}//end interface
