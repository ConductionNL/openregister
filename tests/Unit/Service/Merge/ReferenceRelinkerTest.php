<?php

/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Merge;

use OCA\OpenRegister\Service\Merge\ReferenceFinderInterface;
use OCA\OpenRegister\Service\Merge\ReferenceMove;
use OCA\OpenRegister\Service\Merge\ReferenceRelinker;
use OCA\OpenRegister\Service\Merge\ReferenceWriterInterface;
use OCA\OpenRegister\Service\Merge\ReferencingObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The relinker plans reference moves from object data and applies them under the actor's rights.
 *
 * @covers \OCA\OpenRegister\Service\Merge\ReferenceRelinker
 * @covers \OCA\OpenRegister\Service\Merge\ReferenceMove
 * @covers \OCA\OpenRegister\Service\Merge\ReferenceRelinkPlan
 * @uses   \OCA\OpenRegister\Service\Merge\ReferencingObject
 */
final class ReferenceRelinkerTest extends TestCase {

	private const LOSER = 'zaaksysteem-kopie';

	private const SURVIVOR = 'zaaksysteem';

	/**
	 * A finder yielding these referencing objects.
	 *
	 * @param array<int, ReferencingObject> $objects The objects.
	 *
	 * @return ReferenceFinderInterface
	 */
	private function finder(array $objects): ReferenceFinderInterface {
		return new class ($objects) implements ReferenceFinderInterface {
			/** @param array<int, ReferencingObject> $objects */
			public function __construct(private readonly array $objects) {
			}

			public function referencingObjects(string $uuid): iterable {
				return $this->objects;
			}
		};
	}

	/**
	 * A writer that accepts every move but those on an object in $refused.
	 *
	 * @param list<string> $refused Uuids the actor may not update.
	 *
	 * @return ReferenceWriterInterface
	 */
	private function writer(array $refused=[]): ReferenceWriterInterface {
		return new class ($refused, $this->applied) implements ReferenceWriterInterface {
			/** @var list<ReferenceMove> */
			public array $written = [];

			/** @param list<string> $refused */
			public function __construct(private readonly array $refused, public array &$seen) {
			}

			public function write(ReferenceMove $move, string $actor): bool {
				if (in_array($move->objectUuid, $this->refused, true) === true) {
					return false;
				}

				$this->written[] = $move;
				return true;
			}
		};
	}

	/** @var list<ReferenceMove> */
	public array $applied = [];

	public function testAModuleIsRelinkedInASuiteArrayAndAConnectionScalar(): void {
		$suite      = new ReferencingObject('suite-basis', 'catalogus', 'suite', ['applications' => [self::SURVIVOR, self::LOSER], 'title' => 'Basis']);
		$connection = new ReferencingObject('conn-1', 'catalogus', 'connection', ['module' => self::LOSER, 'name' => 'Koppeling']);
		$plan       = $this->relinker($this->finder([$suite, $connection]))->plan(self::LOSER, self::SURVIVOR);

		$this->assertCount(2, $plan->moves);

		$suiteMove = $plan->moves[0];
		$this->assertSame('applications', $suiteMove->path);
		$this->assertSame([self::SURVIVOR, self::LOSER], $suiteMove->before);
		$this->assertSame([self::SURVIVOR], $suiteMove->after, 'the duplicate the merge creates is dropped, so the suite lists the survivor once');

		$connMove = $plan->moves[1];
		$this->assertSame('module', $connMove->path);
		$this->assertSame(self::SURVIVOR, $connMove->after);
		$this->assertSame([['schema' => 'suite', 'count' => 1], ['schema' => 'connection', 'count' => 1]], $plan->references());
	}

