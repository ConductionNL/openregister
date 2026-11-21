<?php

/**
 * Golden outcomes of {@see \OCA\OpenRegister\Controller\PollLinksController}, pinned before the A24 refactor.
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
use OCA\OpenRegister\Controller\PollLinksController;
use OCA\OpenRegister\Db\PollLink;
use OCA\OpenRegister\Service\LinkTarget;
use OCA\OpenRegister\Service\PollDraft;
use OCA\OpenRegister\Service\PollLinkServiceInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;

/**
 * PollLinksControllerA24GoldenTest.
 */
class PollLinksControllerA24GoldenTest extends LinkedSourceGoldenTestCase {

	/**
	 * Polls link service mock.
	 *
	 * @var PollLinkServiceInterface&MockObject
	 */
	private PollLinkServiceInterface&MockObject $service;

	/**
	 * Controller under test.
	 *
	 * @var PollLinksController
	 */
	private PollLinksController $controller;

	protected function setUp(): void {
		parent::setUp();
		$this->service    = $this->createMock(PollLinkServiceInterface::class);
		$this->controller = new PollLinksController(
			$this->request,
			new \OCA\OpenRegister\Controller\Support\PollLinksControllerServices(
				$this->service,
				$this->objectService,
			)
		);
	}

	private function link(): PollLink {
		$link = new PollLink();
		$link->setObjectUuid('abc-123');
		$link->setPollId(7);
		return $link;
	}

	public static function actions(): array {
		return [
			'index'         => ['index', ['r', 's', 'o']],
			'link'          => ['link', ['r', 's', 'o']],
			'createNew'     => ['createNew', ['r', 's', 'o']],
			'destroy'       => ['destroy', ['r', 's', 'o', '7']],
		];
	}

	#[DataProvider('actions')]
	public function testUnavailableAnswers501BeforeResolvingTheObject(string $method, array $args): void {
		$this->service->method('isPollsAvailable')->willReturn(false);
		$this->givenObjectNeverResolved();

		$response = $this->controller->$method(...$args);

		$this->assertResponse(501, ['error' => 'Nextcloud Polls app is not installed', 'code' => 'APP_NOT_AVAILABLE'], $response);
	}

	#[DataProvider('actions')]
	public function testMissingObjectAnswers404(string $method, array $args): void {
		$this->service->method('isPollsAvailable')->willReturn(true);
		$this->givenNoObject();
		$this->service->expects($this->never())->method('linkPoll');
		$this->service->expects($this->never())->method('unlinkPoll');

		$this->assertNotFound($this->controller->$method(...$args));
	}

	#[DataProvider('actions')]
	public function testObjectLookupFailureAnswers404(string $method, array $args): void {
		$this->service->method('isPollsAvailable')->willReturn(true);
		$this->givenObjectLookupThrows();

		$this->assertNotFound($this->controller->$method(...$args));
	}

	public function testAvailableAnswers501WhenUnavailable(): void {
		$this->service->method('isPollsAvailable')->willReturn(false);
		$this->service->expects($this->never())->method('getAvailablePolls');

		$this->assertResponse(501, ['error' => 'Nextcloud Polls app is not installed', 'code' => 'APP_NOT_AVAILABLE'], $this->controller->available());
	}

	public function testIndexListsAndFailureAnswers500(): void {
		$this->service->method('isPollsAvailable')->willReturn(true);
		$this->givenObject();
		$this->service->method('getLinkedPolls')->with('abc-123')
			->willReturnOnConsecutiveCalls([['pollId' => 1]], $this->throwException(new Exception('boom', 409)));

		$this->assertResponse(200, ['results' => [['pollId' => 1]], 'total' => 1], $this->controller->index('r', 's', 'o'));
		$this->assertResponse(500, ['error' => 'boom'], $this->controller->index('r', 's', 'o'));
	}

	public function testLinkRequiresPollId(): void {
		$this->service->method('isPollsAvailable')->willReturn(true);
		$this->givenObject();
		$this->params = ['pollId' => 'abc'];

		$this->assertResponse(400, ['error' => 'pollId is required'], $this->controller->link('r', 's', 'o'));
	}

	public function testLinkCreates201(): void {
		$this->service->method('isPollsAvailable')->willReturn(true);
		$this->givenObject();
		$this->params = ['pollId' => '7'];
		$link         = $this->link();
		$this->service->expects($this->once())->method('linkPoll')->with($this->equalTo($this->target()), 7)->willReturn($link);

		$this->assertResponse(201, $link->jsonSerialize(), $this->controller->link('r', 's', 'o'));
	}

