<?php

/**
 * ApiCallRecorderTest — the route collapse, and the promise that no payload
 * can reach this table.
 *
 * The collapse is lossy on purpose and has to stay conservative: collapsing a
 * segment that is actually a register or a schema slug would merge two
 * different endpoints into one row and quietly halve the number of routes an
 * administrator can see.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\ApiCaller
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @spec openspec/changes/api-as-a-versioned-surface/specs/api-surface-governance/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\ApiCaller;

use DateTime;
use OCA\OpenRegister\Db\ApiCallRecord;
use OCA\OpenRegister\Db\ApiCallRecordMapper;
use OCA\OpenRegister\Service\ApiCaller\ApiCallRecorder;
use OCP\IAppConfig;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * @covers \OCA\OpenRegister\Service\ApiCaller\ApiCallRecorder
 * @covers \OCA\OpenRegister\Db\ApiCallRecord
 */
class ApiCallRecorderTest extends TestCase {

	/**
	 * Build a recorder over a mapper double.
	 *
	 * @param bool $enabled Whether the record is switched on.
	 * @param string|null $uid The signed-in user, or null.
	 *
	 * @return array{0: ApiCallRecorder, 1: ApiCallRecordMapper&MockObject} The recorder and its mapper.
	 */
	private function build(bool $enabled = true, ?string $uid = 'leverancier'): array {
		$mapper = $this->createMock(ApiCallRecordMapper::class);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueBool')->willReturn($enabled);

		$session = $this->createMock(IUserSession::class);
		if ($uid === null) {
			$session->method('getUser')->willReturn(null);
		} else {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
			$session->method('getUser')->willReturn($user);
		}

		$recorder = new ApiCallRecorder(
			$mapper,
			$session,
			$appConfig,
			$this->createMock(\Psr\Log\LoggerInterface::class),
		);

		return [$recorder, $mapper];
	}//end build()

	/**
	 * @dataProvider provideRoutes
	 */
	public function testARouteIsCollapsedBackToItsShape(string $path, string $expected, string $why): void {
		$this->assertSame($expected, ApiCallRecorder::routeOf(path: $path), $why);
	}//end testARouteIsCollapsedBackToItsShape()

	public static function provideRoutes(): array {
		return [
			'a uuid becomes an id' => [
				'/apps/openregister/api/objects/zaken/melding/3f2504e0-4f89-41d3-9a0c-0305e82c3301',
				'/apps/openregister/api/objects/zaken/melding/{id}',
				'A row per object id would make the table larger than the objects it describes.',
			],
			'a numeric id becomes an id' => [
				'/apps/openregister/api/registers/12/schemas',
				'/apps/openregister/api/registers/{id}/schemas',
				'',
			],
			'a long hex id becomes an id' => [
				'/apps/openregister/api/objects/a1b2c3d4e5f60718293a4b5c',
				'/apps/openregister/api/objects/{id}',
				'',
			],
			'a register slug is left alone' => [
				'/apps/openregister/api/objects/zaken/melding',
				'/apps/openregister/api/objects/zaken/melding',
				'Collapsing a slug would merge two endpoints into one row.',
			],
			'a short hex-looking slug is left alone' => [
				'/apps/openregister/api/objects/beheer/aface',
				'/apps/openregister/api/objects/beheer/aface',
				'Five hex characters is a word, not an identifier.',
			],
			'the index.php prefix is normalised away' => [
				'/index.php/apps/openregister/api/capabilities',
				'/apps/openregister/api/capabilities',
				'Two rows for one route is a report an administrator has to add up by hand.',
			],
			'a query string is dropped' => [
				'/apps/openregister/api/objects/zaken/melding?_limit=50&_search=secret',
				'/apps/openregister/api/objects/zaken/melding',
				'A query string can carry a search term, which is case data.',
			],
			'a trailing slash does not make a new route' => [
				'/apps/openregister/api/capabilities/',
				'/apps/openregister/api/capabilities',
				'',
			],
		];
	}//end provideRoutes()

	public function testACallIsCountedAgainstItsCaller(): void {
		[$recorder, $mapper] = $this->build();

		$mapper->expects($this->once())
			->method('count')
			->with(
				$this->equalTo('leverancier'),
				$this->equalTo('/apps/openregister/api/objects/zaken/melding/{id}'),
				$this->equalTo('GET'),
				$this->equalTo('1'),
				$this->isInstanceOf(DateTime::class),
			)
			->willReturn(true);

		$recorder->record(
			principal: 'leverancier',
			path: '/apps/openregister/api/objects/zaken/melding/3f2504e0-4f89-41d3-9a0c-0305e82c3301',
			method: 'get',
			apiVersion: '1',
		);
	}//end testACallIsCountedAgainstItsCaller()

	public function testNothingIsRecordedWhenTheRecordIsSwitchedOff(): void {
		[$recorder, $mapper] = $this->build(enabled: false);

		$mapper->expects($this->never())->method('count');

		$recorder->record(principal: 'leverancier', path: '/apps/openregister/api/x', method: 'GET', apiVersion: '1');
	}//end testNothingIsRecordedWhenTheRecordIsSwitchedOff()

	public function testAFailedRecordDoesNotFailTheCall(): void {
		[$recorder, $mapper] = $this->build();
		$mapper->method('count')->willThrowException(new RuntimeException('deadlock'));

		$recorder->record(principal: 'leverancier', path: '/apps/openregister/api/x', method: 'GET', apiVersion: '1');

		$this->assertTrue(true, 'A gemeente API going down because its usage log deadlocked is an outage caused by an ornament.');
	}//end testAFailedRecordDoesNotFailTheCall()

	public function testAnAnonymousCallerHasAnEmptyPrincipal(): void {
		[$recorder] = $this->build(uid: null);

		$this->assertSame('', $recorder->principal());
	}//end testAnAnonymousCallerHasAnEmptyPrincipal()

	public function testASignedInCallerIsNamed(): void {
		[$recorder] = $this->build(uid: 'leverancier');

		$this->assertSame('leverancier', $recorder->principal());
	}//end testASignedInCallerIsNamed()

	public function testTheRecordHoldsNoBody(): void {
		$record = new ApiCallRecord();
		$record->setPrincipal('leverancier');
		$record->setRoute('/apps/openregister/api/objects/zaken/melding');
		$record->setMethod('POST');
		$record->setApiVersion('1');
		$record->setCallCount(42);

		$published = $record->jsonSerialize();

		$this->assertSame(
			['id', 'principal', 'route', 'method', 'version', 'calls', 'firstSeen', 'lastSeen'],
			array_keys($published),
			'Recording what a caller sent puts a second copy of case data in a log.'
		);
		$this->assertSame(42, $published['calls']);
	}//end testTheRecordHoldsNoBody()

	public function testAnAnonymousCallerIsPublishedByName(): void {
		$record = new ApiCallRecord();
		$record->setPrincipal('');

		$this->assertSame(
			'anonymous',
			$record->jsonSerialize()['principal'],
			'An empty principal in a report reads as a bug in the report.'
		);
	}//end testAnAnonymousCallerIsPublishedByName()
}//end class
