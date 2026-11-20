<?php

/**
 * PageWidgetRegistryInterface: the page-level widget surface of IntegrationRegistry.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Integration
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Integration;

use OCA\OpenRegister\Kernel\Contracts\Search\PageWidgetDeclarationInterface;

/**
 * Registers and reads the declarative page-level widget descriptors.
 *
 * The Search contract this interface extends declares registerPageWidget()
 * and getPageWidget().
 *
 * @spec openspec/specs/integration-leaf-foundation/spec.md
 */
interface PageWidgetRegistryInterface extends PageWidgetDeclarationInterface {

	/**
	 * List every registered page-level widget descriptor.
	 *
	 * @return array<int, array<string,mixed>>
	 *
	 * @spec openspec/specs/integration-leaf-foundation/spec.md
	 */
	public function listPageWidgets(): array;
}//end interface
