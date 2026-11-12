<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * A deletion analysis shows the caller only the referring objects they read.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Object\DeletionGraph
 * @license  EUPL-1.2
 * @link     https://github.com/ConductionNL/openregister
 *
 * @spec openspec/specs/object-lifecycle/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Object\DeletionGraph;

use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Dto\DeletionAnalysis;
use OCA\OpenRegister\Dto\DeletionEffects;
use OCA\OpenRegister\Exception\ReferentialIntegrityException;
use OCA\OpenRegister\Service\Object\DeletionGraph\ReadableDeletionAnalysis;
use OCP\AppFramework\Db\DoesNotExistException;
use PHPUnit\Framework\TestCase;

/**
 * The walk found every referring object past the read filters; the caller's own read decides which they see.
 *
 * @covers \OCA\OpenRegister\Service\Object\DeletionGraph\ReadableDeletionAnalysis
 * @covers \OCA\OpenRegister\Exception\ReferentialIntegrityException
 * @uses   \OCA\OpenRegister\Dto\DeletionAnalysis
 * @uses   \OCA\OpenRegister\Db\ObjectEntity
 */
class ReadableDeletionAnalysisTest extends TestCase {

	private const ROOT = 'root-0000';

	/**
	 * The uuids the caller reads.
	 *
	 * @var array<int, string>
	 */
	private array $readable = [self::ROOT, 'mine-1', 'mine-2'];

	/**
	 * Every read, as [identifier, register, schema, includeDeleted, rbac, multitenancy].
	 *
	 * @var array<int, array<int, mixed>>
	 */
	private array $reads = [];

	/**
	 * Whether a read fails with something other than not-found.
	 */
	private bool $readFails = false;

	/**
	 * Identifiers the caller's read answers with ANOTHER readable object (whose slug, id or uri equals it).
	 *
	 * @var array<string, string>
	 */
	private array $collisions = [];

	private function redaction(): ReadableDeletionAnalysis {
		$mapper = $this->createMock(MagicMapper::class);
		$mapper->method('find')->willReturnCallback(
			function (string|int $identifier, $register = null, $schema = null, bool $includeDeleted = false, bool $_rbac = true, bool $_multitenancy = true): ObjectEntity {
				$this->reads[] = [$identifier, $register, $schema, $includeDeleted, $_rbac, $_multitenancy];
				if ($this->readFails === true) {
					throw new \RuntimeException('database gone');
				}

				if (isset($this->collisions[$identifier]) === true) {
					$other = new ObjectEntity();
					$other->setUuid($this->collisions[$identifier]);
					return $other;
				}

				if (in_array($identifier, $this->readable, true) === false) {
					throw new DoesNotExistException('not in scope');
				}

				$found = new ObjectEntity();
				$found->setUuid((string) $identifier);
				return $found;
			}
		);

		return new ReadableDeletionAnalysis(mapper: $mapper);
	}//end redaction()

	private static function blocker(string $uuid, string $schema, array $chain = []): array {
		return ['objectUuid' => $uuid, 'schema' => $schema, 'property' => 'case', 'action' => 'RESTRICT', 'chain' => $chain];
	}//end blocker()

	public function testABlockerTheCallerReadsIsKeptAsItIs(): void {
		$analysis = new DeletionAnalysis(deletable: false, blockers: [self::blocker(uuid: 'mine-1', schema: '7', chain: [self::ROOT.' → mine-1 (RESTRICT)'])]);

		$out = $this->redaction()->analysisFor(analysis: $analysis);

		$this->assertSame($analysis->blockers, $out->blockers);
		$this->assertFalse($out->deletable);
	}

	public function testBlockersTheCallerCannotReadAreCountedPerSchema(): void {
		$analysis = new DeletionAnalysis(deletable: false, blockers: [
			self::blocker(uuid: 'theirs-1', schema: '7'),
			self::blocker(uuid: 'mine-1', schema: '7'),
			self::blocker(uuid: 'theirs-2', schema: '7'),
			self::blocker(uuid: 'theirs-3', schema: '9'),
		]);

		$out = $this->redaction()->analysisFor(analysis: $analysis);

		$this->assertSame(
			[
				self::blocker(uuid: 'mine-1', schema: '7'),
				['objectUuid' => '', 'schema' => '7', 'action' => 'RESTRICT', 'reason' => 'not readable', 'count' => 2],
				['objectUuid' => '', 'schema' => '9', 'action' => 'RESTRICT', 'reason' => 'not readable', 'count' => 1],
			],
			$out->blockers
		);
		$this->assertFalse($out->deletable);
		$this->assertStringNotContainsString('theirs', (string) json_encode($out->toArray()));
	}

