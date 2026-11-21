<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller\Support;

use OCA\OpenRegister\Notifications\Controller\NotesController;

/**
 * Builds NotesController from its services as separate arguments, wrapped into its constructor bundles.
 */
final class NotesControllerFactory {
	/**
	 * The constructor arguments of NotesController.
	 *
	 * @param IRequest $request Constructor argument.
	 * @param NoteService $noteService Constructor argument.
	 * @param ObjectService $objectService Constructor argument.
	 * @param TimelineVisibilityService $visibility Constructor argument.
	 * @param TimelineWriteService $timeline Constructor argument.
	 *
	 * @return array<int, mixed>
	 */
	public static function args(
		\OCP\IRequest $request,
		\OCA\OpenRegister\Files\Service\NoteService $noteService,
		\OCA\OpenRegister\Service\ObjectService $objectService,
		\OCA\OpenRegister\Notifications\Service\TimelineVisibilityService $visibility,
		\OCA\OpenRegister\Notifications\Service\Timeline\TimelineWriteService $timeline,
	): array {
		return [
			$request,
			new \OCA\OpenRegister\Notifications\Controller\Support\NotesControllerServices(
				noteService: $noteService,
				objectService: $objectService,
				visibility: $visibility,
				timeline: $timeline,
			),
		];
	}

	/**
	 * Build NotesController; takes the same arguments as args().
	 *
	 * @param mixed ...$args Arguments for args(), positional or named.
	 *
	 * @return NotesController
	 */
	public static function create(mixed ...$args): NotesController {
		return new NotesController(...self::args(...$args));
	}
}
