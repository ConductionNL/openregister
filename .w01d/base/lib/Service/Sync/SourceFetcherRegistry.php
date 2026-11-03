<?php

/**
 * OpenRegister Source Fetcher Registry
 *
 * Resolves the appropriate SourceFetcherInterface for a given source type.
 * Fetchers are registered explicitly; the registry returns the first that
 * reports support for the requested type. Pure selection logic, so it is
 * unit-testable with in-memory fetcher doubles.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Sync
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

namespace OCA\OpenRegister\Service\Sync;

/**
 * Registry mapping source types to fetchers.
 *
 * @spec openspec/specs/data-sync-harvesting/spec.md
 */
class SourceFetcherRegistry {

	/**
	 * Registered fetchers.
	 *
	 * @var list<SourceFetcherInterface>
	 */
	private array $fetchers = [];

	/**
	 * Constructor.
	 *
	 * @param iterable<SourceFetcherInterface> $fetchers Initial fetchers (DI-provided)
	 */
	public function __construct(iterable $fetchers = []) {
		foreach ($fetchers as $fetcher) {
			$this->register(fetcher: $fetcher);
		}
	}//end __construct()

	/**
	 * Register a fetcher.
	 *
	 * @param SourceFetcherInterface $fetcher The fetcher
	 *
	 * @return void
	 *
	 * @spec openspec/specs/data-sync-harvesting/spec.md
	 */
	public function register(SourceFetcherInterface $fetcher): void {
		$this->fetchers[] = $fetcher;
	}//end register()

	/**
	 * Get a fetcher for the given source type.
	 *
	 * @param string $type The source type
	 *
	 * @return SourceFetcherInterface|null The first supporting fetcher, or null
	 *
	 * @spec openspec/specs/data-sync-harvesting/spec.md
	 */
	public function get(string $type): ?SourceFetcherInterface {
		foreach ($this->fetchers as $fetcher) {
			if ($fetcher->supports($type) === true) {
				return $fetcher;
			}
		}

		return null;
	}//end get()
}//end class
