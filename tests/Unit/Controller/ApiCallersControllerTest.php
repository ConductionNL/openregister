<?php

/**
 * ApiCallersControllerTest — the contract test for the caller record and the
 * version declaration surface (gate 25).
 *
 * The administrator check is the one to get wrong quietly. The caller record
 * names every principal that integrates with this gemeente, and the
 * declaration decides which contract every consumer speaks. `#[NoAdminRequired]`
 * answers "is anyone logged in", which is not the question, so the guard is in
 * the body and this file is what proves it runs.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Controller
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @spec openspec/changes/api-as-a-versioned-surface/specs/api-surface-governance/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller;

use OCA\OpenRegister\Controller\ApiCallersController;
use OCA\OpenRegister\Db\ApiCallRecord;
use OCA\OpenRegister\Db\ApiCallRecordMapper;
use OCA\OpenRegister\Service\ApiVersion\ApiVersionCatalogue;
use OCP\AppFramework\Http;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\OpenRegister\Controller\ApiCallersController
 */
class ApiCallersControllerTest extends TestCase {

	/**
	 * The last value written to the declaration, for the write test.
	 *
	 * @var string|null
	 */
	private ?string $stored = null;

	/**
	 * Build the controller.
	 *
	 * @param bool $isAdmin Whether the caller is an administrator.
	 * @param array<string, mixed> $params The request parameters.
	 * @param array<int, ApiCallRecord> $rows What the mapper returns.
	 *
	 * @return array{0: ApiCallersController, 1: ApiCallRecordMapper&MockObject} The controller and its mapper.
	 */
	private function build(bool $isAdmin = true, array $params = [], array $rows = []): array {
		$mapper = $this->createMock(ApiCallRecordMapper::class);
		$mapper->method('findInPeriod')->willReturn($rows);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('');
		$appConfig->method('setValueString')->willReturnCallback(
			function (string $app, string $key, string $value): bool {
				$this->stored = $value;

				return true;
			}
		);

		$session = $this->createMock(IUserSession::class);
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('beheerder');
		$session->method('getUser')->willReturn($user);

		$groups = $this->createMock(IGroupManager::class);
		$groups->method('isAdmin')->willReturn($isAdmin);

		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static fn (string $name, mixed $default = null): mixed => ($params[$name] ?? $default)
		);

		$controller = new ApiCallersController(
			'openregister',
			$request,
			$mapper,
			new ApiVersionCatalogue($appConfig, $this->createMock(LoggerInterface::class)),
			$appConfig,
			$session,
			$groups,
			$this->createMock(LoggerInterface::class),
		);

