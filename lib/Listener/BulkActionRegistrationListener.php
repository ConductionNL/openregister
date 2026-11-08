<?php

/**
 * Registers OpenRegister's own bulk actions on the registration event.
 *
 * The platform's two actions go through the same door a leaf app uses, so
 * the door is exercised by every instance rather than only by whoever ships
 * the first external action.
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
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Listener;

use OCA\OpenRegister\BulkAction\BulkActionInterface;
use OCA\OpenRegister\Event\BulkActionRegistrationEvent;
use OCA\OpenRegister\Listener\Support\BulkEditActions;
use OCA\OpenRegister\Listener\Support\BulkSetActions;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;

/**
 * Class BulkActionRegistrationListener
 *
 * @template-implements IEventListener<BulkActionRegistrationEvent>
 *
 * @spec openspec/specs/bulk-action-jobs/spec.md
 *
 * @psalm-suppress UnusedClass Registered in Application::register().
 */
class BulkActionRegistrationListener implements IEventListener {
	/**
	 * Constructor.
	 *
	 * @param BulkEditActions $editing The bulk attribute write, the redistribution and the rule replay.
	 * @param BulkSetActions $sets The whole-set extract, the inverse of a reversible job, and a declared action on a selection.
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		private readonly BulkEditActions $editing,
		private readonly BulkSetActions $sets,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Register the built-in actions.
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/bulk-action-jobs/spec.md
	 */
	public function handle(Event $event): void {
		if (($event instanceof BulkActionRegistrationEvent) === false) {
			return;
		}

		foreach ($this->builtIns() as $action) {
			$this->register(event: $event, action: $action);
		}
	}//end handle()

	/**
	 * The built-in actions, in the order they are registered.
	 *
	 * @return array<int, BulkActionInterface>
	 *
	 * @spec openspec/specs/bulk-action-jobs/spec.md
	 */
	private function builtIns(): array {
		return [
			$this->editing->setProperties,
			$this->editing->assign,
			$this->editing->applyRule,
			$this->sets->exportWholeSet,
			$this->sets->restore,
			$this->sets->runAction,
		];
	}//end builtIns()

	/**
	 * Register one action; one that is refused is logged and skipped.
	 *
	 * @param BulkActionRegistrationEvent $event  The registration event.
	 * @param BulkActionInterface         $action The action.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/bulk-action-jobs/spec.md
	 */
	private function register(BulkActionRegistrationEvent $event, BulkActionInterface $action): void {
		try {
			$event->registerAction(action: $action);
		} catch (\Throwable $exception) {
			$this->logger->warning(
				message: '[BulkActionRegistration] Could not register a built-in action',
				context: ['action' => $action->getId(), 'error' => $exception->getMessage()]
			);
		}
	}//end register()
}//end class
