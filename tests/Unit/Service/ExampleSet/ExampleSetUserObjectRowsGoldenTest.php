<?php

/**
 * Golden: what notes, read states and watchers refuse, and what they write,
 * pinned on exact messages and exact arguments.
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
 *
 * @spec openspec/specs/example-sets/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\ExampleSet;

use DateTime;
use OCA\OpenRegister\Db\ObjectReadState;
use OCA\OpenRegister\Db\ObjectReadStateMapper;
use OCA\OpenRegister\Notifications\Db\Watcher;
use OCA\OpenRegister\Notifications\Db\WatcherMapperInterface;
use OCA\OpenRegister\Service\ExampleSet\ExampleSetNotes;
use OCA\OpenRegister\Service\ExampleSet\ExampleSetReadStates;
use OCA\OpenRegister\Service\ExampleSet\ExampleSetRow;
use OCA\OpenRegister\Service\ExampleSet\ExampleSetWatchers;
use OCP\Comments\IComment;
use OCP\Comments\ICommentsManager;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use RuntimeException;

/**
 * @covers \OCA\OpenRegister\Service\ExampleSet\ExampleSetNotes
 * @covers \OCA\OpenRegister\Service\ExampleSet\ExampleSetReadStates
 * @covers \OCA\OpenRegister\Service\ExampleSet\ExampleSetWatchers
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetEngineRows
 */
class ExampleSetUserObjectRowsGoldenTest extends TestCase {
	private const USERS = ['alice', 'bob'];

	/**
	 * A container that hands out one service.
	 *
	 * @param object $service The service.
	 *
	 * @return ContainerInterface
	 */
	private function container(object $service): ContainerInterface {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($service);

		return $container;
	}//end container()

	/**
	 * Note rows and their exact refusal.
	 *
	 * @return array<string, array{0: array<string, mixed>, 1: string}>
	 */
	public static function noteCases(): array {
		return [
			'fine'             => [['user' => 'alice', 'objectUuid' => 'o-1', 'message' => 'Hi'], ''],
			'fine dated'       => [['user' => 'bob', 'objectUuid' => 'o-1', 'message' => 'Hi', 'createdAt' => '2025-03-04T05:06:07+00:00'], ''],
			'unknown fields'   => [['user' => 'alice', 'colour' => 1, 'size' => 2], 'field "colour", "size" is not a note field a set may write'],
			'no user'          => [['objectUuid' => 'o-1', 'message' => 'Hi'], 'user "" is not an account the set creates or adopts'],
			'foreign user'     => [['user' => 'eve', 'objectUuid' => 'o-1', 'message' => 'Hi'], 'user "eve" is not an account the set creates or adopts'],
			'blank object'     => [['user' => 'alice', 'objectUuid' => '  ', 'message' => 'Hi'], 'a note needs the object it is on (objectUuid)'],
			'object not text'  => [['user' => 'alice', 'objectUuid' => 7, 'message' => 'Hi'], 'a note needs the object it is on (objectUuid)'],
			'no message'       => [['user' => 'alice', 'objectUuid' => 'o-1'], 'a note needs a message'],
			'blank message'    => [['user' => 'alice', 'objectUuid' => 'o-1', 'message' => ' '], 'a note needs a message'],
			'bad createdAt'    => [['user' => 'alice', 'objectUuid' => 'o-1', 'message' => 'Hi', 'createdAt' => 'not a date'], 'createdAt is a date and time'],
			'blank createdAt'  => [['user' => 'alice', 'objectUuid' => 'o-1', 'message' => 'Hi', 'createdAt' => ''], 'createdAt is a date and time'],
			'null createdAt'   => [['user' => 'alice', 'objectUuid' => 'o-1', 'message' => 'Hi', 'createdAt' => null], ''],
			'fields before user' => [['user' => 'eve', 'x' => 1], 'field "x" is not a note field a set may write'],
		];
	}//end noteCases()

	/**
	 * A note row gets exactly its refusal.
	 *
	 * @param array<string, mixed> $data     The filled fields.
	 * @param string               $expected The refusal.
	 *
	 * @return void
	 *
	 * @dataProvider noteCases
	 */
	public function testNoteRefusal(array $data, string $expected): void {
		$notes = new ExampleSetNotes($this->createMock(ContainerInterface::class));
		$this->assertSame($expected, $notes->refusal($data, self::USERS));
	}//end testNoteRefusal()

	/**
	 * Read-state rows and their exact refusal.
	 *
	 * @return array<string, array{0: array<string, mixed>, 1: string}>
	 */
	public static function readStateCases(): array {
		return [
			'fine'           => [['user' => 'alice', 'objectUuid' => 'o-1', 'register' => 'r', 'schema' => 's'], ''],
			'fine dated'     => [['user' => 'alice', 'objectUuid' => 'o-1', 'seenAt' => '2025-03-04T05:06:07+00:00'], ''],
			'unknown field'  => [['user' => 'alice', 'message' => 'x'], 'field "message" is not a read-state field a set may write'],
			'foreign user'   => [['user' => 'eve', 'objectUuid' => 'o-1'], 'user "eve" is not an account the set creates or adopts'],
			'no object'      => [['user' => 'alice'], 'a read state needs the object that was opened (objectUuid)'],
			'bad seenAt'     => [['user' => 'alice', 'objectUuid' => 'o-1', 'seenAt' => 'yesterday-ish'], 'seenAt is a date and time'],
			'seenAt not text' => [['user' => 'alice', 'objectUuid' => 'o-1', 'seenAt' => 5], 'seenAt is a date and time'],
		];
	}//end readStateCases()

