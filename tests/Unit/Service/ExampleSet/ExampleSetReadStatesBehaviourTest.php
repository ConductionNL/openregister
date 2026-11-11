<?php

/**
 * Unit test for ExampleSetReadStates: which read states it refuses, the
 * sighting it marks, and how it proves and unmarks one.
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

use OCA\OpenRegister\Db\ObjectReadState;
use OCA\OpenRegister\Db\ObjectReadStateMapperInterface;
use OCA\OpenRegister\Service\ExampleSet\ExampleSetReadStates;
use OCA\OpenRegister\Service\ExampleSet\ExampleSetRow;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use RuntimeException;

/**
 * @covers \OCA\OpenRegister\Service\ExampleSet\ExampleSetReadStates
 * @covers \OCA\OpenRegister\Service\ExampleSet\ExampleSetUserObjectRows
 * @uses   \OCA\OpenRegister\Service\ExampleSet\ExampleSetRow
 * @uses   \OCA\OpenRegister\Db\ObjectReadState
 */
class ExampleSetReadStatesBehaviourTest extends TestCase {

	/**
	 * The read-state mapper.
	 *
	 * @var ObjectReadStateMapperInterface&MockObject
	 */
	private ObjectReadStateMapperInterface&MockObject $mapper;

	/**
	 * The class under test.
	 *
	 * @var ExampleSetReadStates
	 */
	private ExampleSetReadStates $subject;

	/**
	 * A container that hands out the mapper.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->mapper = $this->createMock(ObjectReadStateMapperInterface::class);
		$container    = $this->createMock(ContainerInterface::class);
		$container->method('get')->with(ObjectReadStateMapperInterface::class)->willReturn($this->mapper);
		$this->subject = new ExampleSetReadStates(container: $container);
	}//end setUp()

	/**
	 * The kind names its record part and noun.
	 *
	 * @return void
	 */
	public function testTheKindNamesItsPartAndNoun(): void {
		$this->assertSame('readStates', $this->subject->part());
		$this->assertSame('read state', $this->subject->noun());
	}//end testTheKindNamesItsPartAndNoun()

	/**
	 * Each faulty read state is refused with its reason, and a complete one is not.
	 *
	 * @return void
	 */
	public function testRefusals(): void {
		$ok    = ['user' => 'anna', 'objectUuid' => 'o-1', 'register' => 'cases', 'schema' => 'case', 'seenAt' => '2026-01-02T10:00:00Z'];
		$users = ['anna'];
		$this->assertSame('', $this->subject->refusal(data: $ok, users: $users));
		$this->assertSame('field "message" is not a read-state field a set may write', $this->subject->refusal(data: $ok + ['message' => 'x'], users: $users));
		$this->assertSame('a read state needs the object that was opened (objectUuid)', $this->subject->refusal(data: ['objectUuid' => ''] + $ok, users: $users));
		$this->assertSame('seenAt is a date and time', $this->subject->refusal(data: ['seenAt' => 'not a moment'] + $ok, users: $users));
	}//end testRefusals()

	/**
	 * A write marks a sighting carrying the user, object, register, schema and moment, and records user and object.
	 *
	 * @return void
	 */
	public function testAWriteMarksTheSighting(): void {
		$this->mapper->expects($this->once())->method('markSeen')->with(
			$this->callback(
				fn (ObjectReadState $s): bool => $s->getUserId() === 'anna'
					&& $s->getObjectUuid() === 'o-1'
					&& $s->getRegister() === '5'
					&& $s->getSchema() === null
					&& $s->getLastSeenAt()?->format('Y-m-d') === '2026-01-02'
			)
		)->willReturnArgument(0);

		$result = $this->subject->write(row: new ExampleSetRow(app: 'alpha', key: 'r', data: ['user' => 'anna', 'objectUuid' => 'o-1', 'register' => 5, 'seenAt' => '2026-01-02T10:00:00Z']));

		$this->assertSame(['error' => '', 'entry' => ['user' => 'anna', 'objectUuid' => 'o-1']], $result);
	}//end testAWriteMarksTheSighting()

	/**
	 * A sighting without a moment carries none; a mapper that fails returns its message.
	 *
	 * @return void
	 */
	public function testAWriteWithoutAMomentAndAFailedWrite(): void {
		$this->mapper->method('markSeen')->willReturnCallback(
			function (ObjectReadState $s): ObjectReadState {
				if ($s->getObjectUuid() === 'broken') {
					throw new RuntimeException('duplicate');
				}

				$this->assertNull($s->getLastSeenAt());
				return $s;
			}
		);

		$this->assertSame(
			['error' => '', 'entry' => ['user' => 'anna', 'objectUuid' => 'o-1']],
			$this->subject->write(row: new ExampleSetRow(app: 'alpha', key: 'r', data: ['user' => 'anna', 'objectUuid' => 'o-1']))
		);
		$this->assertSame(
			['error' => 'duplicate', 'entry' => null],
			$this->subject->write(row: new ExampleSetRow(app: 'alpha', key: 'r', data: ['user' => 'anna', 'objectUuid' => 'broken']))
		);
	}//end testAWriteWithoutAMomentAndAFailedWrite()

	/**
	 * A read state is lost when the mapper finds none or fails, and held when it finds one.
	 *
	 * @return void
	 */
	public function testAReadStateIsLostWhenNotFound(): void {
		$this->mapper->expects($this->exactly(3))->method('findOne')->with('anna', 'o-1')
			->willReturnOnConsecutiveCalls(new ObjectReadState(), null, $this->throwException(new RuntimeException('db')));
		$entry = ['user' => 'anna', 'objectUuid' => 'o-1'];

		$this->assertFalse($this->subject->lost(entry: $entry));
		$this->assertTrue($this->subject->lost(entry: $entry));
		$this->assertTrue($this->subject->lost(entry: $entry));
	}//end testAReadStateIsLostWhenNotFound()

	/**
	 * A revert marks the object unread for the user; a mapper that fails reports failed.
	 *
	 * @return void
	 */
	public function testARevertMarksUnread(): void {
		$this->mapper->expects($this->exactly(2))->method('markUnread')->with('anna', 'o-1')
			->willReturnOnConsecutiveCalls(true, $this->throwException(new RuntimeException('db')));
		$entry = ['user' => 'anna', 'objectUuid' => 'o-1'];

		$this->assertSame('removed', $this->subject->revert(entry: $entry));
		$this->assertSame('failed', $this->subject->revert(entry: $entry));
	}//end testARevertMarksUnread()
}//end class
