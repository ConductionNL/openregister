<?php

/**
 * Unit test for ExampleSetPrerequisites: which required registers it imports,
 * keeps or fails, which organisations it records, and the context it hands the fill.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\ExampleSet
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

namespace OCA\OpenRegister\Tests\Unit\Service\ExampleSet;

use DateTimeImmutable;
use DateTimeZone;
use OCA\OpenRegister\Service\ExampleSet\ExampleSetInstance;
use OCA\OpenRegister\Service\ExampleSet\ExampleSetPeople;
use OCA\OpenRegister\Service\ExampleSet\ExampleSetPrerequisites;
use OCA\OpenRegister\Service\ExampleSet\ExampleSetRecord;
use OCA\OpenRegister\Service\ExampleSet\ExampleSetRegisters;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Service\ExampleSet\ExampleSetPrerequisites
 * @covers \OCA\OpenRegister\Service\ExampleSet\ExampleSetRequiredRegister
 */
class ExampleSetPrerequisitesBehaviourTest extends TestCase {

	/**
	 * The registers.
	 *
	 * @var ExampleSetRegisters&MockObject
	 */
	private ExampleSetRegisters&MockObject $registers;

	/**
	 * The instance organisations.
	 *
	 * @var ExampleSetInstance&MockObject
	 */
	private ExampleSetInstance&MockObject $instance;

	/**
	 * The record store.
	 *
	 * @var ExampleSetRecord&MockObject
	 */
	private ExampleSetRecord&MockObject $record;

	/**
	 * The people.
	 *
	 * @var ExampleSetPeople&MockObject
	 */
	private ExampleSetPeople&MockObject $people;

	/**
	 * The class under test.
	 *
	 * @var ExampleSetPrerequisites
	 */
	private ExampleSetPrerequisites $subject;

	/**
	 * Mocked collaborators.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->registers = $this->createMock(ExampleSetRegisters::class);
		$this->instance  = $this->createMock(ExampleSetInstance::class);
		$this->record    = $this->createMock(ExampleSetRecord::class);
		$this->people    = $this->createMock(ExampleSetPeople::class);
		$this->subject   = new ExampleSetPrerequisites($this->registers, $this->instance, $this->record, $this->people);
	}//end setUp()

	/**
	 * A register that is there and current is kept: nothing imported, nothing written.
	 *
	 * @return void
	 */
	public function testACurrentRegisterIsKept(): void {
		$this->registers->method('exists')->willReturn(true);
		$this->registers->expects($this->once())->method('isBehind')->with('cases', false)->willReturn(false);
		$this->registers->expects($this->never())->method('import');
		$this->record->expects($this->never())->method('write');
		$book = ['registers' => ['cases' => ['job-0']]];

		$result = $this->subject->ensure(plan: ['set' => 'demo', 'requires' => ['cases' => []]], record: $book);

		$this->assertSame(['refusals' => [], 'registers' => ['cases' => 'kept']], $result);
		$this->assertSame(['registers' => ['cases' => ['job-0']]], $book);
	}//end testACurrentRegisterIsKept()

	/**
	 * A missing register is imported, its jobs recorded and the record written.
	 *
	 * @return void
	 */
	public function testAMissingRegisterIsImportedAndItsJobsRecorded(): void {
		$this->registers->method('exists')->willReturnOnConsecutiveCalls(false, true);
		$this->registers->expects($this->once())->method('import')->with('cases')->willReturn(['jobs' => ['j1'], 'error' => '']);
		$this->registers->expects($this->never())->method('importMock');
		$this->record->expects($this->once())->method('write')->with('demo', ['registers' => ['cases' => ['j1']]]);
		$book = ['registers' => []];

		$result = $this->subject->ensure(plan: ['set' => 'demo', 'requires' => ['cases' => ['mock' => 'yes']]], record: $book);

		$this->assertSame(['refusals' => [], 'registers' => ['cases' => 'imported']], $result);
		$this->assertSame(['registers' => ['cases' => ['j1']]], $book);
	}//end testAMissingRegisterIsImportedAndItsJobsRecorded()

	/**
	 * A register whose recorded data was removed is imported again even though it exists; a mock is imported as mock.
	 *
	 * @return void
	 */
	public function testARegisterWithRemovedDataIsImportedAgainAsMock(): void {
		$this->registers->method('exists')->willReturn(true);
		$this->registers->expects($this->never())->method('isBehind');
		$this->registers->expects($this->once())->method('importMock')->with('cases')->willReturn(['jobs' => [], 'error' => '']);
		$this->record->expects($this->never())->method('write');
		$book = ['registers' => ['cases' => []]];

		$result = $this->subject->ensure(plan: ['set' => 'demo', 'requires' => ['cases' => ['mock' => true]]], record: $book);

		$this->assertSame(['refusals' => [], 'registers' => ['cases' => 'imported']], $result);
	}//end testARegisterWithRemovedDataIsImportedAgainAsMock()

