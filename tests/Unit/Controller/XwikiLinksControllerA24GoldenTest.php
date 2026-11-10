<?php

/**
 * Golden outcomes of {@see \OCA\OpenRegister\Controller\XwikiLinksController}, pinned before the A24 refactor.
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
use OCA\OpenRegister\Controller\Support\XwikiLinksControllerServices;
use OCA\OpenRegister\Controller\XwikiLinksController;
use OCA\OpenRegister\Db\ListQuery;
use OCA\OpenRegister\Db\XwikiLink;
use OCA\OpenRegister\Service\XwikiLinkServiceInterface;
use OCA\OpenRegister\Service\XwikiPageDraft;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;

/**
 * XwikiLinksControllerA24GoldenTest.
 */
class XwikiLinksControllerA24GoldenTest extends LinkedSourceGoldenTestCase {

	/**
	 * Xwiki link service mock.
	 *
	 * @var XwikiLinkServiceInterface&MockObject
	 */
	private XwikiLinkServiceInterface&MockObject $service;

	/**
	 * Controller under test.
	 *
	 * @var XwikiLinksController
	 */
	private XwikiLinksController $controller;

	protected function setUp(): void {
		parent::setUp();
		$this->service    = $this->createMock(XwikiLinkServiceInterface::class);
		$this->controller = new XwikiLinksController($this->request, new XwikiLinksControllerServices($this->service, $this->objectService));
	}

	private function link(): XwikiLink {
		$link = new XwikiLink();
		$link->setObjectUuid('abc-123');
		$link->setPageReference('Main.Page');
		return $link;
	}

	public static function actions(): array {
		return [
			'index'         => ['index', ['r', 's', 'o']],
			'link'          => ['link', ['r', 's', 'o']],
			'createAndLink' => ['createAndLink', ['r', 's', 'o']],
			'destroy'       => ['destroy', ['r', 's', 'o', 'Main.Page']],
		];
	}

	#[DataProvider('actions')]
	public function testMissingObjectAnswers404(string $method, array $args): void {
		$this->givenNoObject();
		$this->service->expects($this->never())->method('linkPage');
		$this->service->expects($this->never())->method('unlinkPage');

		$this->assertNotFound($this->controller->$method(...$args));
	}

	#[DataProvider('actions')]
	public function testObjectLookupFailureAnswers404(string $method, array $args): void {
		$this->givenObjectLookupThrows();

		$this->assertNotFound($this->controller->$method(...$args));
	}

	public function testIndexListsAndFailureIsMapped(): void {
		$this->givenObject();
		$this->service->method('getLinkedPages')->with('abc-123')
			->willReturnOnConsecutiveCalls([['ref' => 'A']], $this->throwException(new Exception('boom', 409)));

		$this->assertResponse(200, ['results' => [['ref' => 'A']], 'total' => 1], $this->controller->index('r', 's', 'o'));
		$this->assertResponse(409, ['error' => 'boom'], $this->controller->index('r', 's', 'o'));
	}

	public function testLinkRequiresPageReference(): void {
		$this->givenObject();
		$this->params = ['pageReference' => '  '];
		$this->service->expects($this->never())->method('linkPage');

		$this->assertResponse(400, ['error' => 'pageReference is required'], $this->controller->link('r', 's', 'o'));
	}

	public function testLinkCreates201(): void {
		$this->givenObject();
		$this->params = ['pageReference' => ' Main.Page '];
		$link         = $this->link();
		$this->service->expects($this->once())->method('linkPage')->with($this->equalTo($this->target()), ' Main.Page ')
			->willReturn($link);

		$this->assertResponse(201, $link->jsonSerialize(), $this->controller->link('r', 's', 'o'));
	}

	public static function missingCreateParams(): array {
		return [
			'no space'    => [['title' => 'T'], 'space is required'],
			'blank space' => [['space' => ' ', 'title' => 'T'], 'space is required'],
			'no title'    => [['space' => 'Main'], 'title is required'],
			'both blank'  => [['space' => '', 'title' => ''], 'space is required'],
		];
	}

	#[DataProvider('missingCreateParams')]
	public function testCreateAndLinkRequiresSpaceAndTitle(array $params, string $error): void {
		$this->givenObject();
		$this->params = $params;
		$this->service->expects($this->never())->method('createAndLinkPage');

		$this->assertResponse(400, ['error' => $error], $this->controller->createAndLink('r', 's', 'o'));
	}

