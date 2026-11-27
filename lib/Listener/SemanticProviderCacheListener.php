<?php

/**
 * OpenRegister SemanticProviderCacheListener: drops the semantic provider list when what it is built from changes.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Listener
 * @package  OCA\OpenRegister\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/cross-app-semantic-references/tasks.md#task-4.1
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Listener;

use OCA\OpenRegister\Event\RegisterCreatedEvent;
use OCA\OpenRegister\Event\RegisterDeletedEvent;
use OCA\OpenRegister\Event\RegisterUpdatedEvent;
use OCA\OpenRegister\Event\SchemaCreatedEvent;
use OCA\OpenRegister\Event\SchemaDeletedEvent;
use OCA\OpenRegister\Event\SchemaUpdatedEvent;
use OCA\OpenRegister\Service\SemanticProviderCatalogue;
use OCP\App\Events\AppDisableEvent;
use OCP\App\Events\AppEnableEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;

/**
 * Clears the cached semantic provider list on a schema, register or app change,
 * so the integrations capability never advertises a provider that is gone or
 * misses one that arrived.
 *
 * @template-implements IEventListener<Event>
 *
 * @spec openspec/changes/cross-app-semantic-references/tasks.md#task-4.1
 */
final class SemanticProviderCacheListener implements IEventListener {

	/**
	 * The events after which the provider list is stale.
	 *
	 * @var list<class-string<Event>>
	 */
	public const EVENTS = [
		SchemaCreatedEvent::class,
		SchemaUpdatedEvent::class,
		SchemaDeletedEvent::class,
		RegisterCreatedEvent::class,
		RegisterUpdatedEvent::class,
		RegisterDeletedEvent::class,
		AppEnableEvent::class,
		AppDisableEvent::class,
	];

	/**
	 * Constructor.
	 *
	 * @param SemanticProviderCatalogue $catalogue The cached provider list.
	 */
	public function __construct(
		private readonly SemanticProviderCatalogue $catalogue,
	) {
	}//end __construct()

	/**
	 * Clear the list when the event is one it is built from.
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return void
	 *
	 * @listener-placement inline cheap-bounded — one cache namespace clear;
	 *   deferred, the next page load in this window would advertise the old providers.
	 *
	 * @spec openspec/changes/cross-app-semantic-references/tasks.md#task-4.1
	 */
	public function handle(Event $event): void {
		if (in_array($event::class, self::EVENTS, true) === false) {
			return;
		}

		$this->catalogue->clear();
	}//end handle()
}//end class
