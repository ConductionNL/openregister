<?php

/**
 * AppContainerFinderInterface: reach another app's own DI container.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Contract
 * @package  OCA\OpenRegister\Kernel\Contracts
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Kernel\Contracts;

use Psr\Container\ContainerInterface;

/**
 * Finds the DI container an installed app registered for itself. Implemented by AppContainerLocator.
 */
interface AppContainerFinderInterface {

	/**
	 * The app's own container, or null when it has none.
	 *
	 * @param string $appId The app id whose container is wanted.
	 *
	 * @return ContainerInterface|null The app's container, or null.
	 *
	 * @spec openspec/specs/apphost-observability/spec.md#requirement-a-leaf-apps-provider-must-be-resolved-from-that-apps-own-container
	 */
	public function find(string $appId): ?ContainerInterface;
}//end interface