	public function testCreateAndLinkCreates201(): void {
		$this->givenObject();
		$this->params = ['space' => 'Main', 'title' => 'New page'];
		$link         = $this->link();
		$this->service->expects($this->once())->method('createAndLinkPage')->with($this->equalTo($this->target()), new XwikiPageDraft('Main', 'New page'))
			->willReturn($link);

		$this->assertResponse(201, $link->jsonSerialize(), $this->controller->createAndLink('r', 's', 'o'));
	}

	public function testDestroyDecodesThePageReference(): void {
		$this->givenObject();
		$this->service->expects($this->once())->method('unlinkPage')->with('abc-123', 'Main/Sub Page+1');

		$this->assertResponse(200, ['success' => true], $this->controller->destroy('r', 's', 'o', 'Main%2FSub%20Page+1'));
	}

	public static function codes(): array {
		return [[400, 400], [401, 401], [404, 404], [409, 409], [503, 503], [502, 400], [500, 400], [0, 400]];
	}

	#[DataProvider('codes')]
	public function testFailuresAreMappedByCode(int $code, int $status): void {
		$this->givenObject();
		$this->service->method('unlinkPage')->willThrowException(new Exception('nope', $code));

		$this->assertResponse($status, ['error' => 'nope'], $this->controller->destroy('r', 's', 'o', 'A'));
	}

	public function testSourceFailureCarriesTheCause(): void {
		$this->givenObject();
		$this->service->method('unlinkPage')->willThrowException(new Exception('xwiki:openconnector-down', 503));

		$this->assertResponse(
			503,
			['error' => 'xWiki source is not available', 'details' => ['cause' => 'openconnector-down']],
			$this->controller->destroy('r', 's', 'o', 'A')
		);
	}

	public function testPrefixedFailureWithOtherCodeIsPlain(): void {
		$this->givenObject();
		$this->service->method('unlinkPage')->willThrowException(new Exception('xwiki:provider-auth', 401));

		$this->assertResponse(401, ['error' => 'xwiki:provider-auth'], $this->controller->destroy('r', 's', 'o', 'A'));
	}

	public function testAvailableListsWithSearch(): void {
		$this->params = ['search' => 7];
		$this->service->expects($this->once())->method('getAvailablePages')->with('7')
			->willReturn(['results' => [['ref' => 'A']], 'total' => 9, 'extra' => 1]);

		$this->assertResponse(200, ['results' => [['ref' => 'A']], 'total' => 9], $this->controller->available());
	}

	public function testAvailableUnavailableAnswers503(): void {
		$this->service->expects($this->once())->method('getAvailablePages')->with(null)
			->willReturn(['unavailable' => true, 'cause' => 'openconnector-source-missing']);

		$this->assertResponse(
			503,
			['error' => 'xWiki source is not available', 'details' => ['cause' => 'openconnector-source-missing']],
			$this->controller->available()
		);
	}

	public function testAvailableNotUnavailableWhenFlagIsNotTrue(): void {
		$this->service->method('getAvailablePages')->willReturn(['unavailable' => 1, 'cause' => 'x', 'results' => [], 'total' => 0]);

		$this->assertResponse(200, ['results' => [], 'total' => 0], $this->controller->available());
	}

	public static function searchQueries(): array {
		return [
			'q wins'           => [['q' => 'alpha', 'search' => 'beta'], 'alpha', 25, 0],
			'search fallback'  => [['search' => 'beta', 'limit' => '10', 'offset' => '5'], 'beta', 10, 5],
			'no query'         => [[], null, 25, 0],
			'numeric q'        => [['q' => 3, 'limit' => 'x'], '3', 0, 0],
		];
	}

	#[DataProvider('searchQueries')]
	public function testSearchForwardsQueryAndPaging(array $params, ?string $query, int $limit, int $offset): void {
		$this->params = $params;
		$this->service->expects($this->once())->method('searchPages')->with(new ListQuery(limit: $limit, offset: $offset, search: $query))
			->willReturn(
			['offset' => 2, 'limit' => 1, 'total' => 4, 'results' => [['ref' => 'A']], 'extra' => true]
		);

		$this->assertResponse(200, ['results' => [['ref' => 'A']], 'total' => 4, 'limit' => 1, 'offset' => 2], $this->controller->search());
	}

	public function testSearchUnavailableAnswers503(): void {
		$this->service->method('searchPages')->willReturn(['unavailable' => true, 'cause' => 'upstream-service-down']);

		$this->assertResponse(
			503,
			['error' => 'xWiki source is not available', 'details' => ['cause' => 'upstream-service-down']],
			$this->controller->search()
		);
	}
}//end class