	/**
	 * A read-state row gets exactly its refusal.
	 *
	 * @param array<string, mixed> $data     The filled fields.
	 * @param string               $expected The refusal.
	 *
	 * @return void
	 *
	 * @dataProvider readStateCases
	 */
	public function testReadStateRefusal(array $data, string $expected): void {
		$states = new ExampleSetReadStates($this->createMock(ContainerInterface::class));
		$this->assertSame($expected, $states->refusal($data, self::USERS));
	}//end testReadStateRefusal()

	/**
	 * Watcher rows and their exact refusal.
	 *
	 * @return array<string, array{0: array<string, mixed>, 1: string}>
	 */
	public static function watcherCases(): array {
		return [
			'fine'          => [['user' => 'bob', 'objectUuid' => 'o-1', 'notify' => false], ''],
			'fine bare'     => [['user' => 'bob', 'objectUuid' => 'o-1'], ''],
			'unknown field' => [['user' => 'bob', 'seenAt' => 'x'], 'field "seenAt" is not a watcher field a set may write'],
			'foreign user'  => [['user' => 'eve', 'objectUuid' => 'o-1'], 'user "eve" is not an account the set creates or adopts'],
			'no object'     => [['user' => 'bob', 'objectUuid' => ''], 'a watcher needs the object it follows (objectUuid)'],
			'bad notify'    => [['user' => 'bob', 'objectUuid' => 'o-1', 'notify' => 'yes'], 'notify is true or false'],
			'null notify'   => [['user' => 'bob', 'objectUuid' => 'o-1', 'notify' => null], ''],
		];
	}//end watcherCases()

	/**
	 * A watcher row gets exactly its refusal.
	 *
	 * @param array<string, mixed> $data     The filled fields.
	 * @param string               $expected The refusal.
	 *
	 * @return void
	 *
	 * @dataProvider watcherCases
	 */
	public function testWatcherRefusal(array $data, string $expected): void {
		$watchers = new ExampleSetWatchers($this->createMock(ContainerInterface::class));
		$this->assertSame($expected, $watchers->refusal($data, self::USERS));
	}//end testWatcherRefusal()

	/**
	 * A read state is marked with its register, schema and moment, and recorded by user and object.
	 *
	 * @return void
	 */
	public function testReadStateWrite(): void {
		$mapper = $this->createMock(ObjectReadStateMapper::class);
		$mapper->expects($this->exactly(2))->method('markSeen')->willReturnCallback(
			function (...$args) {
				static $call = 0;
				$call++;
				if ($call === 1) {
					$this->assertSame(['alice', 'o-1', 'r', 's', null], array_slice($args, 0, 5));
					$this->assertInstanceOf(DateTime::class, $args[5]);
					$this->assertSame('2025-03-04T05:06:07+00:00', $args[5]->format('c'));
					return $this->createMock(ObjectReadState::class);
				}

				$this->assertSame(['bob', 'o-2', null, null, null, null], $args);
				throw new RuntimeException('db down');
			}
		);
		$states = new ExampleSetReadStates($this->container($mapper));

		$this->assertSame(
			['error' => '', 'entry' => ['user' => 'alice', 'objectUuid' => 'o-1']],
			$states->write(new ExampleSetRow('app', 'k', ['user' => 'alice', 'objectUuid' => 'o-1', 'register' => 'r', 'schema' => 's', 'seenAt' => '2025-03-04T05:06:07+00:00']))
		);
		$this->assertSame(
			['error' => 'db down', 'entry' => null],
			$states->write(new ExampleSetRow('app', 'k', ['user' => 'bob', 'objectUuid' => 'o-2']))
		);
	}//end testReadStateWrite()

	/**
	 * A watcher subscribes with its register, schema and notify flag, and is recorded by user and object.
	 *
	 * @return void
	 */
	public function testWatcherWrite(): void {
		$mapper = $this->createMock(WatcherMapperInterface::class);
		$seen   = [];
		$mapper->expects($this->exactly(2))->method('subscribe')->willReturnCallback(
			function (...$args) use (&$seen) {
				$seen[] = $args;
				if (count($seen) === 2) {
					throw new RuntimeException('no table');
				}

				return $this->createMock(Watcher::class);
			}
		);
		$watchers = new ExampleSetWatchers($this->container($mapper));

		$this->assertSame(
			['error' => '', 'entry' => ['user' => 'bob', 'objectUuid' => 'o-1']],
			$watchers->write(new ExampleSetRow('app', 'k', ['user' => 'bob', 'objectUuid' => 'o-1', 'register' => 7, 'notify' => false]))
		);
		$this->assertSame(
			['error' => 'no table', 'entry' => null],
			$watchers->write(new ExampleSetRow('app', 'k', ['user' => 'alice', 'objectUuid' => 'o-2', 'schema' => 's']))
		);
		$this->assertSame([['bob', 'o-1', '7', null, false], ['alice', 'o-2', null, 's', null]], $seen);
	}//end testWatcherWrite()

