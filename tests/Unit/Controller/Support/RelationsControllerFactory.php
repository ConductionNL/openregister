<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller\Support;

use OCA\OpenRegister\Controller\RelationsController;

/**
 * Builds RelationsController from its services as separate arguments, wrapped into its constructor bundles.
 */
final class RelationsControllerFactory {
	/**
	 * The constructor arguments of RelationsController.
	 *
	 * @param string $appName Ignored: the controller uses Application::APP_ID.
	 * @param IRequest $request Constructor argument.
	 * @param ObjectService $objectService Constructor argument.
	 * @param NoteService $noteService Constructor argument.
	 * @param ObjectCalendarTasksInterface $taskService Constructor argument.
	 * @param EmailService $emailService Constructor argument.
	 * @param ObjectCalendarEventsInterface $calendarEventService Constructor argument.
	 * @param ContactService $contactService Constructor argument.
	 * @param DeckCardService $deckCardService Constructor argument.
	 * @param LoggerInterface $logger Constructor argument.
	 * @param ContainerInterface $container Constructor argument.
	 *
	 * @return array<int, mixed>
	 */
	public static function args(
		string $appName,
		\OCP\IRequest $request,
		\OCA\OpenRegister\Service\ObjectService $objectService,
		\OCA\OpenRegister\Files\Service\NoteService $noteService,
		\OCA\OpenRegister\Kernel\Contracts\ObjectCalendarTasksInterface $taskService,
		\OCA\OpenRegister\Notifications\Service\EmailService $emailService,
		\OCA\OpenRegister\Kernel\Contracts\ObjectCalendarEventsInterface $calendarEventService,
		\OCA\OpenRegister\Service\ContactService $contactService,
		\OCA\OpenRegister\Service\DeckCardService $deckCardService,
		\Psr\Log\LoggerInterface $logger,
		\Psr\Container\ContainerInterface $container,
	): array {
		return [
			$request,
			new \OCA\OpenRegister\Controller\Support\RelationsControllerServices(
				records: new \OCA\OpenRegister\Controller\Support\RelationsRecords(
					objectService: $objectService,
					noteService: $noteService,
					taskService: $taskService,
					emailService: $emailService,
				),
				collaboration: new \OCA\OpenRegister\Controller\Support\RelationsCollaboration(
					calendarEventService: $calendarEventService,
					contactService: $contactService,
					deckCardService: $deckCardService,
				),
				platform: new \OCA\OpenRegister\Controller\Support\RelationsPlatform(
					logger: $logger,
					container: $container,
				),
			),
		];
	}

	/**
	 * Build RelationsController; takes the same arguments as args().
	 *
	 * @param mixed ...$args Arguments for args(), positional or named.
	 *
	 * @return RelationsController
	 */
	public static function create(mixed ...$args): RelationsController {
		return new RelationsController(...self::args(...$args));
	}
}
