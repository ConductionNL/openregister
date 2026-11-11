<?php

/**
 * Golden outcomes of {@see \OCA\OpenRegister\Controller\TimeTrackerLinksController}, pinned before the A24 refactor.
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
use OCA\OpenRegister\Controller\Support\TimeTrackerLinksControllerServices;
use OCA\OpenRegister\Controller\TimeTrackerLinksController;
use OCA\OpenRegister\Db\TimeTrackerLink;
use OCA\OpenRegister\Service\TimeTracker\TimeEntry;
use OCA\OpenRegister\Service\TimeTrackerLinkServiceInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;

/**
 * TimeTrackerLinksControllerA24GoldenTest.
 */
class TimeTrackerLinksControllerA24GoldenTest extends LinkedSourceGoldenTestCase {

	/**
	 * TimeTracker link service mock.
	 *
	 * @var TimeTrackerLinkServiceInterface&MockObject
	 */
	private TimeTrackerLinkServiceInterface&MockObject $service;

	/**
	 * Controller under test.
	 *
	 * @var TimeTrackerLinksController
	 */
	private TimeTrackerLinksController $controller;

	protected function setUp(): void {
		parent::setUp();
		$this->service    = $this->createMock(TimeTrackerLinkServiceInterface::class);
		$this->controller = new TimeTrackerLinksController($this->request, new TimeTrackerLinksControllerServices($this->service, $this->objectService));
	}

	private function link(): TimeTrackerLink {
		$link = new TimeTrackerLink();
		$link->setObjectUuid('abc-123');
				return $link;
	}

	public static function actions(): array {
		return [
			'index'         => ['index', ['r', 's', 'o']],
			'link'          => ['link', ['r', 's', 'o']],
			'createAndLink' => ['createAndLink', ['r', 's', 'o']],
			'destroy'       => ['destroy', ['r', 's', 'o', 'e-7']],
		];
	}

	#[DataProvider('actions')]
	public function testUnavailableAnswers501BeforeResolvingTheObject(string $method, array $args): void {
		$this->service->method('isTimeManagerAvailable')->willReturn(false);
		$this->givenObjectNeverResolved();

		$response = $this->controller->$method(...$args);

		$this->assertResponse(501, ['error' => 'NC TimeManager app is not installed', 'code' => 'APP_NOT_AVAILABLE'], $response);
	}

	#[DataProvider('actions')]
	public function testMissingObjectAnswers404(string $method, array $args): void {
		$this->service->method('isTimeManagerAvailable')->willReturn(true);
		$this->givenNoObject();
		$this->service->expects($this->never())->method('linkEntry');
		$this->service->expects($this->never())->method('unlink');

		$this->assertNotFound($this->controller->$method(...$args));
	}

	#[DataProvider('actions')]
	public function testObjectLookupFailureAnswers404(string $method, array $args): void {
		$this->service->method('isTimeManagerAvailable')->willReturn(true);
		$this->givenObjectLookupThrows();

		$this->assertNotFound($this->controller->$method(...$args));
	}

	public function testAvailableAnswers501WhenUnavailable(): void {
		$this->service->method('isTimeManagerAvailable')->willReturn(false);
		$this->service->expects($this->never())->method('getAvailableClients');

		$this->assertResponse(501, ['error' => 'NC TimeManager app is not installed', 'code' => 'APP_NOT_AVAILABLE'], $this->controller->available());
	}

	public function testIndexListsAndFailureAnswers500(): void {
		$this->service->method('isTimeManagerAvailable')->willReturn(true);
		$this->givenObject();
		$this->service->method('getLinkedEntries')->with('abc-123')
			->willReturnOnConsecutiveCalls([['entryId' => 'e1']], $this->throwException(new Exception('boom', 409)));

		$this->assertResponse(200, ['results' => [['entryId' => 'e1']], 'total' => 1], $this->controller->index('r', 's', 'o'));
		$this->assertResponse(500, ['error' => 'boom'], $this->controller->index('r', 's', 'o'));
	}

