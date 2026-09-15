<?php

/**
 * The catalogue of bulk actions available on this instance.
 *
 * OpenRegister owns the bulk mechanism and a leaf app declares an action
 * rather than writing a loop of its own (ADR-022). Registration happens on
 * the {@see BulkActionRegistrationEvent}, dispatched lazily the first time
 * anyone asks for an action, so an app that is not installed costs nothing.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

use InvalidArgumentException;
use OCA\OpenRegister\BulkAction\BulkActionInterface;
use OCA\OpenRegister\Event\BulkActionRegistrationEvent;
use OCP\EventDispatcher\IEventDispatcher;
use Psr\Log\LoggerInterface;

/**
 * Class BulkActionRegistry
 */
class BulkActionRegistry {

	/**
	 * The registered actions, keyed by their id.
	 *
	 * @var array<string, BulkActionInterface>
	 */
	private array $actions = [];

	/**
	 * Whether the registration event has been dispatched.
	 *
	 * @var boolean
	 */
	private bool $loaded = false;

	/**
	 * Constructor.
	 *
	 * @param IEventDispatcher $eventDispatcher Event dispatcher.
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		private readonly IEventDispatcher $eventDispatcher,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Register one action.
	 *
	 * Called by apps during the BulkActionRegistrationEvent.
	 *
	 * @param BulkActionInterface $action The action to register.
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException When the id is malformed or taken.
	 *
	 * @spec openspec/changes/bulk-action-jobs/specs/bulk-action-jobs/spec.md
	 */
	public function register(BulkActionInterface $action): void {
		$id = $action->getId();

		if (preg_match('/^[a-z0-9_]+:[a-z0-9-]+$/', $id) === 0) {
			throw new InvalidArgumentException(
				"Invalid bulk action id: {$id}. Must be 'app_id:action-name'."
			);
		}

		if (isset($this->actions[$id]) === true) {
			throw new InvalidArgumentException("Bulk action already registered: {$id}");
		}

		$this->actions[$id] = $action;
	}//end register()

	/**
	 * Get one action by id.
	 *
	 * @param string $id The action id.
	 *
	 * @return BulkActionInterface The action.
	 *
	 * @throws InvalidArgumentException When no such action is registered.
	 *
	 * @spec openspec/changes/bulk-action-jobs/specs/bulk-action-jobs/spec.md
	 */
	public function get(string $id): BulkActionInterface {
		$this->load();

		if (isset($this->actions[$id]) === false) {
			throw new InvalidArgumentException("Unknown bulk action: {$id}");
		}

		return $this->actions[$id];
	}//end get()

	/**
	 * Whether an action is registered.
	 *
	 * @param string $id The action id.
	 *
	 * @return bool True when the action exists.
	 *
	 * @spec openspec/changes/bulk-action-jobs/specs/bulk-action-jobs/spec.md
	 */
	public function has(string $id): bool {
		$this->load();

		return isset($this->actions[$id]);
	}//end has()

	/**
	 * Every registered action, keyed by id.
	 *
	 * @return array<string, BulkActionInterface> The actions.
	 *
	 * @spec openspec/changes/bulk-action-jobs/specs/bulk-action-jobs/spec.md
	 */
	public function all(): array {
		$this->load();

		return $this->actions;
	}//end all()

	/**
	 * The catalogue as a consumer reads it.
	 *
	 * @return array<int, array<string, mixed>> One entry per action.
	 *
	 * @spec openspec/changes/bulk-action-jobs/specs/bulk-action-jobs/spec.md
	 */
	public function describe(): array {
		$catalogue = [];

		foreach ($this->all() as $id => $action) {
			$catalogue[] = [
				'id' => $id,
				'label' => $action->getLabel(),
				'description' => $action->getDescription(),
				'requiresJustification' => $action->requiresJustification(),
				'guards' => $action->getGuards(),
			];
		}

		return $catalogue;
	}//end describe()

	/**
	 * Dispatch the registration event once.
	 *
	 * @return void
	 */
	private function load(): void {
		if ($this->loaded === true) {
			return;
		}

		// Set before dispatching: a listener that asks the registry a
		// question must not send the event round a second time.
		$this->loaded = true;

		$this->eventDispatcher->dispatchTyped(new BulkActionRegistrationEvent(registry: $this));

		$this->logger->debug(
			message: '[BulkActionRegistry] Loaded bulk actions',
			context: ['actions' => array_keys($this->actions)]
		);
	}//end load()
}//end class
