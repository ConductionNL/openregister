<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller\Support;

use OCA\OpenRegister\Notifications\Controller\TimelineEntriesController;

/**
 * Builds TimelineEntriesController from its services as separate arguments, wrapped into its constructor bundles.
 */
final class TimelineEntriesControllerFactory {
	/**
	 * The constructor arguments of TimelineEntriesController.
	 *
	 * @param IRequest $request Constructor argument.
	 * @param ObjectService $objects Constructor argument.
	 * @param TimelineEntryService $entries Constructor argument.
	 * @param TimelineWriteService $writer Constructor argument.
	 * @param TimelineEntrySearchService $search Constructor argument.
	 * @param ReferenceService $references Constructor argument.
	 * @param TimelineVisibilityService $visibility Constructor argument.
	 * @param LoggerInterface $logger Constructor argument.
	 *
	 * @return array<int, mixed>
	 */
	public static function args(
		\OCP\IRequest $request,
		\OCA\OpenRegister\Service\ObjectService $objects,
		\OCA\OpenRegister\Notifications\Service\Timeline\TimelineEntryService $entries,
		\OCA\OpenRegister\Notifications\Service\Timeline\TimelineWriteService $writer,
		\OCA\OpenRegister\Notifications\Service\Timeline\TimelineEntrySearchService $search,
		\OCA\OpenRegister\Notifications\Service\Timeline\ReferenceService $references,
		\OCA\OpenRegister\Notifications\Service\TimelineVisibilityService $visibility,
		\Psr\Log\LoggerInterface $logger,
	): array {
		return [
			$request,
			new \OCA\OpenRegister\Notifications\Controller\Support\TimelineEntriesControllerServices(
				domain: new \OCA\OpenRegister\Notifications\Controller\Support\TimelineEntriesDomain(
					objects: $objects,
					entries: $entries,
					writer: $writer,
					search: $search,
				),
				visibility: new \OCA\OpenRegister\Notifications\Controller\Support\TimelineEntriesVisibility(
					references: $references,
					visibility: $visibility,
					logger: $logger,
				),
			),
		];
	}

	/**
	 * Build TimelineEntriesController; takes the same arguments as args().
	 *
	 * @param mixed ...$args Arguments for args(), positional or named.
	 *
	 * @return TimelineEntriesController
	 */
	public static function create(mixed ...$args): TimelineEntriesController {
		return new TimelineEntriesController(...self::args(...$args));
	}
}
