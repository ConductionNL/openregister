<?php

/**
 * The refusals and failures of the reason-taking and list-changing archival
 * routes: their order, status, body and logged line.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Controller
 *
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 */

declare(strict_types=1);

// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Controller;

use OCA\OpenRegister\Controller\ArchivalController;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Archival\ArchivalNominationService;
use OCA\OpenRegister\Service\Archival\DestructionListCreator;
use OCA\OpenRegister\Service\Archival\DestructionListRepository;
use OCA\OpenRegister\Service\Archival\DestructionReviewService;
use OCA\OpenRegister\Service\Archival\DestructionService;
use OCA\OpenRegister\Service\Archival\LegalHoldService;
use OCA\OpenRegister\Service\Archival\ReviewOutcomeService;
use OCA\OpenRegister\Tests\Unit\Controller\Support\ArchivalControllerFactory;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @coversNothing
 */
final class ArchivalRefusalsGoldenTest extends TestCase {
	/**
	 * The request parameters.
	 *
	 * @var array<string, mixed>
	 */
	private array $params = [];

	/**
	 * The error lines logged, as [message, context keys, id].
	 *
	 * @var array<int, array{string, array<int, string>, mixed}>
	 */
	private array $errors = [];

	/**
	 * The object mapper, failing on find().
	 *
	 * @var MagicMapper&MockObject
	 */
	private MagicMapper&MockObject $objectMapper;

	/**
	 * The destruction-list repository.
	 *
	 * @var DestructionListRepository&MockObject
	 */
	private DestructionListRepository&MockObject $lists;

	/**
	 * The legal-hold service, which must never be reached by a refusal.
	 *
	 * @var LegalHoldService&MockObject
	 */
	private LegalHoldService&MockObject $holds;

	/**
	 * The destruction service, which must never be reached by a refusal.
	 *
	 * @var DestructionService&MockObject
	 */
	private DestructionService&MockObject $destruction;

	/**
	 * The controller, with the caller as an archivist or not.
	 *
	 * @param bool $isArchivist Whether the caller is in the archivist group.
	 *
	 * @return ArchivalController
	 */
	private function controller(bool $isArchivist = true): ArchivalController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParams')->willReturnCallback(fn (): array => $this->params);
		$request->method('getParam')->willReturnCallback(fn (string $key, $default = null) => ($this->params[$key] ?? $default));
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('els');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('isInGroup')->willReturn($isArchivist);
		$groups->method('isAdmin')->willReturn(false);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('error')->willReturnCallback(
			function (string $message, array $context = []): void {
				$this->errors[] = [$message, array_keys($context), ($context['id'] ?? null)];
			}
		);
		$this->objectMapper = $this->getMockBuilder(MagicMapper::class)->disableOriginalConstructor()->onlyMethods(['update', 'find'])->getMock();
		$this->objectMapper->method('find')->willThrowException(new RuntimeException('gone'));
		$this->lists = $this->createMock(DestructionListRepository::class);
		$this->holds = $this->createMock(LegalHoldService::class);
		$this->destruction = $this->createMock(DestructionService::class);

