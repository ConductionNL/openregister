<?php

/**
 * SearchScopesCapabilityOnDemand: registers the search scopes capability
 * without building it on every page render.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Capabilities
 * @package  OCA\OpenRegister\Capabilities
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/content-search-index/specs/unified-search-provider/spec.md#requirement-the-provider-accepts-scopes-and-advertises-them
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Capabilities;

use OCP\Capabilities\ICapability;
use OCP\Capabilities\IInitialStateExcludedCapability;
use Psr\Container\ContainerInterface;

/**
 * The search scopes capability, built only when its block is asked for.
 *
 * Nextcloud renders the capabilities into every page, and it constructs every
 * registered capability before it checks IInitialStateExcludedCapability.
 * SearchScopesCapability is excluded from that page state, yet building it
 * resolved the permission handler and, through it, the MagicMapper with its
 * handlers: ~1.5 ms of every Nextcloud page render on the reference stack,
 * for a block no page used. This class is what Nextcloud constructs; it holds
 * only the container and builds SearchScopesCapability when the OCS
 * capabilities endpoint asks for the block.
 *
 * @spec openspec/changes/content-search-index/specs/unified-search-provider/spec.md#requirement-the-provider-accepts-scopes-and-advertises-them
 */
final class SearchScopesCapabilityOnDemand implements ICapability, IInitialStateExcludedCapability {

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container The app container that builds SearchScopesCapability.
	 */
	public function __construct(
		private readonly ContainerInterface $container,
	) {
	}//end __construct()

	/**
	 * The search scopes block, from SearchScopesCapability.
	 *
	 * @return array<string, mixed> The capability block.
	 *
	 * @spec openspec/changes/content-search-index/specs/unified-search-provider/spec.md#requirement-the-provider-accepts-scopes-and-advertises-them
	 */
	public function getCapabilities(): array {
		return $this->container->get(SearchScopesCapability::class)->getCapabilities();
	}//end getCapabilities()
}//end class
