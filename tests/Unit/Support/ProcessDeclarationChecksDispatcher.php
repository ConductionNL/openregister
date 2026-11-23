<?php

/**
 * OpenRegister ProcessDeclarationChecksDispatcher
 *
 * An event dispatcher that answers the schema declaration checks event the way
 * the app does, through every listener the areas register for it, so a hand-built
 * SchemaMapper runs the checks Processes, Compliance, Integrations, Notifications,
 * Configuration and Search contribute.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Support
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Support;

use OCA\OpenRegister\Db\SchemaMapper\SchemaDeclarationChecksEvent;
use OCA\OpenRegister\Listener\ArchivalDeclarationChecksListener;
use OCA\OpenRegister\Listener\HandoffDeclarationChecksListener;
use OCA\OpenRegister\Listener\ReferenceCardDeclarationCheckListener;
use OCA\OpenRegister\Listener\SchemaDeclarationChecksListener;
use OCA\OpenRegister\Listener\TeamResourcesDeclarationCheckListener;
use OCA\OpenRegister\Mcp\Listener\McpDeclarationChecksListener;
use OCA\OpenRegister\Listener\UniqueHintDeclarationCheckListener;
use OCA\OpenRegister\Notifications\Listener\NotificationDeclarationCheckListener;
use OCA\OpenRegister\Search\Listener\AggregationDeclarationCheckListener;
use OCA\OpenRegister\Search\Listener\SearchDeclarationChecksListener;
use OCA\OpenRegister\Service\Aggregation\WidgetAnnotationValidator;
use OCA\OpenRegister\Service\ObjectAction\ObjectActionValidatorInterface;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\EventDispatcher\IEventListener;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * Contributes the declaration checks the areas add, as the app does; every other event is recorded and dropped.
 */
final class ProcessDeclarationChecksDispatcher implements IEventDispatcher, ContainerInterface {

	/**
	 * The events dispatched, other than the checks collection.
	 *
	 * @var array<int, Event>
	 */
	public array $dispatched = [];

	/**
	 * Constructor.
	 *
	 * @param ObjectActionValidatorInterface|null $objectActions The action validator the declared action check asks; none checks the shape only.
	 * @param LoggerInterface|null                $logger        The logger the checks report to.
	 */
	public function __construct(
		private readonly ?ObjectActionValidatorInterface $objectActions = null,
		private readonly ?LoggerInterface $logger = null,
	) {
	}//end __construct()

	/**
	 * Ignored: this dispatcher has no listeners of its own.
	 *
	 * @param string   $eventName The event.
	 * @param callable $listener  The listener.
	 * @param int      $priority  The priority.
	 *
	 * @return void
	 */
	public function addListener(string $eventName, callable $listener, int $priority = 0): void {
	}//end addListener()

	/**
	 * Ignored.
	 *
	 * @param string   $eventName The event.
	 * @param callable $listener  The listener.
	 *
	 * @return void
	 */
	public function removeListener(string $eventName, callable $listener): void {
	}//end removeListener()

	/**
	 * Ignored.
	 *
	 * @param string $eventName The event.
	 * @param string $className The listener class.
	 * @param int    $priority  The priority.
	 *
	 * @return void
	 */
	public function addServiceListener(string $eventName, string $className, int $priority = 0): void {
	}//end addServiceListener()

	/**
	 * Only the checks collection has a listener.
	 *
	 * @param string $eventName The event.
	 *
	 * @return bool
	 */
	public function hasListeners(string $eventName): bool {
		return $eventName === SchemaDeclarationChecksEvent::class;
	}//end hasListeners()

	/**
	 * Dispatch by name, as dispatchTyped does.
	 *
	 * @param string $eventName The event name.
	 * @param Event  $event     The event.
	 *
	 * @return void
	 */
	public function dispatch(string $eventName, Event $event): void {
		$this->dispatchTyped(event: $event);
	}//end dispatch()

	/**
	 * Every listener the app registers for the schema declaration checks event.
	 *
	 * Processes, Compliance, Integrations, Notifications, Configuration and Search
	 * each contribute their checks from their own registrar; a hand-built
	 * SchemaMapper runs all of them.
	 *
	 * @param LoggerInterface    $logger    The logger the listeners receive.
	 * @param ContainerInterface $container The container the process checks read.
	 *
	 * @return list<IEventListener<SchemaDeclarationChecksEvent>>
	 */
	public static function listeners(LoggerInterface $logger, ContainerInterface $container): array {
		return [
			new SchemaDeclarationChecksListener(logger: $logger, container: $container),
			new ArchivalDeclarationChecksListener(logger: $logger),
			new HandoffDeclarationChecksListener(),
			new NotificationDeclarationCheckListener(logger: $logger),
			new McpDeclarationChecksListener(),
			new ReferenceCardDeclarationCheckListener(),
			new TeamResourcesDeclarationCheckListener(),
			new UniqueHintDeclarationCheckListener(),
			new AggregationDeclarationCheckListener(logger: $logger),
			new SearchDeclarationChecksListener(widgets: new WidgetAnnotationValidator()),
		];
	}//end listeners()

	/**
	 * Contribute every area's checks to the collection event; record every other event.
	 *
	 * @param Event $event The event.
	 *
	 * @return void
	 */
	public function dispatchTyped(Event $event): void {
		if ($event instanceof SchemaDeclarationChecksEvent) {
			foreach (self::listeners(logger: ($this->logger ?? new NullLogger()), container: $this) as $listener) {
				$listener->handle(event: $event);
			}

			return;
		}

		$this->dispatched[] = $event;
	}//end dispatchTyped()

	/**
	 * The action validator, for the listener's lazy lookup.
	 *
	 * @param string $id The service id.
	 *
	 * @return mixed
	 */
	public function get(string $id): mixed {
		if ($id === ObjectActionValidatorInterface::class) {
			return $this->objectActions;
		}

		throw new class ('No ' . $id . ' in this test') extends RuntimeException implements NotFoundExceptionInterface {
		};
	}//end get()

	/**
	 * Whether the action validator is given.
	 *
	 * @param string $id The service id.
	 *
	 * @return bool
	 */
	public function has(string $id): bool {
		return $id === ObjectActionValidatorInterface::class && $this->objectActions !== null;
	}//end has()
}//end class