		return ArchivalControllerFactory::create(
			'openregister',
			$request,
			$this->destruction,
			$this->holds,
			$this->objectMapper,
			$session,
			$groups,
			$logger,
			$this->lists,
			new DestructionReviewService(),
			$this->createMock(ReviewOutcomeService::class),
			$this->createMock(AuditTrailMapper::class),
			$this->createMock(ArchivalNominationService::class),
			$this->getMockBuilder(SchemaMapper::class)->disableOriginalConstructor()->onlyMethods(['find'])->getMock(),
			$this->createMock(DestructionListCreator::class)
		);
	}//end controller()

	/**
	 * Call one reason-taking route.
	 *
	 * @param ArchivalController $controller The controller.
	 * @param string             $route      The route method.
	 *
	 * @return \OCP\AppFramework\Http\JSONResponse
	 */
	private function call(ArchivalController $controller, string $route): \OCP\AppFramework\Http\JSONResponse {
		if ($route === 'createLegalHold') {
			return $controller->createLegalHold();
		}

		return $controller->$route('dl-1');
	}//end call()

	/**
	 * The reason-taking routes and the 400 each answers for a blank reason.
	 *
	 * @return array<string, array{string, string}>
	 */
	public static function reasonRoutes(): array {
		return [
			'reject' => ['rejectDestructionList', 'Een reden voor afwijzing is verplicht'],
			'hold' => ['createLegalHold', 'Een reden voor de bewaarplicht is verplicht'],
			'release' => ['releaseLegalHold', 'Een reden voor het opheffen van de bewaarplicht is verplicht'],
		];
	}//end reasonRoutes()

	/**
	 * A non-archivist is refused before the reason is looked at.
	 *
	 * @param string $route The route method.
	 *
	 * @return void
	 */
	#[DataProvider('reasonRoutes')]
	public function testANonArchivistIsRefusedFirst(string $route): void {
		$this->params = [];
		$controller = $this->controller(isArchivist: false);
		$this->objectMapper->expects($this->never())->method('find');

		$response = $this->call(controller: $controller, route: $route);

		$this->assertSame([403, ['error' => 'Onvoldoende rechten: archivaris rol is vereist']], [$response->getStatus(), $response->getData()]);
	}//end testANonArchivistIsRefusedFirst()

	/**
	 * A missing or blank reason is refused with the route's own message.
	 *
	 * @param string $route   The route method.
	 * @param string $message The 400 message.
	 *
	 * @return void
	 */
	#[DataProvider('reasonRoutes')]
	public function testABlankReasonIsRefused(string $route, string $message): void {
		foreach ([[], ['reason' => ''], ['reason' => "  \t"]] as $params) {
			$this->params = $params;
			$controller = $this->controller();
			$this->objectMapper->expects($this->never())->method('find');

			$response = $this->call(controller: $controller, route: $route);

			$this->assertSame([400, ['error' => $message]], [$response->getStatus(), $response->getData()]);
		}
	}//end testABlankReasonIsRefused()

	/**
	 * A failing write answers 500 with the action named, and logs it.
	 *
	 * @return void
	 */
	public function testAFailingWriteIs500AndLogged(): void {
		$expected = [
			'approveDestructionList' => ['approve destruction list', ['file', 'line', 'id', 'exception'], 'dl-1'],
			'rejectDestructionList' => ['reject destruction list', ['file', 'line', 'id', 'exception'], 'dl-1'],
			'createLegalHold' => ['create legal hold', ['file', 'line', 'exception'], null],
			'releaseLegalHold' => ['release legal hold', null, null],
		];
		foreach ($expected as $route => [$action, $keys, $id]) {
			$this->params = ['reason' => 'kept', 'objectId' => 'o-1'];
			$this->errors = [];

			$response = $this->call(controller: $this->controller(), route: $route);

			$this->assertSame([500, ['error' => 'Failed to ' . $action . ': gone']], [$response->getStatus(), $response->getData()], $route);
			$logged = [];
			if ($keys !== null) {
				$logged = [['[ArchivalController] Failed to ' . $action, $keys, $id]];
			}

			$this->assertSame($logged, $this->errors, $route);
		}
	}//end testAFailingWriteIs500AndLogged()

	/**
	 * A list that cannot be read answers 500 and logs the id and the cause.
	 *
	 * @return void
	 */
	public function testAnUnreadableListIs500AndLogged(): void {
		foreach (['getDestructionList', 'decideEntry'] as $route) {
			$this->errors = [];
			$controller = $this->controller();
			$this->lists->method('find')->willThrowException(new RuntimeException('db'));

			$response = match ($route) {
				'decideEntry' => $controller->decideEntry('dl-1', 'obj-1'),
				default => $controller->getDestructionList('dl-1'),
			};

			$this->assertSame([500, ['error' => 'Destruction list could not be read']], [$response->getStatus(), $response->getData()], $route);
			$this->assertSame([['[ArchivalController] Could not read destruction list dl-1: db', [], null]], $this->errors, $route);
		}
	}//end testAnUnreadableListIs500AndLogged()
}//end class
