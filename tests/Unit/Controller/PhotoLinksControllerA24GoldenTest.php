<?php

/**
 * Golden outcomes of {@see \OCA\OpenRegister\Controller\PhotoLinksController}, pinned before the A24 refactor.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Controller;

use Exception;
use OCA\OpenRegister\Controller\PhotoLinksController;
use OCA\OpenRegister\Db\PhotoLink;
use OCA\OpenRegister\Service\PhotoLinkServiceInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;

/**
 * PhotoLinksControllerA24GoldenTest.
 */
class PhotoLinksControllerA24GoldenTest extends LinkedSourceGoldenTestCase {

	/**
	 * Photos link service mock.
	 *
	 * @var PhotoLinkServiceInterface&MockObject
	 */
	private PhotoLinkServiceInterface&MockObject $service;

	/**
	 * Controller under test.
	 *
	 * @var PhotoLinksController
	 */
	private PhotoLinksController $controller;

	protected function setUp(): void {
		parent::setUp();
		$this->service    = $this->createMock(PhotoLinkServiceInterface::class);
		$this->controller = new PhotoLinksController('openregister', $this->request, $this->service, $this->objectService);
	}

	private function link(): PhotoLink {
		$link = new PhotoLink();
		$link->setObjectUuid('abc-123');
		$link->setAlbumId(7);
		return $link;
	}

	public static function actions(): array {
		return [
			'index'         => ['index', ['r', 's', 'o']],
			'link'          => ['link', ['r', 's', 'o']],
			'createAndLink' => ['createAndLink', ['r', 's', 'o']],
			'destroy'       => ['destroy', ['r', 's', 'o', '7']],
		];
	}

	#[DataProvider('actions')]
	public function testUnavailableAnswers501BeforeResolvingTheObject(string $method, array $args): void {
		$this->service->method('isPhotosAvailable')->willReturn(false);
		$this->givenObjectNeverResolved();

		$response = $this->controller->$method(...$args);

		$this->assertResponse(501, ['error' => 'NC Photos app is not installed', 'code' => 'APP_NOT_AVAILABLE'], $response);
	}

	#[DataProvider('actions')]
	public function testMissingObjectAnswers404(string $method, array $args): void {
		$this->service->method('isPhotosAvailable')->willReturn(true);
		$this->givenNoObject();
		$this->service->expects($this->never())->method('linkAlbum');
		$this->service->expects($this->never())->method('unlinkAlbum');

		$this->assertNotFound($this->controller->$method(...$args));
	}

	#[DataProvider('actions')]
	public function testObjectLookupFailureAnswers404(string $method, array $args): void {
		$this->service->method('isPhotosAvailable')->willReturn(true);
		$this->givenObjectLookupThrows();

		$this->assertNotFound($this->controller->$method(...$args));
	}

	public function testAvailableAnswers501WhenUnavailable(): void {
		$this->service->method('isPhotosAvailable')->willReturn(false);
		$this->service->expects($this->never())->method('getAvailableAlbums');

		$this->assertResponse(501, ['error' => 'NC Photos app is not installed', 'code' => 'APP_NOT_AVAILABLE'], $this->controller->available());
	}

	public function testIndexListsAndFailureAnswers500(): void {
		$this->service->method('isPhotosAvailable')->willReturn(true);
		$this->givenObject();
		$this->service->method('getLinkedAlbums')->with('abc-123')
			->willReturnOnConsecutiveCalls([['albumId' => 1]], $this->throwException(new Exception('boom', 409)));

		$this->assertResponse(200, ['results' => [['albumId' => 1]], 'total' => 1], $this->controller->index('r', 's', 'o'));
		$this->assertResponse(500, ['error' => 'boom'], $this->controller->index('r', 's', 'o'));
	}

	public function testLinkRequiresAlbumId(): void {
		$this->service->method('isPhotosAvailable')->willReturn(true);
		$this->givenObject();
		$this->params = ['albumId' => 'abc'];

		$this->assertResponse(400, ['error' => 'albumId is required'], $this->controller->link('r', 's', 'o'));
	}

	public function testLinkCreates201(): void {
		$this->service->method('isPhotosAvailable')->willReturn(true);
		$this->givenObject();
		$this->params = ['albumId' => '7'];
		$link         = $this->link();
		$this->service->expects($this->once())->method('linkAlbum')->with($this->equalTo($this->target()), 7)->willReturn($link);

		$this->assertResponse(201, $link->jsonSerialize(), $this->controller->link('r', 's', 'o'));
	}

	public function testCreateAndLinkRequiresName(): void {
		$this->service->method('isPhotosAvailable')->willReturn(true);
		$this->givenObject();
		$this->params = ['name' => '   '];

		$this->assertResponse(400, ['error' => 'name is required'], $this->controller->createAndLink('r', 's', 'o'));
	}

	public function testCreateAndLinkCreates201(): void {
		$this->service->method('isPhotosAvailable')->willReturn(true);
		$this->givenObject();
		$this->params = ['name' => ' Trip '];
		$link         = $this->link();
		$this->service->expects($this->once())->method('createAndLinkAlbum')->with($this->equalTo($this->target()), ' Trip ')->willReturn($link);

		$this->assertResponse(201, $link->jsonSerialize(), $this->controller->createAndLink('r', 's', 'o'));
	}

	public function testDestroyUnlinks(): void {
		$this->service->method('isPhotosAvailable')->willReturn(true);
		$this->givenObject();
		$this->service->expects($this->once())->method('unlinkAlbum')->with('abc-123', 7);

		$this->assertResponse(200, ['success' => true], $this->controller->destroy('r', 's', 'o', '7'));
	}

	public static function codes(): array {
		return [[400, 400], [401, 401], [404, 404], [409, 409], [503, 503], [502, 400], [500, 400], [0, 400]];
	}

	#[DataProvider('codes')]
	public function testFailuresAreMappedByCode(int $code, int $status): void {
		$this->service->method('isPhotosAvailable')->willReturn(true);
		$this->givenObject();
		$this->service->method('unlinkAlbum')->willThrowException(new Exception('nope', $code));

		$this->assertResponse($status, ['error' => 'nope'], $this->controller->destroy('r', 's', 'o', '7'));
	}

	public function testAvailablePassesSearchAsString(): void {
		$this->service->method('isPhotosAvailable')->willReturn(true);
		$this->params = ['search' => 12];
		$this->service->expects($this->once())->method('getAvailableAlbums')->with('12')->willReturn([['id' => 1], ['id' => 2]]);

		$this->assertResponse(200, ['results' => [['id' => 1], ['id' => 2]], 'total' => 2], $this->controller->available());
	}

	public function testAvailablePassesNullSearch(): void {
		$this->service->method('isPhotosAvailable')->willReturn(true);
		$this->service->expects($this->once())->method('getAvailableAlbums')->with(null)->willReturn([]);

		$this->assertResponse(200, ['results' => [], 'total' => 0], $this->controller->available());
	}
}//end class
