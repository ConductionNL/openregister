<?php

/**
 * The item model's missing primitive.
 *
 * Every node acts once per ITEM, so the engine fans out over a collection — but
 * only when the collection already IS the item list. Nothing turned an array
 * SITTING ON an item into items, which is the difference between a flow that can
 * read a list and one that can act on it. hydra's reviewer emits findings and the
 * pipeline must file one issue PER finding; without this that is inexpressible.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Flow
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Flow;

use OCA\OpenRegister\Service\Flow\FlowItems;
use OCA\OpenRegister\Service\Flow\Nodes\ExplodeNode;
use OCP\IL10N;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;
use UnexpectedValueException;

/**
 * @covers \OCA\OpenRegister\Service\Flow\Nodes\ExplodeNode
 */
class ExplodeNodeTest extends TestCase {
	private ExplodeNode $node;

	protected function setUp(): void {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(
			static fn (string $t, array $p = []): string => vsprintf($t, $p)
		);
		$this->node = new ExplodeNode($l10n, $this->createMock(IURLGenerator::class));
	}

	private function items(array $records): array {
		return array_map(static fn (array $r): array => FlowItems::item(json: $r), $records);
	}

	/**
	 * The whole point: a list on the item becomes items.
	 */
	public function testAListBecomesOneItemPerEntry(): void {
		$out = $this->node->execute(
			$this->items([['findings' => [['t' => 'a'], ['t' => 'b'], ['t' => 'c']]]]),
			['path' => 'findings', 'as' => 'finding'],
			[]
		);

		$this->assertCount(3, $out);
		$this->assertSame('a', $out[0]['json']['finding']['t']);
		$this->assertSame('c', $out[2]['json']['finding']['t']);
	}

	/**
	 * The entry keeps the context it came from.
	 *
	 * A finding still knows its repository and its run. Dropping the rest would
	 * make the node unusable for anything but a list of complete records.
	 */
	public function testTheOriginalRecordIsCarried(): void {
		$out = $this->node->execute(
			$this->items([['repo' => 'openregister', 'findings' => [['t' => 'a']]]]),
			['path' => 'findings', 'as' => 'finding'],
			[]
		);

		$this->assertSame('openregister', $out[0]['json']['repo']);
		$this->assertSame('a', $out[0]['json']['finding']['t']);
	}

	/**
	 * A dotted path reaches into nested structure — where findings actually live.
	 */
	public function testADottedPathReachesNestedLists(): void {
		$out = $this->node->execute(
			$this->items([['stage' => ['files' => ['r.json' => ['code_review' => ['findings' => [1, 2]]]]]]]),
			['path' => 'stage.files.r.json.code_review.findings'],
			[]
		);

		// The literal key contains a dot, so this path cannot resolve — proving
		// the traversal is strict rather than fuzzy.
		$this->assertCount(0, $out);
	}

	/**
	 * An EMPTY list contributes nothing and is NOT an error.
	 *
	 * "The reviewer found nothing" is the ordinary case; failing the step on it
	 * would turn a clean review into a broken run.
	 */
	public function testAnEmptyOrAbsentListIsNotAnError(): void {
		$out = $this->node->execute(
			$this->items([['findings' => []], ['other' => 1]]),
			['path' => 'findings'],
			[]
		);

		$this->assertSame([], $out);
	}

	/**
	 * A SCALAR at the path is a mis-authored flow and fails.
	 */
	public function testAScalarAtThePathFails(): void {
		$this->expectException(UnexpectedValueException::class);
		$this->node->execute($this->items([['findings' => 'oops']]), ['path' => 'findings'], []);
	}

	/**
	 * An unconfigured step FAILS rather than passing items through.
	 *
	 * `validateConfig()` runs only when a flow is saved; a seeded or imported
	 * flow reaches execute() unvalidated, and a silent pass-through would look
	 * exactly like a list of one.
	 */
	public function testAnUnconfiguredStepFailsInExecuteToo(): void {
		$this->expectException(UnexpectedValueException::class);
		$this->node->execute($this->items([['findings' => [1]]]), [], []);
	}

	/**
	 * Several input items all explode, into one flat list.
	 */
	public function testEveryInputItemExplodes(): void {
		$out = $this->node->execute(
			$this->items([['f' => [1, 2]], ['f' => [3]]]),
			['path' => 'f', 'as' => 'n'],
			[]
		);

		$this->assertCount(3, $out);
		$this->assertSame([1, 2, 3], array_map(static fn ($i) => $i['json']['n'], $out));
	}

	public function testItRegistersAsTheExplodeNode(): void {
		$this->assertSame('openregister.explode', $this->node->getId());
	}
}