	public function testCreateNewRequiresTitle(): void {
		$this->service->method('isPollsAvailable')->willReturn(true);
		$this->givenObject();
		$this->params = ['title' => '  ', 'options' => ['A']];
		$this->service->expects($this->never())->method('createAndLinkPoll');

		$this->assertResponse(400, ['error' => 'title is required'], $this->controller->createNew('r', 's', 'o'));
	}

	private function draftFor(array $params): PollDraft {
		$this->service->method('isPollsAvailable')->willReturn(true);
		$this->givenObject();
		$this->params = $params;
		$link         = $this->link();
		$captured     = null;
		$this->service->expects($this->once())->method('createAndLinkPoll')->willReturnCallback(
			function (LinkTarget $target, PollDraft $draft) use (&$captured, $link): PollLink {
				$this->assertEquals($this->target(), $target);
				$captured = $draft;
				return $link;
			}
		);

		$this->assertResponse(201, $link->jsonSerialize(), $this->controller->createNew('r', 's', 'o'));
		return $captured;
	}

	public function testCreateNewDefaults(): void {
		$draft = $this->draftFor(['title' => 'Lunch']);

		$this->assertSame('Lunch', $draft->title);
		$this->assertSame('', $draft->description);
		$this->assertSame('textPoll', $draft->pollType());
		$this->assertSame([], $draft->options());
		$this->assertSame(0, $draft->expire());
	}

	public function testCreateNewParsesOptionsTypeAndDeadline(): void {
		$draft = $this->draftFor(
			[
				'title'       => ' Lunch ',
				'description' => 'Pick',
				'type'        => 'datePoll',
				'options'     => ['Mon', ['text' => 'Tue'], ['label' => 'Wed'], ['other' => 'x'], 5, null, ['text' => 'T', 'label' => 'L']],
				'deadline'    => '2030-01-02T03:04:05+00:00',
			]
		);

		$this->assertSame(' Lunch ', $draft->title);
		$this->assertSame('Pick', $draft->description);
		$this->assertSame('datePoll', $draft->pollType());
		$this->assertSame(['Mon', 'Tue', 'Wed', '', 'T'], $draft->options());
		$this->assertSame(1893553445, $draft->expire());
	}

	public static function noDeadlines(): array {
		return [
			'empty'      => [''],
			'unparsable' => ['not a date at all'],
		];
	}

	#[DataProvider('noDeadlines')]
	public function testCreateNewIgnoresUnusableDeadline(string $deadline): void {
		$draft = $this->draftFor(['title' => 'Lunch', 'options' => 'not-a-list', 'deadline' => $deadline]);

		$this->assertSame([], $draft->options());
		$this->assertSame(0, $draft->expire());
	}

	public function testDestroyUnlinks(): void {
		$this->service->method('isPollsAvailable')->willReturn(true);
		$this->givenObject();
		$this->service->expects($this->once())->method('unlinkPoll')->with('abc-123', 7);

		$this->assertResponse(200, ['success' => true], $this->controller->destroy('r', 's', 'o', '7'));
	}

	public static function codes(): array {
		return [[400, 400], [401, 400], [404, 404], [409, 409], [503, 503], [502, 400], [500, 400], [0, 400]];
	}

	#[DataProvider('codes')]
	public function testFailuresAreMappedByCode(int $code, int $status): void {
		$this->service->method('isPollsAvailable')->willReturn(true);
		$this->givenObject();
		$this->service->method('unlinkPoll')->willThrowException(new Exception('nope', $code));

		$this->assertResponse($status, ['error' => 'nope'], $this->controller->destroy('r', 's', 'o', '7'));
	}

	public function testAvailablePassesSearchAsString(): void {
		$this->service->method('isPollsAvailable')->willReturn(true);
		$this->params = ['search' => 12];
		$this->service->expects($this->once())->method('getAvailablePolls')->with('12')->willReturn([['id' => 1], ['id' => 2]]);

		$this->assertResponse(200, ['results' => [['id' => 1], ['id' => 2]], 'total' => 2], $this->controller->available());
	}

	public function testAvailablePassesNullSearch(): void {
		$this->service->method('isPollsAvailable')->willReturn(true);
		$this->service->expects($this->once())->method('getAvailablePolls')->with(null)->willReturn([]);

		$this->assertResponse(200, ['results' => [], 'total' => 0], $this->controller->available());
	}
}//end class
