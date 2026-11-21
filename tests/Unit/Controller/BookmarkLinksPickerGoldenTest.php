<?php

/**
 * Golden test pinning BookmarkLinksController::available: the optional search
 * and the tags filter, given as a comma list or as a list.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Controller;

use OCA\OpenRegister\Controller\BookmarkLinksController;
use OCA\OpenRegister\Controller\Support\BookmarkLinksControllerServices;
use OCA\OpenRegister\Service\BookmarkLinkService;
use OCA\OpenRegister\Service\ObjectService;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

class BookmarkLinksPickerGoldenTest extends TestCase {

	private array $asked = [];

	private function controller(array $params): BookmarkLinksController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static fn (string $key, mixed $default = null): mixed => (array_key_exists($key, $params) === true ? $params[$key] : $default)
		);

		$service = $this->createMock(BookmarkLinkService::class);
		$service->method('isBookmarksAvailable')->willReturn(true);
		$service->method('getAvailableBookmarks')->willReturnCallback(
			function (?string $search, ?array $tags): array {
				$this->asked[] = [$search, $tags];
				return [['id' => 1]];
			}
		);

		return new BookmarkLinksController(
			$request,
			new BookmarkLinksControllerServices(bookmarkLinkService: $service, objectService: $this->createMock(ObjectService::class))
		);
	}

	public function testTheSearchAndTagsReachThePickerAsTheyWereRead(): void {
		$response = $this->controller([])->available();
		$this->controller(['search' => 12, 'tags' => ' a, ,b ,'])->available();
		$this->controller(['tags' => ''])->available();
		$this->controller(['tags' => ['x', 3, 'y', null]])->available();
		$this->controller(['tags' => 7])->available();

		$this->assertSame([200, ['results' => [['id' => 1]], 'total' => 1]], [$response->getStatus(), $response->getData()]);
		$this->assertSame(
			[
				[null, null],
				['12', ['a', 'b']],
				[null, null],
				[null, ['x', 'y']],
				[null, null],
			],
			$this->asked
		);
	}
}//end class
