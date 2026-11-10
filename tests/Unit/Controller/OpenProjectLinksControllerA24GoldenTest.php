<?php

/**
 * Golden outcomes of {@see \OCA\OpenRegister\Controller\OpenProjectLinksController}, pinned before the A24 refactor.
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
use OCA\OpenRegister\Service\WorkPackageDraft;
use OCA\OpenRegister\Controller\OpenProjectLinksController;
use OCA\OpenRegister\Db\OpenProjectLink;
use OCA\OpenRegister\Service\OpenProjectLinkServiceInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;

/**
 * OpenProjectLinksControllerA24GoldenTest.
 */
class OpenProjectLinksControllerA24GoldenTest extends LinkedSourceGoldenTestCase {

	/**
	 * OpenProject link service mock.
	 *
	 * @var OpenProjectLinkServiceInterface&MockObject
	 */
	private OpenProjectLinkServiceInterface&MockObject $service;

	/**
	 * Controller under test.
	 *
	 * @var OpenProjectLinksController
	 */
	private OpenProjectLinksController $controller;

	protected function setUp(): void {
		parent::setUp();
		$this->service    = $this->createMock(OpenProjectLinkServiceInterface::class);
		$this->controller = new OpenProjectLinksController(
			$this->request,
			new \OCA\OpenRegister\Controller\Support\OpenProjectLinksControllerServices(
				$this->service,
				$this->objectService,
			)
		);
	}

	private function link(): OpenProjectLink {
		$link = new OpenProjectLink();
		$link->setObjectUuid('abc-123');
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
		$this->service->method('isOpenConnectorAvailable')->willReturn(false);
		$this->givenObjectNeverResolved();

		$response = $this->controller->$method(...$args);

		$this->assertResponse(501, ['error' => 'OpenConnector app is not installed', 'code' => 'APP_NOT_AVAILABLE'], $response);
	}

	#[DataProvider('actions')]
	public function testMissingObjectAnswers404(string $method, array $args): void {
		$this->service->method('isOpenConnectorAvailable')->willReturn(true);
		$this->givenNoObject();
		$this->service->expects($this->never())->method('linkWorkPackage');
		$this->service->expects($this->never())->method('unlink');

		$this->assertNotFound($this->controller->$method(...$args));
	}

	#[DataProvider('actions')]
	public function testObjectLookupFailureAnswers404(string $method, array $args): void {
		$this->service->method('isOpenConnectorAvailable')->willReturn(true);
		$this->givenObjectLookupThrows();

		$this->assertNotFound($this->controller->$method(...$args));
	}

	public function testAvailableAnswers501WhenUnavailable(): void {
		$this->service->method('isOpenConnectorAvailable')->willReturn(false);
		$this->service->expects($this->never())->method('getAvailableWorkPackages');

		$this->assertResponse(501, ['error' => 'OpenConnector app is not installed', 'code' => 'APP_NOT_AVAILABLE'], $this->controller->available());
	}

	public function testIndexListsAndFailureIsMapped(): void {
		$this->service->method('isOpenConnectorAvailable')->willReturn(true);
		$this->givenObject();
		$this->service->method('getLinkedWorkPackages')->with('abc-123')
			->willReturnOnConsecutiveCalls([['workPackageId' => 1]], $this->throwException(new Exception('boom', 409)));

		$this->assertResponse(200, ['results' => [['workPackageId' => 1]], 'total' => 1], $this->controller->index('r', 's', 'o'));
		$this->assertResponse(409, ['error' => 'boom'], $this->controller->index('r', 's', 'o'));
	}

	public function testLinkRequiresWorkPackageId(): void {
		$this->service->method('isOpenConnectorAvailable')->willReturn(true);
		$this->givenObject();
		$this->params = ['workPackageId' => 'abc'];

		$this->assertResponse(400, ['error' => 'workPackageId is required'], $this->controller->link('r', 's', 'o'));
	}

	public function testLinkCreates201(): void {
		$this->service->method('isOpenConnectorAvailable')->willReturn(true);
		$this->givenObject();
		$this->params = ['workPackageId' => '7'];
		$link         = $this->link();
		$this->service->expects($this->once())->method('linkWorkPackage')->with($this->equalTo($this->target()), 7)
			->willReturn($link);

		$this->assertResponse(201, $link->jsonSerialize(), $this->controller->link('r', 's', 'o'));
	}

