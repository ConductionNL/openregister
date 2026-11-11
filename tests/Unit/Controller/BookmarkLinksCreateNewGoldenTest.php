<?php

/**
 * Golden test pinning BookmarkLinksController::createNew: the exact refusals
 * and the draft it hands the service (tags narrowed to strings).
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Controller;

use OCA\OpenRegister\Controller\BookmarkLinksController;
use OCA\OpenRegister\Controller\Support\BookmarkLinksControllerServices;
use OCA\OpenRegister\Db\BookmarkLink;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\BookmarkDraft;
use OCA\OpenRegister\Service\BookmarkLinkService;
use OCA\OpenRegister\Service\LinkTarget;
use OCA\OpenRegister\Service\ObjectService;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

class BookmarkLinksCreateNewGoldenTest extends TestCase {

	private array $drafts = [];

	private function controller(array $params): BookmarkLinksController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static fn (string $key, mixed $default = null): mixed => (array_key_exists($key, $params) === true ? $params[$key] : $default)
		);

		$object = new ObjectEntity();
		$object->setUuid('abc-123');
		$object->setRegister(1);
		$object->setSchema(2);
		$objects = $this->createMock(ObjectService::class);
		$objects->method('setSchema')->willReturnSelf();
		$objects->method('setRegister')->willReturnSelf();
		$objects->method('setObject')->willReturnSelf();
		$objects->method('getObject')->willReturn($object);

		$service = $this->createMock(BookmarkLinkService::class);
		$service->method('isBookmarksAvailable')->willReturn(true);
		$service->method('createAndLinkBookmark')->willReturnCallback(
			function (LinkTarget $target, BookmarkDraft $draft): BookmarkLink {
				$this->drafts[] = [$target->objectUuid, $draft->title, $draft->url, $draft->description(), $draft->tags()];
				$link = new BookmarkLink();
				$link->setBookmarkId(456);
				return $link;
			}
		);

		return new BookmarkLinksController(
			$request,
			new BookmarkLinksControllerServices(bookmarkLinkService: $service, objectService: $objects)
		);
	}

	public function testTheRefusalsAnswerExactBodiesAndCreateNothing(): void {
		$noTitle = $this->controller(['title' => ' ', 'url' => 'https://x.nl'])->createNew('r', 's', 'o');
		$this->assertSame([400, ['error' => 'title is required']], [$noTitle->getStatus(), $noTitle->getData()]);

		$noUrl = $this->controller(['title' => 'T'])->createNew('r', 's', 'o');
		$this->assertSame([400, ['error' => 'url is required']], [$noUrl->getStatus(), $noUrl->getData()]);

		$this->assertSame([], $this->drafts);
	}

	public function testTheDraftKeepsOnlyTextTagsAndDefaultsTheDescription(): void {
		$this->controller(
			['title' => 'Conduction', 'url' => 'https://conduction.nl', 'description' => 'Company', 'tags' => ['vendor', 3, null, 'nl']]
		)->createNew('r', 's', 'o');
		$this->controller(['title' => 'C', 'url' => 'https://c.nl', 'tags' => 'vendor'])->createNew('r', 's', 'o');
		$response = $this->controller(['title' => 'C', 'url' => 'https://c.nl'])->createNew('r', 's', 'o');

		$this->assertSame(201, $response->getStatus());
		$this->assertSame(
			[
				['abc-123', 'Conduction', 'https://conduction.nl', 'Company', ['vendor', 'nl']],
				['abc-123', 'C', 'https://c.nl', '', []],
				['abc-123', 'C', 'https://c.nl', '', []],
			],
			$this->drafts
		);
	}
}//end class