	public function testABlockedDeleteStillNamesEveryCountedBlocker(): void {
		$analysis = new DeletionAnalysis(deletable: false, blockers: [
			self::blocker(uuid: 'theirs-1', schema: '7'),
			self::blocker(uuid: 'theirs-2', schema: '7'),
			self::blocker(uuid: 'mine-1', schema: '7'),
		]);

		$exception = new ReferentialIntegrityException(analysis: $this->redaction()->analysisFor(analysis: $analysis));

		$this->assertSame('Cannot delete object: 3 dependent object(s) block deletion', $exception->getMessage());
	}

	public function testEveryTargetListIsRedactedAndAnEntryWithoutActionGetsNone(): void {
		$analysis = new DeletionAnalysis(
			deletable: true,
			effects: new DeletionEffects(cascadeTargets: [['objectUuid' => 'theirs-1', 'register' => '1', 'schema' => '7', 'property' => 'case', 'chain' => []]], nullifyTargets: [['objectUuid' => 'theirs-2', 'schema' => '8', 'property' => 'case', 'isArray' => false, 'sourceUuid' => self::ROOT]], defaultTargets: [['objectUuid' => 'theirs-3', 'schema' => '9', 'property' => 'case', 'defaultValue' => 'x']])
		);

		$out = $this->redaction()->analysisFor(analysis: $analysis);

		$this->assertSame([['objectUuid' => '', 'schema' => '7', 'reason' => 'not readable', 'count' => 1]], $out->cascadeTargets);
		$this->assertSame([['objectUuid' => '', 'schema' => '8', 'reason' => 'not readable', 'count' => 1]], $out->nullifyTargets);
		$this->assertSame([['objectUuid' => '', 'schema' => '9', 'reason' => 'not readable', 'count' => 1]], $out->defaultTargets);
		$this->assertTrue($out->deletable);
	}

	public function testAnUnreadableObjectIsBlankedFromTheChainsAndSourcesOfKeptEntries(): void {
		$analysis = new DeletionAnalysis(
			deletable: false,
			effects: new DeletionEffects(cascadeTargets: [['objectUuid' => 'theirs-1', 'schema' => '7', 'chain' => [self::ROOT.' → theirs-1 (CASCADE)']]], nullifyTargets: [['objectUuid' => 'mine-2', 'schema' => '8', 'sourceUuid' => 'theirs-1']]),
			blockers: [self::blocker(uuid: 'mine-1', schema: '9', chain: [self::ROOT.' → theirs-1 (CASCADE)', 'theirs-1 → mine-1 (RESTRICT)'])],
			chainPaths: [[self::ROOT.' → theirs-1 (CASCADE)'], 'not a chain']
		);

		$out = $this->redaction()->analysisFor(analysis: $analysis);

		$this->assertSame([self::ROOT.' → (not readable) (CASCADE)', '(not readable) → mine-1 (RESTRICT)'], $out->blockers[0]['chain']);
		$this->assertSame('(not readable)', $out->nullifyTargets[0]['sourceUuid']);
		$this->assertSame([[self::ROOT.' → (not readable) (CASCADE)'], 'not a chain'], $out->chainPaths);
		$this->assertStringNotContainsString('theirs', (string) json_encode($out->toArray()));
	}

	public function testAChainNamingAnUnreadableObjectListedLaterIsStillBlanked(): void {
		$analysis = new DeletionAnalysis(deletable: false, blockers: [
			self::blocker(uuid: 'mine-1', schema: '9', chain: ['theirs-9 → mine-1 (RESTRICT)']),
			self::blocker(uuid: 'theirs-9', schema: '7'),
		]);

		$out = $this->redaction()->analysisFor(analysis: $analysis);

		$this->assertSame(['(not readable) → mine-1 (RESTRICT)'], $out->blockers[0]['chain']);
	}