	public static function missingCreateParams(): array {
		return [
			'no projectId'    => [['subject' => 'S'], 'projectId is required'],
			'blank projectId' => [['projectId' => ' ', 'subject' => 'S'], 'projectId is required'],
			'no subject'      => [['projectId' => 'p1'], 'subject is required'],
			'blank subject'   => [['projectId' => 'p1', 'subject' => '  '], 'subject is required'],
		];
	}

	#[DataProvider('missingCreateParams')]
	public function testCreateAndLinkRequiresProjectAndSubject(array $params, string $error): void {
		$this->service->method('isOpenConnectorAvailable')->willReturn(true);
		$this->givenObject();
		$this->params = $params;
		$this->service->expects($this->never())->method('createAndLinkDraft');

		$this->assertResponse(400, ['error' => $error], $this->controller->createAndLink('r', 's', 'o'));
	}

	public function testCreateAndLinkCreates201WithDefaultType(): void {
		$this->service->method('isOpenConnectorAvailable')->willReturn(true);
		$this->givenObject();
		$this->params = ['projectId' => 'p1', 'subject' => 'Fix it'];
		$link         = $this->link();
		$this->service->expects($this->once())->method('createAndLinkDraft')->with($this->equalTo($this->target()), new WorkPackageDraft('p1', 'Fix it', ''))
			->willReturn($link);

		$this->assertResponse(201, $link->jsonSerialize(), $this->controller->createAndLink('r', 's', 'o'));
	}

	public function testCreateAndLinkPassesType(): void {
		$this->service->method('isOpenConnectorAvailable')->willReturn(true);
		$this->givenObject();
		$this->params = ['projectId' => 'p1', 'subject' => 'Fix it', 'type' => 'Bug'];
		$link         = $this->link();
		$this->service->expects($this->once())->method('createAndLinkDraft')->with($this->equalTo($this->target()), new WorkPackageDraft('p1', 'Fix it', 'Bug'))
			->willReturn($link);

		$this->assertResponse(201, $link->jsonSerialize(), $this->controller->createAndLink('r', 's', 'o'));
	}

	public function testDestroyUnlinks(): void {
		$this->service->method('isOpenConnectorAvailable')->willReturn(true);
		$this->givenObject();
		$this->service->expects($this->once())->method('unlink')->with('abc-123', 7);

		$this->assertResponse(200, ['success' => true], $this->controller->destroy('r', 's', 'o', '7'));
	}

	public static function codes(): array {
		return [[400, 400], [401, 401], [404, 404], [409, 409], [503, 503], [502, 502], [500, 400], [0, 400]];
	}

	#[DataProvider('codes')]
	public function testFailuresAreMappedByCode(int $code, int $status): void {
		$this->service->method('isOpenConnectorAvailable')->willReturn(true);
		$this->givenObject();
		$this->service->method('unlink')->willThrowException(new Exception('nope', $code));

		$this->assertResponse($status, ['error' => 'nope'], $this->controller->destroy('r', 's', 'o', '7'));
	}

	public function testAvailablePassesSearchAsString(): void {
		$this->service->method('isOpenConnectorAvailable')->willReturn(true);
		$this->params = ['search' => 12];
		$this->service->expects($this->once())->method('getAvailableWorkPackages')->with('12')
			->willReturn([['id' => 1], ['id' => 2]]);

		$this->assertResponse(200, ['results' => [['id' => 1], ['id' => 2]], 'total' => 2], $this->controller->available());
	}

	public function testAvailablePassesNullSearch(): void {
		$this->service->method('isOpenConnectorAvailable')->willReturn(true);
		$this->service->expects($this->once())->method('getAvailableWorkPackages')->with(null)
			->willReturn([]);

		$this->assertResponse(200, ['results' => [], 'total' => 0], $this->controller->available());
	}

	#[DataProvider('codes')]
	public function testAvailableFailuresAreMappedByCode(int $code, int $status): void {
		$this->service->method('isOpenConnectorAvailable')->willReturn(true);
		$this->service->method('getAvailableWorkPackages')->willThrowException(new Exception('down', $code));

		$this->assertResponse($status, ['error' => 'down'], $this->controller->available());
	}
}//end class
