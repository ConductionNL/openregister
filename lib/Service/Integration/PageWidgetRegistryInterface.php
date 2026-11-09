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

/**
 * Registers and reads the declarative page-level widget descriptors.
 *
 * @spec openspec/specs/integration-leaf-foundation/spec.md
 */
interface PageWidgetRegistryInterface {

	/**
	 * Register a page-level widget contract.
	 *
	 * @param array<string,mixed> $descriptor The page-widget descriptor.
	 *
	 * @return bool True when accepted, false when rejected (missing id or duplicate).
	 *
	 * @spec openspec/specs/integration-leaf-foundation/spec.md
	 */
	public function registerPageWidget(array $descriptor): bool;

	/**
	 * List every registered page-level widget descriptor.
	 *
	 * @return array<int, array<string,mixed>>
	 *
	 * @spec openspec/specs/integration-leaf-foundation/spec.md
	 */
	public function listPageWidgets(): array;

	/**
	 * Look up a page-level widget descriptor by id.
	 *
	 * @param string $id The widget id.
	 *
	 * @return array<string,mixed>|null The descriptor, or null when unknown.
	 *
	 * @spec openspec/specs/integration-leaf-foundation/spec.md
	 */
	public function getPageWidget(string $id): ?array;
}//end interface
