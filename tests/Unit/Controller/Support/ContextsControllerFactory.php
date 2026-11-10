<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller\Support;

use OCA\OpenRegister\Controller\ContextsController;

/**
 * Builds ContextsController from its services as separate arguments, wrapped into its constructor bundle.
 */
final class ContextsControllerFactory {
	/**
	 * The constructor arguments of ContextsController.
	 *
	 * @param IRequest $request Constructor argument.
	 * @param SchemaCatalogInterface $catalog Constructor argument.
	 * @param JsonLdContextServiceInterface $contextService Constructor argument.
	 *
	 * @return array<int, mixed>
	 */
	public static function args(
		\OCP\IRequest $request,
		\OCA\OpenRegister\Db\SchemaCatalogInterface $catalog,
		\OCA\OpenRegister\Service\JsonLd\JsonLdContextServiceInterface $contextService,
	): array {
		return [
			$request,
			new \OCA\OpenRegister\Controller\Support\ContextsControllerServices(
				catalog: $catalog,
				contextService: $contextService,
			),
		];
	}//end args()

	/**
	 * Build ContextsController; takes the same arguments as args().
	 *
	 * @param mixed ...$args Arguments for args(), positional or named.
	 *
	 * @return ContextsController
	 */
	public static function create(mixed ...$args): ContextsController {
		return new ContextsController(...self::args(...$args));
	}//end create()
}//end class