	public static function missingLinkParams(): array {
		return [
			'no entryType'    => [['id' => 'e1'], 'entryType is required'],
			'blank entryType' => [['entryType' => '  ', 'id' => 'e1'], 'entryType is required'],
			'no id'           => [['entryType' => 'task'], 'id is required'],
			'blank id'        => [['entryType' => 'task', 'id' => ' '], 'id is required'],
		];
	}

	#[DataProvider('missingLinkParams')]
	public function testLinkRequiresEntryTypeAndId(array $params, string $error): void {
		$this->service->method('isTimeManagerAvailable')->willReturn(true);
		$this->givenObject();
		$this->params = $params;
		$this->service->expects($this->never())->method('linkEntry');

		$this->assertResponse(400, ['error' => $error], $this->controller->link('r', 's', 'o'));
	}

	public function testLinkCreates201(): void {
		$this->service->method('isTimeManagerAvailable')->willReturn(true);
		$this->givenObject();
		$this->params = ['entryType' => 'task', 'id' => ' e1 '];
		$link         = $this->link();
		$this->service->expects($this->once())->method('linkEntry')->with($this->equalTo($this->target()), new TimeEntry('task', ' e1 '))->willReturn($link);

		$this->assertResponse(201, $link->jsonSerialize(), $this->controller->link('r', 's', 'o'));
	}

	public function testCreateAndLinkRequiresName(): void {
		$this->service->method('isTimeManagerAvailable')->willReturn(true);
		$this->givenObject();
		$this->params = ['name' => '   '];

		$this->assertResponse(400, ['error' => 'name is required'], $this->controller->createAndLink('r', 's', 'o'));
	}

	public function testCreateAndLinkCreates201(): void {
		$this->service->method('isTimeManagerAvailable')->willReturn(true);
		$this->givenObject();
		$this->params = ['name' => ' Trip '];
		$link         = $this->link();
		$this->service->expects($this->once())->method('createAndLinkClient')->with($this->equalTo($this->target()), ' Trip ')->willReturn($link);

		$this->assertResponse(201, $link->jsonSerialize(), $this->controller->createAndLink('r', 's', 'o'));
	}

	public function testDestroyUnlinks(): void {
		$this->service->method('isTimeManagerAvailable')->willReturn(true);
		$this->givenObject();
		$this->service->expects($this->once())->method('unlink')->with('abc-123', 'e-7');

		$this->assertResponse(200, ['success' => true], $this->controller->destroy('r', 's', 'o', 'e-7'));
	}

	public static function codes(): array {
		return [[400, 400], [401, 401], [404, 404], [409, 409], [503, 503], [502, 400], [500, 400], [0, 400]];
	}

	#[DataProvider('codes')]
	public function testFailuresAreMappedByCode(int $code, int $status): void {
		$this->service->method('isTimeManagerAvailable')->willReturn(true);
		$this->givenObject();
		$this->service->method('unlink')->willThrowException(new Exception('nope', $code));

		$this->assertResponse($status, ['error' => 'nope'], $this->controller->destroy('r', 's', 'o', 'e-7'));
	}

	public function testAvailablePassesSearchAsString(): void {
		$this->service->method('isTimeManagerAvailable')->willReturn(true);
		$this->params = ['search' => 12];
		$this->service->expects($this->once())->method('getAvailableClients')->with('12')->willReturn([['id' => 1], ['id' => 2]]);

		$this->assertResponse(200, ['results' => [['id' => 1], ['id' => 2]], 'total' => 2], $this->controller->available());
	}

	public function testAvailablePassesNullSearch(): void {
		$this->service->method('isTimeManagerAvailable')->willReturn(true);
		$this->service->expects($this->once())->method('getAvailableClients')->with(null)->willReturn([]);

		$this->assertResponse(200, ['results' => [], 'total' => 0], $this->controller->available());
	}
}//end class
