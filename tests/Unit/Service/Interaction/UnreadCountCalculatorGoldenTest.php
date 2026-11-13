<?php

/**
 * Golden test for UnreadCountCalculator::countsFor().
 *
 * Pins the per-sub-resource unread counts for a never-seen object, a seen one
 * with per-sub-resource stamps (one unparseable), entries that are no moment,
 * a body that is no array and a file lookup that throws, ahead of a parameter
 * refactor of the private count steps. Every moment is fixed; nothing reads
 * the clock.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Interaction
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Interaction;

use DateTime;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\ObjectReadState;
use OCA\OpenRegister\Files\Db\FileMapperInterface;
use OCA\OpenRegister\Service\Interaction\SubstantiveChangeEvaluator;
use OCA\OpenRegister\Service\Interaction\UnreadCountCalculator;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Golden pins for the unread badge counts.
 *
 * @covers \OCA\OpenRegister\Service\Interaction\UnreadCountCalculator
 * @uses \OCA\OpenRegister\Db\ObjectEntity
 * @uses \OCA\OpenRegister\Db\ObjectReadState
 * @uses \OCA\OpenRegister\Service\Interaction\SubResourceWindow
 */
class UnreadCountCalculatorGoldenTest extends TestCase {

	/**
	 * The sub-resources every object declares here.
	 */
	private const DESCRIPTORS = [
		'files'   => ['kind' => 'files'],
		'notes'   => ['kind' => 'property', 'property' => 'notes', 'dateField' => 'at'],
		'tasks'   => ['kind' => 'property', 'property' => 'tasks', 'dateField' => 'due'],
		'missing' => ['kind' => 'property', 'property' => 'absent', 'dateField' => 'at'],
	];

	/**
	 * A calculator whose file lookup answers the given files, or throws when null.
	 *
	 * @param array<int, mixed>|null $files The files, or null to throw.
	 *
	 * @return UnreadCountCalculator
	 */
	private function calculator(?array $files): UnreadCountCalculator {
		$evaluator = $this->createMock(SubstantiveChangeEvaluator::class);
		$evaluator->method('subResources')->willReturn(self::DESCRIPTORS);
		$mapper = $this->createMock(FileMapperInterface::class);
		if ($files === null) {
			$mapper->method('getFilesForObject')->willThrowException(new RuntimeException('no folder'));
		} else {
			$mapper->method('getFilesForObject')->willReturn($files);
		}

		return new UnreadCountCalculator(evaluator: $evaluator, fileMapper: $mapper, logger: $this->createMock(LoggerInterface::class));
	}//end calculator()

	/**
	 * An object with notes and tasks at fixed moments.
	 *
	 * @return ObjectEntity
	 */
	private function object(): ObjectEntity {
		$object = new ObjectEntity();
		$object->setUuid('u-1');
		$object->setObject([
			'notes' => [
				['at' => '2026-01-01T10:00:00+00:00'],
				['at' => '2026-01-08T10:00:00+00:00'],
				['at' => (string) (new DateTime('2026-01-20T00:00:00+00:00'))->getTimestamp()],
				['at' => ''],
				'not an entry',
				['at' => 'not a moment'],
			],
			'tasks' => [
				['due' => (new DateTime('2026-01-09T00:00:00+00:00'))->getTimestamp()],
				['due' => '2026-01-12T00:00:00+00:00'],
				['other' => 'x'],
			],
		]);

		return $object;
	}//end object()

	/**
	 * Files at fixed moments, one row that is no file.
	 *
	 * @return array<int, mixed>
	 */
	private function files(): array {
		return [
			['mtime' => (new DateTime('2026-01-02T00:00:00+00:00'))->getTimestamp()],
			['mtime' => (new DateTime('2026-01-15T00:00:00+00:00'))->getTimestamp()],
			['mtime' => null],
			'x',
		];
	}//end files()

	/**
	 * Every count, for the states and failures above.
	 *
	 * @return void
	 */
	public function testCounts(): void {
		$state = new ObjectReadState();
		$state->setLastSeenAt(new DateTime('2026-01-10T00:00:00+00:00'));
		$state->setSubSeen(['notes' => '2026-01-05T00:00:00+00:00', 'tasks' => 'garbage', 'files' => '']);

		$noBody = new ObjectEntity();
		$noBody->setUuid('u-2');

		$got = [
			'never seen'    => $this->calculator(files: $this->files())->countsFor(object: $this->object(), state: null),
			'seen'          => $this->calculator(files: $this->files())->countsFor(object: $this->object(), state: $state),
			'files throw'   => $this->calculator(files: null)->countsFor(object: $this->object(), state: $state),
			'no body'       => $this->calculator(files: [])->countsFor(object: $noBody, state: null),
		];

		$this->assertSame(
			[
				'never seen'  => ['files' => 2, 'notes' => 3, 'tasks' => 2, 'missing' => 0],
				'seen'        => ['files' => 1, 'notes' => 2, 'tasks' => 2, 'missing' => 0],
				'files throw' => ['files' => 0, 'notes' => 2, 'tasks' => 2, 'missing' => 0],
				'no body'     => ['files' => 0, 'notes' => 0, 'tasks' => 0, 'missing' => 0],
			],
			$got
		);
	}//end testCounts()
}//end class
