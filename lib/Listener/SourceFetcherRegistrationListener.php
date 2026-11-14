<?php

/**
 * OpenRegister SourceFetcherRegistrationListener
 *
 * Registers OpenRegister's own source fetchers through RegisterSourceFetchersEvent, the path every app uses, so that path is exercised by its owner.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Listener
 * @package  OCA\OpenRegister\Listener
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

namespace OCA\OpenRegister\Listener;

use OCA\OpenRegister\Service\Sync\RegisterSourceFetchersEvent;
use OCA\OpenRegister\Service\Sync\RestApiSourceFetcher;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;

/**
 * Contributes OpenRegister's built-in fetchers.
 *
 * @template-implements IEventListener<Event>
 */
class SourceFetcherRegistrationListener implements IEventListener {
	/**
	 * Constructor.
	 *
	 * @param RestApiSourceFetcher $restApi The REST (and OpenRegister-to-OpenRegister) fetcher.
	 */
	public function __construct(
		private readonly RestApiSourceFetcher $restApi,
	) {
	}//end __construct()

	/**
	 * Register the built-in fetchers on the event's registry.
	 *
	 * @param Event $event The event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/app-harvest-fetchers-and-flow-node/specs/data-sync-harvesting/spec.md
	 */
	public function handle(Event $event): void {
		if (($event instanceof RegisterSourceFetchersEvent) === false) {
			return;
		}

		$event->registerFetcher(fetcher: $this->restApi, appId: 'openregister');
	}//end handle()
}//end class
