<?php
/**
 * PageWidgetDeclarationInterface: declares a page-level widget and reads it back.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Contract
 * @package  OCA\OpenRegister\Kernel\Contracts\Search
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Kernel\Contracts\Search;

/**
 * Declares page-level widget descriptors and looks one up.
 *
 * The map overview (Search) declares its map widget; the integration
 * registry (Integrations) holds the widgets and is bound to this contract.
 */
interface PageWidgetDeclarationInterface {

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