	/**
	 * A note is created as the user on the object, with its message, verb and moment, then saved.
	 *
	 * @return void
	 */
	public function testNoteWrite(): void {
		$log     = [];
		$comment = $this->createMock(IComment::class);
		$comment->method('setMessage')->willReturnCallback(function ($message) use (&$log, $comment) {
			$log[] = ['message', $message];
			return $comment;
		});
		$comment->method('setVerb')->willReturnCallback(function ($verb) use (&$log, $comment) {
			$log[] = ['verb', $verb];
			return $comment;
		});
		$comment->method('setCreationDateTime')->willReturnCallback(function ($moment) use (&$log, $comment) {
			$log[] = ['created', $moment->format('c')];
			return $comment;
		});
		$comment->method('getId')->willReturn('42');
		$comments = $this->createMock(ICommentsManager::class);
		$comments->method('create')->willReturnCallback(function (...$args) use (&$log, $comment) {
			$log[] = array_merge(['create'], $args);
			return $comment;
		});
		$comments->method('save')->willReturnCallback(function () use (&$log) {
			$log[] = ['save'];
			return true;
		});
		$notes = new ExampleSetNotes($this->container($comments));

		$this->assertSame(
			['error' => '', 'entry' => ['id' => '42']],
			$notes->write(new ExampleSetRow('app', 'k', ['user' => 'alice', 'objectUuid' => 'o-1', 'message' => 'Hi', 'createdAt' => '2025-03-04T05:06:07+00:00']))
		);
		$this->assertSame(
			['error' => '', 'entry' => ['id' => '42']],
			$notes->write(new ExampleSetRow('app', 'k', ['user' => 'bob', 'objectUuid' => 'o-2', 'message' => 'Yo']))
		);
		$this->assertSame(
			[
				['create', 'users', 'alice', 'openregister', 'o-1'],
				['message', 'Hi'],
				['verb', 'comment'],
				['created', '2025-03-04T05:06:07+00:00'],
				['save'],
				['create', 'users', 'bob', 'openregister', 'o-2'],
				['message', 'Yo'],
				['verb', 'comment'],
				['save'],
			],
			$log
		);
	}//end testNoteWrite()

	/**
	 * A note whose save fails reports the failure.
	 *
	 * @return void
	 */
	public function testNoteWriteFailure(): void {
		$comments = $this->createMock(ICommentsManager::class);
		$comments->method('create')->willThrowException(new RuntimeException('no comments'));
		$notes = new ExampleSetNotes($this->container($comments));

		$this->assertSame(
			['error' => 'no comments', 'entry' => null],
			$notes->write(new ExampleSetRow('app', 'k', ['user' => 'alice', 'objectUuid' => 'o-1', 'message' => 'Hi']))
		);
	}//end testNoteWriteFailure()
	/**
	 * A read state and a watcher are lost when the lookup finds nothing or fails, and revert to `removed` or `failed`.
	 *
	 * @return void
	 */
	public function testLostAndRevert(): void {
		$entry  = ['user' => 'alice', 'objectUuid' => 'o-1'];
		$states = $this->createMock(ObjectReadStateMapper::class);
		$states->method('findOne')->willReturnOnConsecutiveCalls(
			$this->createMock(ObjectReadState::class),
			null,
			$this->throwException(new RuntimeException('x'))
		);
		$states->method('markUnread')->willReturnOnConsecutiveCalls(true, false, $this->throwException(new RuntimeException('x')));
		$readStates = new ExampleSetReadStates($this->container($states));
		$this->assertSame([false, true, true], [$readStates->lost($entry), $readStates->lost($entry), $readStates->lost($entry)]);
		$this->assertSame(['removed', 'removed', 'failed'], [$readStates->revert($entry), $readStates->revert($entry), $readStates->revert($entry)]);

		$follows = $this->createMock(WatcherMapperInterface::class);
		$follows->expects($this->exactly(3))->method('findOne')->with('alice', 'o-1')->willReturnOnConsecutiveCalls(
			$this->createMock(Watcher::class),
			null,
			$this->throwException(new RuntimeException('x'))
		);
		$follows->expects($this->exactly(3))->method('unsubscribe')->with('alice', 'o-1')->willReturnOnConsecutiveCalls(true, false, $this->throwException(new RuntimeException('x')));
		$watchers = new ExampleSetWatchers($this->container($follows));
		$this->assertSame([false, true, true], [$watchers->lost($entry), $watchers->lost($entry), $watchers->lost($entry)]);
		$this->assertSame(['removed', 'removed', 'failed'], [$watchers->revert($entry), $watchers->revert($entry), $watchers->revert($entry)]);
	}//end testLostAndRevert()
}//end class