	public function testAReferenceTheActorMayNotUpdateIsLeftAndReported(): void {
		$mine     = new ReferencingObject('mine', 'catalogus', 'suite', ['module' => self::LOSER]);
		$notMine  = new ReferencingObject('locked', 'catalogus', 'review', ['subject' => self::LOSER]);
		$writer   = $this->writer(refused: ['locked']);
		$relinker = $this->relinker($this->finder([$mine, $notMine]), writer: $writer);

		$result = $relinker->apply($relinker->plan(self::LOSER, self::SURVIVOR), 'steward');

		$this->assertSame([['object' => 'mine', 'path' => 'module', 'before' => self::LOSER, 'after' => self::SURVIVOR]], $result['applied']);
		$this->assertSame([['object' => 'locked', 'path' => 'subject']], $result['skipped']);
		$this->assertSame(['mine'], array_column(array_map(static fn (ReferenceMove $m): array => ['u' => $m->objectUuid], $writer->written), 'u'));
	}

	public function testAUriReferenceKeepsItsPrefix(): void {
		$object = new ReferencingObject('x', 'r', 's', ['ref' => 'https://example.com/api/objects/' . self::LOSER, 'untouched' => 'https://example.com/other']);
		$plan   = $this->relinker($this->finder([$object]))->plan(self::LOSER, self::SURVIVOR);

		$this->assertCount(1, $plan->moves);
		$this->assertSame('https://example.com/api/objects/' . self::SURVIVOR, $plan->moves[0]->after);
	}

	public function testANestedReferenceIsFound(): void {
		$object = new ReferencingObject('x', 'r', 's', ['meta' => ['owner' => self::LOSER, 'note' => 'keep'], 'tags' => ['a', 'b']]);
		$plan   = $this->relinker($this->finder([$object]))->plan(self::LOSER, self::SURVIVOR);

		$this->assertCount(1, $plan->moves);
		$this->assertSame('meta', $plan->moves[0]->path);
		$this->assertSame(['owner' => self::SURVIVOR, 'note' => 'keep'], $plan->moves[0]->after);
	}

	public function testAnObjectThatDoesNotReferenceTheLoserMakesNoMove(): void {
		$object = new ReferencingObject('x', 'r', 's', ['module' => self::SURVIVOR, 'list' => ['other', 'another'], 'count' => 3, 'active' => true]);
		$this->assertSame([], $this->relinker($this->finder([$object]))->plan(self::LOSER, self::SURVIVOR)->moves);
	}

	public function testAnArrayWithoutTheSurvivorKeepsEveryElement(): void {
		$object = new ReferencingObject('x', 'r', 's', ['applications' => ['other', self::LOSER]]);
		$plan   = $this->relinker($this->finder([$object]))->plan(self::LOSER, self::SURVIVOR);

		$this->assertSame(['other', self::SURVIVOR], $plan->moves[0]->after, 'with no existing survivor entry the list keeps its length');
	}

	public function testAPreExistingDuplicateInTheListIsNotCollapsed(): void {
		$object = new ReferencingObject('x', 'r', 's', ['applications' => ['other', 'other', self::LOSER]]);
		$plan   = $this->relinker($this->finder([$object]))->plan(self::LOSER, self::SURVIVOR);

		$this->assertSame(['other', 'other', self::SURVIVOR], $plan->moves[0]->after, 'only the duplicate the merge creates is dropped, not one the author wrote');
	}

	public function testThePlanStopsAtTheCapAndSaysSo(): void {
		$objects = [];
		for ($i = 0; $i < 5; $i++) {
			$objects[] = new ReferencingObject('o' . $i, 'r', 's', ['module' => self::LOSER]);
		}

		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')->with($this->stringContains('more than the 3-move cap'));
		$plan = (new ReferenceRelinker($this->finder($objects), $this->writer(), $logger, 3))->plan(self::LOSER, self::SURVIVOR);

		$this->assertTrue($plan->exceedsCap);
		$this->assertCount(3, $plan->moves);
	}

	public function testTheDefaultCapIsFiveThousand(): void {
		$this->assertSame(5000, ReferenceRelinker::DEFAULT_CAP);
	}

	private function relinker(ReferenceFinderInterface $finder, ?ReferenceWriterInterface $writer=null): ReferenceRelinker {
		return new ReferenceRelinker($finder, ($writer ?? $this->writer()), $this->createMock(LoggerInterface::class), 5000);
	}
}