	/**
	 * A register that is behind is imported again.
	 *
	 * @return void
	 */
	public function testARegisterThatIsBehindIsImported(): void {
		$this->registers->method('exists')->willReturn(true);
		$this->registers->method('isBehind')->with('cases', true)->willReturn(true);
		$this->registers->expects($this->once())->method('importMock')->willReturn(['jobs' => [], 'error' => '']);

		$book   = ['registers' => ['cases' => ['j0']]];
		$result = $this->subject->ensure(plan: ['set' => 'demo', 'requires' => ['cases' => ['mock' => true]]], record: $book);

		$this->assertSame('imported', $result['registers']['cases']);
	}//end testARegisterThatIsBehindIsImported()

	/**
	 * An import that errors fails with its error, and one that leaves the register missing fails as still missing.
	 *
	 * @return void
	 */
	public function testAFailedImportIsRefused(): void {
		$this->registers->method('exists')->willReturn(false);
		$this->registers->method('import')->willReturnCallback(
			fn (string $slug): array => $slug === 'broken'
				? ['jobs' => ['j4'], 'error' => 'descriptor unreadable']
				: ['jobs' => [], 'error' => '']
		);
		$this->record->expects($this->once())->method('write');
		$book = ['registers' => []];

		$result = $this->subject->ensure(plan: ['set' => 'demo', 'requires' => ['broken' => [], 'gone' => []]], record: $book);

		$this->assertSame(
			[
				'refusals'  => [
					'register "broken" is required and could not be imported from OpenRegister\'s descriptor: descriptor unreadable',
					'register "gone" is required and could not be imported from OpenRegister\'s descriptor: it is still not on this instance',
				],
				'registers' => ['broken' => 'failed', 'gone' => 'failed'],
			],
			$result
		);
		$this->assertSame(['registers' => ['broken' => ['j4']]], $book);
	}//end testAFailedImportIsRefused()

	/**
	 * A created organisation is recorded and the record written; an existing one is not; a failed one is refused.
	 *
	 * @return void
	 */
	public function testOrganisationsAreRecordedOnlyWhenCreated(): void {
		$this->instance->method('ensure')->willReturnCallback(
			function (array $declared, array $made): array {
				return match ($declared['slug']) {
					'new'    => ['uuid' => 'u-new', 'created' => true, 'error' => ''],
					'old'    => ['uuid' => 'u-old', 'created' => false, 'error' => ''],
					default  => ['uuid' => '', 'created' => false, 'error' => 'quota refused'],
				};
			}
		);
		$this->record->expects($this->once())->method('write')
			->with('demo', ['registers' => [], 'organisations' => ['u-0', 'u-new']]);
		$book = ['registers' => [], 'organisations' => ['u-0']];
		$plan = ['set' => 'demo', 'organisations' => [['slug' => 'new'], ['slug' => 'old'], ['slug' => 'bad']]];

		$result = $this->subject->ensure(plan: $plan, record: $book);

		$this->assertSame(
			['refusals' => ['OpenRegister organisation "bad" could not be created or completed: quota refused'], 'registers' => []],
			$result
		);
		$this->assertSame(['u-0', 'u-new'], $book['organisations']);
	}//end testOrganisationsAreRecordedOnlyWhenCreated()

	/**
	 * The fill context carries the cast, the day, the service accounts by key, and the instance's organisations.
	 *
	 * @return void
	 */
	public function testTheContextCarriesCastDayAccountsAndInstance(): void {
		$today = new DateTimeImmutable('2026-03-01 00:00:00', new DateTimeZone('UTC'));
		$plan  = ['cast' => ['entries' => ['anna' => ['uid' => 'anna']]]];
		$this->people->method('serviceAccounts')->with($plan)->willReturn([
			['key' => 'bot', 'uid' => 'svc-bot', 'displayName' => 'Bot', 'groups' => ['g']],
		]);
		$this->instance->method('uuids')->willReturn(['acme' => 'u-acme']);
		$this->instance->method('values')->willReturn(['name' => 'Zuiddrecht']);

		$this->assertSame(
			[
				'cast'          => ['anna' => ['uid' => 'anna']],
				'today'         => $today,
				'accounts'      => ['bot' => ['uid' => 'svc-bot', 'displayName' => 'Bot']],
				'organisations' => ['acme' => 'u-acme'],
				'instance'      => ['name' => 'Zuiddrecht'],
			],
			$this->subject->context(plan: $plan, today: $today)
		);
	}//end testTheContextCarriesCastDayAccountsAndInstance()
}//end class
