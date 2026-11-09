<?php

/**
 * Golden outcomes of {@see \OCA\OpenRegister\Controller\TalkLinksController}, pinned before the A24 refactor.
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
use OCA\OpenRegister\Controller\TalkLinksController;
use OCA\OpenRegister\Db\TalkLink;
use OCA\OpenRegister\Service\TalkLinkServiceInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;

/**
 * TalkLinksControllerA24GoldenTest.
 */
class TalkLinksControllerA24GoldenTest extends LinkedSourceGoldenTestCase {

	/**
	 * Talk link service mock.
	 *
	 * @var TalkLinkServiceInterface&MockObject
	 */
	private TalkLinkServiceInterface&MockObject $service;

	/**
	 * Controller under test.
	 *
	 * @var TalkLinksController
	 */
	private TalkLinksController $controller;

	protected function setUp(): void {
		parent::setUp();
		$this->service    = $this->createMock(TalkLinkServiceInterface::class);
		$this->controller = new TalkLinksController('openregister', $this->request, $this->service, $this->objectService);
	}

	private function link(): TalkLink {
		$link = new TalkLink();
		$link->setObjectUuid('abc-123');
				return $link;
	}

	public static function actions(): array {
		return [
			'index'         => ['index', ['r', 's', 'o']],
			'link'          => ['link', ['r', 's', 'o']],
			'createNew'     => ['createNew', ['r', 's', 'o']],
			'destroy'       => ['destroy', ['r', 's', 'o', 'tok']],
		];
	}

	#[DataProvider('actions')]
	public function testUnavailableAnswers501BeforeResolvingTheObject(string $method, array $args): void {
		$this->service->method('isTalkAvailable')->willReturn(false);
		$this->givenObjectNeverResolved();

		$response = $this->controller->$method(...$args);

		$this->assertResponse(501, ['error' => 'Nextcloud Talk app is not installed', 'code' => 'APP_NOT_AVAILABLE'], $response);
	}

	#[DataProvider('actions')]
	public function testMissingObjectAnswers404(string $method, array $args): void {
		$this->service->method('isTalkAvailable')->willReturn(true);
		$this->givenNoObject();
		$this->service->expects($this->never())->method('linkRoom');
		$this->service->expects($this->never())->method('unlinkRoom');

		$this->assertNotFound($this->controller->$method(...$args));
	}

	#[DataProvider('actions')]
	public function testObjectLookupFailureAnswers404(string $method, array $args): void {
		$this->service->method('isTalkAvailable')->willReturn(true);
		$this->givenObjectLookupThrows();

		$this->assertNotFound($this->controller->$method(...$args));
	}

	public function testRoomsAnswers501WhenUnavailable(): void {
		$this->service->method('isTalkAvailable')->willReturn(false);
		$this->service->expects($this->never())->method('getAvailableRoomsForUser');

		$this->assertResponse(501, ['error' => 'Nextcloud Talk app is not installed', 'code' => 'APP_NOT_AVAILABLE'], $this->controller->rooms());
	}

	public function testIndexListsAndFailureAnswers500(): void {
		$this->service->method('isTalkAvailable')->willReturn(true);
		$this->givenObject();
		$this->service->method('getLinkedRooms')->with('abc-123')
			->willReturnOnConsecutiveCalls([['roomToken' => 't1']], $this->throwException(new Exception('boom', 409)));

		$this->assertResponse(200, ['results' => [['roomToken' => 't1']], 'total' => 1], $this->controller->index('r', 's', 'o'));
		$this->assertResponse(500, ['error' => 'boom'], $this->controller->index('r', 's', 'o'));
	}

	public function testLinkRequiresRoomToken(): void {
		$this->service->method('isTalkAvailable')->willReturn(true);
		$this->givenObject();
		$this->service->expects($this->never())->method('linkRoom');

		$this->assertResponse(400, ['error' => 'roomToken is required'], $this->controller->link('r', 's', 'o'));
	}

	public function testLinkKeepsAWhitespaceRoomToken(): void {
		$this->service->method('isTalkAvailable')->willReturn(true);
		$this->givenObject();
		$this->params = ['roomToken' => ' '];
		$link         = $this->link();
		$this->service->expects($this->once())->method('linkRoom')->with('abc-123', 1, 2, ' ')->willReturn($link);

		$this->assertResponse(201, $link->jsonSerialize(), $this->controller->link('r', 's', 'o'));
	}

	public function testCreateNewRequiresName(): void {
		$this->service->method('isTalkAvailable')->willReturn(true);
		$this->givenObject();
		$this->params = ['name' => '   '];
		$this->service->expects($this->never())->method('createAndLinkRoom');

		$this->assertResponse(400, ['error' => 'name is required'], $this->controller->createNew('r', 's', 'o'));
	}

	public function testCreateNewDefaults(): void {
		$this->service->method('isTalkAvailable')->willReturn(true);
		$this->givenObject();
		$this->params = ['name' => ' Room '];
		$link         = $this->link();
		$this->service->expects($this->once())->method('createAndLinkRoom')->with('abc-123', 1, 2, 'Room', null, 2)->willReturn($link);

		$this->assertResponse(201, $link->jsonSerialize(), $this->controller->createNew('r', 's', 'o'));
	}

	public function testCreateNewPassesDescriptionAndType(): void {
		$this->service->method('isTalkAvailable')->willReturn(true);
		$this->givenObject();
		$this->params = ['name' => 'Room', 'description' => 42, 'type' => '3'];
		$link         = $this->link();
		$this->service->expects($this->once())->method('createAndLinkRoom')->with('abc-123', 1, 2, 'Room', '42', 3)->willReturn($link);

		$this->assertResponse(201, $link->jsonSerialize(), $this->controller->createNew('r', 's', 'o'));
	}

	public function testDestroyUnlinks(): void {
		$this->service->method('isTalkAvailable')->willReturn(true);
		$this->givenObject();
		$this->service->expects($this->once())->method('unlinkRoom')->with('abc-123', 'tok');

		$this->assertResponse(200, ['success' => true], $this->controller->destroy('r', 's', 'o', 'tok'));
	}

	public static function codes(): array {
		return [[400, 400], [401, 400], [404, 404], [409, 409], [503, 503], [502, 400], [500, 400], [0, 400]];
	}

	#[DataProvider('codes')]
	public function testFailuresAreMappedByCode(int $code, int $status): void {
		$this->service->method('isTalkAvailable')->willReturn(true);
		$this->givenObject();
		$this->service->method('unlinkRoom')->willThrowException(new Exception('nope', $code));

		$this->assertResponse($status, ['error' => 'nope'], $this->controller->destroy('r', 's', 'o', 'tok'));
	}

	public function testRoomsPassesSearchAsString(): void {
		$this->service->method('isTalkAvailable')->willReturn(true);
		$this->params = ['search' => 12];
		$this->service->expects($this->once())->method('getAvailableRoomsForUser')->with('12')->willReturn([['id' => 1], ['id' => 2]]);

		$this->assertResponse(200, ['results' => [['id' => 1], ['id' => 2]], 'total' => 2], $this->controller->rooms());
	}

	public function testRoomsPassesNullSearch(): void {
		$this->service->method('isTalkAvailable')->willReturn(true);
		$this->service->expects($this->once())->method('getAvailableRoomsForUser')->with(null)->willReturn([]);

		$this->assertResponse(200, ['results' => [], 'total' => 0], $this->controller->rooms());
	}
}//end class