		return [$controller, $mapper];
	}//end build()

	/**
	 * One record, as the mapper would return it.
	 *
	 * @param string $principal The caller.
	 * @param int $calls How many calls it made.
	 *
	 * @return ApiCallRecord The record.
	 */
	private static function record(string $principal, int $calls): ApiCallRecord {
		$record = new ApiCallRecord();
		$record->setPrincipal($principal);
		$record->setRoute('/apps/openregister/api/objects/zaken/melding');
		$record->setMethod('GET');
		$record->setApiVersion('1');
		$record->setCallCount($calls);

		return $record;
	}//end record()

	public function testADeprecationBecomesAConversation(): void {
		[$controller] = $this->build(
			rows: [self::record('leverancier-a', 4821), self::record('leverancier-b', 17)]
		);

		$response = $controller->index();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(
			['leverancier-a', 'leverancier-b'],
			array_column($response->getData()['callers'], 'principal'),
			'Both suppliers calling the deprecated endpoint are named, with their counts.'
		);
		$this->assertSame(4821, $response->getData()['callers'][0]['calls']);
	}//end testADeprecationBecomesAConversation()

	public function testTheRecordCarriesNoBody(): void {
		[$controller] = $this->build(rows: [self::record('leverancier-a', 1)]);

		$row = $controller->index()->getData()['callers'][0];

		$this->assertSame(
			['id', 'principal', 'route', 'method', 'version', 'calls', 'firstSeen', 'lastSeen'],
			array_keys($row)
		);
	}//end testTheRecordCarriesNoBody()

	public function testANonAdministratorCannotReadWhoIntegratesWithThisGemeente(): void {
		[$controller] = $this->build(isAdmin: false);

		$this->assertSame(Http::STATUS_FORBIDDEN, $controller->index()->getStatus());
		$this->assertSame(Http::STATUS_FORBIDDEN, $controller->readDeclaration()->getStatus());
		$this->assertSame(Http::STATUS_FORBIDDEN, $controller->writeDeclaration()->getStatus());
	}//end testANonAdministratorCannotReadWhoIntegratesWithThisGemeente()

	public function testThePeriodIsNarrowedByTheCaller(): void {
		[$controller, $mapper] = $this->build(params: ['from' => '2026-08-01', 'to' => '2026-09-01']);

		$mapper->expects($this->once())
			->method('findInPeriod')
			->with(
				$this->callback(static fn ($from): bool => ($from->format('Y-m-d') === '2026-08-01')),
				$this->callback(static fn ($to): bool => ($to->format('Y-m-d') === '2026-09-01')),
				$this->anything(),
				$this->anything(),
			)
			->willReturn([]);

		$controller->index();
	}//end testThePeriodIsNarrowedByTheCaller()

	public function testAnUnparseableDateFallsBackRatherThanFailing(): void {
		[$controller] = $this->build(params: ['from' => 'last Tuesday-ish']);

		$this->assertSame(Http::STATUS_OK, $controller->index()->getStatus());
	}//end testAnUnparseableDateFallsBackRatherThanFailing()

	public function testTheDeclarationIsReadableByAnAdministrator(): void {
		[$controller] = $this->build();

		$data = $controller->readDeclaration()->getData();

		$this->assertSame('1', $data['currentVersion']);
		$this->assertSame([['version' => '1', 'status' => 'supported', 'description' => ApiVersionCatalogue::BUILT_IN[0]['description']]], $data['versions']);
		$this->assertFalse($data['administered'], 'An unconfigured instance says so rather than implying somebody edited it.');
	}//end testTheDeclarationIsReadableByAnAdministrator()

	public function testAValidDeclarationIsStored(): void {
		[$controller] = $this->build(
			params: [
				'versions' => [
					['id' => '1', 'status' => 'deprecated', 'sunset' => '2027-03-01', 'successor' => '2'],
					['id' => '2', 'status' => 'supported'],
				],
			]
		);

		$response = $controller->writeDeclaration();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertTrue($response->getData()['stored']);
		$this->assertStringContainsString('"sunset":"2027-03-01"', (string)$this->stored);
	}//end testAValidDeclarationIsStored()

	public function testABrokenDeclarationIsRefusedRatherThanStored(): void {
		[$controller] = $this->build(
			params: ['versions' => [['id' => '1', 'status' => 'deprecated']]]
		);

		$response = $controller->writeDeclaration();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertNull(
			$this->stored,
			'Storing it would give a 200, serve the built-in contract back, and leave the administrator with no idea their edit did nothing.'
		);
		$this->assertArrayHasKey('1', $response->getData()['rejections']);
	}//end testABrokenDeclarationIsRefusedRatherThanStored()

	public function testADeclarationWithNothingSupportedIsRefused(): void {
		[$controller] = $this->build(
			params: ['versions' => [['id' => '1', 'status' => 'withdrawn', 'successor' => '2']]]
		);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $controller->writeDeclaration()->getStatus());
		$this->assertNull($this->stored);
	}//end testADeclarationWithNothingSupportedIsRefused()

	public function testAnEmptyDeclarationIsRefused(): void {
		[$controller] = $this->build(params: ['versions' => []]);

		$response = $controller->writeDeclaration();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertStringContainsString('at least one version', $response->getData()['rejections']['*']);
	}//end testAnEmptyDeclarationIsRefused()

	public function testAMissingVersionsListIsABadRequest(): void {
		[$controller] = $this->build();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $controller->writeDeclaration()->getStatus());
	}//end testAMissingVersionsListIsABadRequest()
}//end class
