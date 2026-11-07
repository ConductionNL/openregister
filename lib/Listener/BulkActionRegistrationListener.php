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

use OCA\OpenRegister\BulkAction\ApplyRuleActionInterface;
use OCA\OpenRegister\BulkAction\AssignActionInterface;
use OCA\OpenRegister\BulkAction\ExportWholeSetAction;
use OCA\OpenRegister\BulkAction\RestorePriorValuesActionInterface;
use OCA\OpenRegister\BulkAction\RunActionActionInterface;
use OCA\OpenRegister\BulkAction\SetPropertiesActionInterface;
use OCA\OpenRegister\Event\BulkActionRegistrationEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;

/**
 * Class BulkActionRegistrationListener
 *
 * @template-implements IEventListener<BulkActionRegistrationEvent>
 *
 * @psalm-suppress UnusedClass Registered in Application::register().
 */
class BulkActionRegistrationListener implements IEventListener {
	/**
	 * Constructor.
	 *
	 * @param SetPropertiesActionInterface $setProperties The bulk attribute write.
	 * @param AssignActionInterface $assign The bulk redistribution.
	 * @param ApplyRuleActionInterface $applyRule The rule replay.
	 * @param ExportWholeSetAction $exportWholeSet The whole-set extract.
	 * @param RestorePriorValuesActionInterface $restore The inverse of a reversible job.
	 * @param RunActionActionInterface $runAction A declared object action on a large selection.
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		private readonly SetPropertiesActionInterface $setProperties,
		private readonly AssignActionInterface $assign,
		private readonly ApplyRuleActionInterface $applyRule,
		private readonly ExportWholeSetAction $exportWholeSet,
		private readonly RestorePriorValuesActionInterface $restore,
		private readonly RunActionActionInterface $runAction,
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

		$actions = [
			$this->setProperties,
			$this->assign,
			$this->applyRule,
			$this->exportWholeSet,
			$this->restore,
			$this->runAction,
		];

		foreach ($actions as $action) {
			try {
				$event->registerAction(action: $action);
			} catch (\Throwable $exception) {
				$this->logger->warning(
					message: '[BulkActionRegistration] Could not register a built-in action',
					context: ['action' => $action->getId(), 'error' => $exception->getMessage()]
				);
			}
		}
	}//end handle()
}//end class
