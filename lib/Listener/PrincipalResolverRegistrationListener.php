<?php

/**
 * OpenRegister contributes the two principal kinds every instance has.
 *
 * `user` and `group` are Nextcloud's own, so they belong to the engine rather
 * than to any app. Everything else — a position on a body, a function, a case
 * role — is contributed by the app that owns the concept, through the same
 * event this uses. Registering the built-ins the same way means the
 * contribution path is exercised by its owner and cannot rot unnoticed.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Listener
 * @package  OCA\OpenRegister\Listener
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/flow-typed-principals/specs/flow-typed-principals/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Listener;

use OCA\OpenRegister\Service\Flow\Principal\GroupPrincipalResolver;
use OCA\OpenRegister\Service\Flow\Principal\RegisterPrincipalResolversEvent;
use OCA\OpenRegister\Service\Flow\Principal\UserPrincipalResolver;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;

/**
 * Registers the built-in principal resolvers.
 *
 * @template-implements IEventListener<Event>
 */
class PrincipalResolverRegistrationListener implements IEventListener {

	/**
	 * Constructor.
	 *
	 * @param UserPrincipalResolver  $users  Resolves `user` references.
	 * @param GroupPrincipalResolver $groups Resolves `group` references.
	 */
	public function __construct(
		private readonly UserPrincipalResolver $users,
		private readonly GroupPrincipalResolver $groups,
	) {

	}//end __construct()

	/**
	 * Contribute the built-ins.
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/flow-typed-principals/specs/flow-typed-principals/spec.md
	 */
	public function handle(Event $event): void {
		if (($event instanceof RegisterPrincipalResolversEvent) === false) {
			return;
		}

		$event->registerResolver(resolver: $this->users);
		$event->registerResolver(resolver: $this->groups);

	}//end handle()
}//end class
