<?php

/**
 * Golden test pinning how each link controller answers a service failure:
 * which exception codes pass through as the status, and that every other code
 * is a 400, always with the exception message as the error.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Controller;

use Exception;
use OCA\OpenRegister\Controller\BookmarkLinksController;
use OCA\OpenRegister\Controller\CollectiveLinksController;
use OCA\OpenRegister\Controller\DeckLinksController;
use OCA\OpenRegister\Controller\EmailLinksController;
use OCA\OpenRegister\Controller\FlowLinksController;
use OCA\OpenRegister\Controller\MapLinksController;
use OCP\AppFramework\Http\JSONResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

class LinkControllerFailureMappingGoldenTest extends TestCase {

	private const CODES = [0, 400, 401, 403, 404, 409, 500, 503];

	public static function controllers(): array {
		return [
			'bookmarks' => [BookmarkLinksController::class, [400, 404, 409, 503]],
			'collectives' => [CollectiveLinksController::class, [400, 401, 404, 409, 503]],
			'deck links' => [DeckLinksController::class, [404, 409, 503]],
			'email' => [EmailLinksController::class, [401, 403, 404, 409, 503]],
			'flows' => [FlowLinksController::class, [400, 403, 404, 409, 503]],
			'maps' => [MapLinksController::class, [400, 401, 404, 409, 503]],
		];
	}

	#[DataProvider('controllers')]
	public function testEachCodeIsAnsweredAsBefore(string $class, array $passThrough): void {
		$reflection = new ReflectionClass($class);
		$controller = $reflection->newInstanceWithoutConstructor();
		$map = $reflection->getMethod('mapException');

		foreach (self::CODES as $code) {
			$expected = 400;
			if (in_array($code, $passThrough, true) === true) {
				$expected = $code;
			}

			/** @var JSONResponse $response */
			$response = $map->invoke($controller, new Exception('upstream said ' . $code, $code));
			$this->assertSame([$expected, ['error' => 'upstream said ' . $code]], [$response->getStatus(), $response->getData()], $class . ' code ' . $code);
		}
	}
}//end class
