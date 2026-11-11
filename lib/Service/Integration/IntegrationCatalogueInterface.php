<?php

/**
 * IntegrationCatalogueInterface: the registered integration providers, for callers that read them.
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
 * Lists and looks up the integration providers registered on this instance.
 *
 * Registration (addProvider, withProviders, setLeafLoader) stays on
 * IntegrationRegistry itself; this is the read side.
 *
 * @spec openspec/specs/integration-registry/spec.md
 */
interface IntegrationCatalogueInterface extends IntegrationIdIndexInterface {

	/**
	 * List every registered provider, irrespective of isEnabled().
	 *
	 * @return array<int, IntegrationProvider>
	 *
	 * @spec openspec/specs/integration-registry/spec.md
	 */
	public function list(): array;

	/**
	 * Look up a provider by id.
	 *
	 * @param string $id Stable integration id (e.g. 'files', 'xwiki').
	 *
	 * @return IntegrationProvider|null Provider, or null when unknown.
	 *
	 * @spec openspec/specs/integration-registry/spec.md
	 */
	public function get(string $id): ?IntegrationProvider;

	/**
	 * Return only the providers that are currently usable.
	 *
	 * @return array<int, IntegrationProvider>
	 *
	 * @spec openspec/specs/integration-registry/spec.md
	 */
	public function getEnabled(): array;
}//end interface
