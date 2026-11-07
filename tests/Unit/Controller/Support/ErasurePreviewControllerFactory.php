<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller\Support;

use OCA\OpenRegister\Controller\ErasurePreviewController;

/**
 * Builds ErasurePreviewController from its services as separate arguments.
 */
final class ErasurePreviewControllerFactory {
	/**
	 * The constructor arguments of ErasurePreviewController.
	 *
	 * @param string $appName Constructor argument.
	 * @param IRequest $request Constructor argument.
	 * @param ErasurePreviewService $previewService Constructor argument.
	 * @param ErasurePreviewStore $store Constructor argument.
	 * @param ErasureRunner $runner Constructor argument.
	 *
	 * @return array<int, mixed>
	 */
	public static function args(
		string $appName,
		\OCP\IRequest $request,
		\OCA\OpenRegister\Service\Gdpr\Erasure\ErasurePreviewService $previewService,
		\OCA\OpenRegister\Service\Gdpr\Erasure\ErasurePreviewStore $store,
		\OCA\OpenRegister\Service\Gdpr\Erasure\ErasureRunner $runner,
	): array {
		return [
			$appName,
			$request,
			$previewService,
			$store,
			$runner,
		];
	}//end args()

	/**
	 * Build ErasurePreviewController; takes the same arguments as args().
	 *
	 * @param mixed ...$args Arguments for args(), positional or named.
	 *
	 * @return ErasurePreviewController
	 */
	public static function create(mixed ...$args): ErasurePreviewController {
		return new ErasurePreviewController(...self::args(...$args));
	}//end create()
}//end class
