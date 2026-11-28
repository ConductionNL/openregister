<?php

/**
 * OpenRegister RegisterSourceFetchersEvent
 *
 * Dispatched once, when the source fetcher registry is first used, so an app can add a harvest
 * protocol for its own namespaced source type (`<appid>.<protocol>`). OpenRegister registers its own
 * REST fetcher through the same event.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Sync
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/app-harvest-fetchers-and-flow-node/specs/data-sync-harvesting/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Sync;

use OCP\EventDispatcher\Event;

/**
 * Carries the registry an app registers its source fetchers on.
 */
class RegisterSourceFetchersEvent extends Event {
	/**
	 * Constructor.
	 *
	 * @param SourceFetcherRegistry $registry The registry to contribute to.
	 */
	public function __construct(
		private readonly SourceFetcherRegistry $registry,
	) {
		parent::__construct();
	}//end __construct()

	/**
	 * Contribute a fetcher for the type its getType() names.
	 *
	 * @param SourceFetcherInterface $fetcher The fetcher.
	 * @param string|null            $appId   The contributing app, listed with the type (default: derived from the type).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/app-harvest-fetchers-and-flow-node/specs/data-sync-harvesting/spec.md
	 */
	public function registerFetcher(SourceFetcherInterface $fetcher, ?string $appId = null): void {
		$this->registry->register(fetcher: $fetcher, appId: $appId);
	}//end registerFetcher()
}//end class