	public function testATokenThatOnlyContainsAnUnreadableUuidIsKept(): void {
		$analysis = new DeletionAnalysis(deletable: false, blockers: [
			self::blocker(uuid: 'theirs-1', schema: '7'),
			self::blocker(uuid: 'mine-1', schema: '9', chain: [self::ROOT.' → mine-1 (theirs-1x)', 'case=theirs-1 [7, 2]']),
		]);

		$out = $this->redaction()->analysisFor(analysis: $analysis);

		$this->assertSame([self::ROOT.' → mine-1 (theirs-1x)', 'case=theirs-1 [7, 2]'], $out->blockers[0]['chain']);
	}

	public function testAnUnverifiableBlockerWithoutUuidIsKept(): void {
		$unverifiable = ['objectUuid' => '', 'schema' => '7', 'property' => 'case', 'action' => 'RESTRICT', 'reason' => 'unverifiable', 'chain' => []];

		$out = $this->redaction()->analysisFor(analysis: new DeletionAnalysis(deletable: false, blockers: [$unverifiable]));

		$this->assertSame([$unverifiable], $out->blockers);
		$this->assertSame([], $this->reads);
	}

	public function testEachObjectIsReadOnceAsTheCaller(): void {
		$analysis = new DeletionAnalysis(
			deletable: false,
			effects: new DeletionEffects(cascadeTargets: [['objectUuid' => 'theirs-1', 'schema' => '7']]),
			blockers: [self::blocker(uuid: 'theirs-1', schema: '7'), self::blocker(uuid: 'mine-1', schema: '7')]
		);

		$this->redaction()->analysisFor(analysis: $analysis);

		$this->assertSame([['theirs-1', null, null, false, true, true], ['mine-1', null, null, false, true, true]], $this->reads);
	}

	public function testAReadableObjectWhoseSlugIsAnUnreadableUuidDoesNotRevealIt(): void {
		$this->collisions['theirs-1'] = 'mine-1';

		$out = $this->redaction()->analysisFor(analysis: new DeletionAnalysis(deletable: false, blockers: [self::blocker(uuid: 'theirs-1', schema: '7')]));

		$this->assertSame([['objectUuid' => '', 'schema' => '7', 'action' => 'RESTRICT', 'reason' => 'not readable', 'count' => 1]], $out->blockers);
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public static function otherUuids(): array {
		return ['a case variant' => ['THEIRS-9'], 'a longer uuid' => ['theirs-9x'], 'a shorter uuid' => ['theirs-']];
	}//end otherUuids()

	/**
	 * A read that answers with an object whose uuid only resembles the referrer's does not reveal it.
	 *
	 * @dataProvider otherUuids
	 */
	public function testAReadIsMatchedOnTheExactUuid(string $found): void {
		$this->collisions['theirs-9'] = $found;

		$out = $this->redaction()->analysisFor(analysis: new DeletionAnalysis(deletable: false, blockers: [self::blocker(uuid: 'theirs-9', schema: '7')]));

		$this->assertSame([['objectUuid' => '', 'schema' => '7', 'action' => 'RESTRICT', 'reason' => 'not readable', 'count' => 1]], $out->blockers);
	}

	public function testAReadThatFailsHidesTheObject(): void {
		$this->readFails = true;

		$out = $this->redaction()->analysisFor(analysis: new DeletionAnalysis(deletable: false, blockers: [self::blocker(uuid: 'mine-1', schema: '7')]));

		$this->assertSame([['objectUuid' => '', 'schema' => '7', 'action' => 'RESTRICT', 'reason' => 'not readable', 'count' => 1]], $out->blockers);
	}

	public function testPastTheLookupBudgetObjectsCountAsUnreadable(): void {
		$blockers = [];
		for ($i = 0; $i < 500; $i++) {
			$this->readable[] = 'r-'.$i;
			$blockers[] = self::blocker(uuid: 'r-'.$i, schema: '7');
		}

		$blockers[] = self::blocker(uuid: 'mine-1', schema: '7');

		$out = $this->redaction()->analysisFor(analysis: new DeletionAnalysis(deletable: false, blockers: $blockers));

		$this->assertCount(500, $this->reads);
		$this->assertCount(501, $out->blockers);
		$this->assertSame(['objectUuid' => '', 'schema' => '7', 'action' => 'RESTRICT', 'reason' => 'not readable', 'count' => 1], $out->blockers[500]);
	}
}//end class
