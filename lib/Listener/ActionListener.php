<?php

/**
 * OpenRegister ActionListener
 *
 * Listener that delegates event handling to ActionExecutor for matching actions.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Listener
 * @package  OCA\OpenRegister\Listener
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/specs/actions/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Listener;

use OCA\OpenRegister\Db\ActionMapper;
use OCA\OpenRegister\Service\ActionExecutor;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;

/**
 * ActionListener handles events by finding and executing matching actions
 *
 * Registered for ALL event types in Application::registerEventListeners().
 * Coexists with HookListener (inline hooks execute first).
 *
 * @template-implements IEventListener<Event>
 *
 * @SuppressWarnings(PHPMD.CyclomaticComplexity)
 */
class ActionListener implements IEventListener {
	/**
	 * Constructor
	 *
	 * @param ActionMapper $actionMapper Action mapper for finding matching actions
	 * @param ActionExecutor $actionExecutor Action executor for running actions
	 * @param LoggerInterface $logger Logger
	 *
	 * @spec openspec/specs/event-driven-architecture/spec.md
	 */
	public function __construct(
		private readonly ActionMapper $actionMapper,
		private readonly ActionExecutor $actionExecutor,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Handle event by finding and executing matching actions
	 *
	 * @param Event $event The lifecycle event
	 *
	 * @return void
	 *
	 * @spec openspec/specs/event-driven-architecture/spec.md
	 */
	public function handle(Event $event): void {
		// Respect propagation stop from inline hooks or previous listeners.
		if ($event->isPropagationStopped() === true) {
			$this->logger->debug(
				message: '[ActionListener] Propagation already stopped, skipping action execution'
			);
			return;
		}

		try {
			// Determine event type from class name (short name).
			$eventType = $this->getEventTypeName(event: $event);

			// Extract payload from event.
			$payload = $this->extractPayload(event: $event);
			$schemaUuid = $payload['schemaUuid'] ?? null;
			$registerUuid = $payload['registerUuid'] ?? null;

			// Find matching actions.
			$actions = $this->actionMapper->findMatchingActions(
				eventType: $eventType,
				schemaUuid: $schemaUuid,
				registerUuid: $registerUuid
			);

			if (empty($actions) === true) {
				return;
			}

			// Apply filter_condition matching.
			$filteredActions = $this->applyFilterConditions(actions: $actions, payload: $payload);

			if (empty($filteredActions) === true) {
				return;
			}

			$this->logger->debug(
				message: '[ActionListener] Executing actions for event',
				context: [
					'eventType' => $eventType,
					'actionCount' => count($filteredActions),
				]
			);

			// Delegate to ActionExecutor.
			$this->actionExecutor->executeActions(
				actions: $filteredActions,
				event: $event,
				payload: $payload,
				eventType: $eventType
			);
		} catch (\Throwable $e) {
			// Never let listener failures affect other listeners. Catch
			// \Throwable (not just Exception) so a PHP Error raised inside a
			// listener — e.g. a type mismatch on an event getter — cannot
			// bubble up and turn an otherwise-successful object operation into
			// a 500.
			$this->logger->error(
				message: '[ActionListener] Error handling event',
				context: [
					'error' => $e->getMessage(),
					'eventType' => get_class($event),
				]
			);
		}//end try
	}//end handle()

	/**
	 * Get the short event type name from an event class
	 *
	 * @param Event $event The event
	 *
	 * @return string Short class name (e.g., 'ObjectCreatingEvent')
	 *
	 * @spec openspec/specs/event-driven-architecture/spec.md
	 */
	private function getEventTypeName(Event $event): string {
		$class = get_class($event);
		$parts = explode('\\', $class);

		return end($parts);
	}//end getEventTypeName()

	/**
	 * Extract payload data from an event
	 *
	 * @param Event $event The event
	 *
	 * @return array Payload data
	 *
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity)
	 * @SuppressWarnings(PHPMD.NPathComplexity)
	 *
	 * @spec openspec/specs/event-driven-architecture/spec.md
	 */
	private function extractPayload(Event $event): array {
		$payload = [];

		// Object events.
		if (method_exists($event, 'getObject') === true) {
			$object = $event->getObject();
			if ($object !== null) {
				$payload['object'] = $object->jsonSerialize();
				$payload['schemaUuid'] = $object->getSchema() ?? null;
				$payload['registerUuid'] = $object->getRegister() ?? null;
			}
		}

		// For update events, try to get the new object.
		if (method_exists($event, 'getNewObject') === true) {
			$newObject = $event->getNewObject();
			if ($newObject !== null) {
				$payload['object'] = $newObject->jsonSerialize();
				$payload['schemaUuid'] = $newObject->getSchema() ?? null;
				$payload['registerUuid'] = $newObject->getRegister() ?? null;
			}
		}

		// Register events. Some events (e.g. ObjectTransitionedEvent) expose
		// getRegister() as a plain string id/uuid rather than a Register entity;
		// guard the type so calling jsonSerialize() on a string never fatals
		// (Error, not Exception, so the handle() try/catch would not absorb it).
		if (method_exists($event, 'getRegister') === true) {
			$register = $event->getRegister();
			if (is_object($register) === true) {
				$payload['register'] = $register->jsonSerialize();
				$payload['registerUuid'] = $register->getUuid() ?? null;
			} elseif (is_string($register) === true && $register !== '') {
				$payload['registerUuid'] = $register;
			}
		}

		// Schema events. As above, ObjectTransitionedEvent's getSchema() is a
		// string id/uuid, not a Schema entity.
		if (method_exists($event, 'getSchema') === true) {
			$schema = $event->getSchema();
			if (is_object($schema) === true) {
				$payload['schema'] = $schema->jsonSerialize();
				$payload['schemaUuid'] = $schema->getUuid() ?? null;
			} elseif (is_string($schema) === true && $schema !== '') {
				$payload['schemaUuid'] = $schema;
			}
		}

		// Action events. ObjectTransitionedEvent's getAction() is the string
		// transition name (e.g. 'park'), not an Action entity.
		if (method_exists($event, 'getAction') === true) {
			$action = $event->getAction();
			if (is_object($action) === true) {
				$payload['action'] = $action->jsonSerialize();
			} elseif (is_string($action) === true && $action !== '') {
				$payload['action'] = $action;
			}
		}

		// Source events.
		if (method_exists($event, 'getSource') === true) {
			$source = $event->getSource();
			if (is_object($source) === true) {
				$payload['source'] = $source->jsonSerialize();
			}
		}

		// Configuration events.
		if (method_exists($event, 'getConfiguration') === true) {
			$configuration = $event->getConfiguration();
			if (is_object($configuration) === true) {
				$payload['configuration'] = $configuration->jsonSerialize();
			}
		}

		return $payload;
	}//end extractPayload()

	/**
	 * Apply filter_condition matching against the payload
	 *
	 * @param array $actions Array of actions to filter
	 * @param array $payload Event payload
	 *
	 * @return array Filtered actions that match their filter conditions
	 *
	 * @spec openspec/specs/event-driven-architecture/spec.md
	 */
	private function applyFilterConditions(array $actions, array $payload): array {
		return array_values(
			array_filter(
				$actions,
				function ($action) use ($payload) {
					$conditions = $action->getFilterConditionArray();

					if (empty($conditions) === true) {
						return true;
					}

					foreach ($conditions as $key => $expected) {
						$actual = $this->getNestedValue(data: $payload, key: $key);

						if (is_array($expected) === true) {
							if (in_array($actual, $expected) === false) {
								return false;
							}
						} elseif ($actual !== $expected) {
							return false;
						}
					}

					return true;
				}
			)
		);
	}//end applyFilterConditions()

	/**
	 * Get a nested value from an array using dot notation
	 *
	 * @param array $data Array to search
	 * @param string $key Dot-notation key
	 *
	 * @return mixed The value or null
	 *
	 * @spec openspec/specs/event-driven-architecture/spec.md
	 */
	private function getNestedValue(array $data, string $key): mixed {
		$keys = explode('.', $key);

		foreach ($keys as $segment) {
			if (is_array($data) === false || array_key_exists($segment, $data) === false) {
				return null;
			}

			$data = $data[$segment];
		}

		return $data;
	}//end getNestedValue()
}//end class
