<?php

/**
 * Characterisation of ExplodeNode's per-item shaping.
 *
 * Pins `keepRecord`, the default `as` key, provenance and the order a
 * non-list refusal fires in, exactly as they behave today.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Flow;

use OCA\OpenRegister\Service\Flow\FlowItems;
use OCA\OpenRegister\Service\Flow\FlowNodeCall;
use OCA\OpenRegister\Service\Flow\Nodes\ExplodeNode;
use OCP\IL10N;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;
use UnexpectedValueException;

/**
 * @covers \OCA\OpenRegister\Service\Flow\Nodes\ExplodeNode
 */
final class ExplodeNodeCharacterisationTest extends TestCase {

	/**
	 * The node under test.
	 *
	 * @var ExplodeNode
	 */
	private ExplodeNode $node;

	/**
	 * Build the node.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(
			static fn (string $t, array $p = []): string => vsprintf($t, $p)
		);
		$this->node = new ExplodeNode($l10n, $this->createMock(IURLGenerator::class));
	}//end setUp()

	/**
	 * `keepRecord: false` drops the source record; only a literal false does.
	 *
	 * @return void
	 */
	public function testOnlyALiteralFalseDropsTheRecord(): void {
		$items = [FlowItems::item(json: ['repo' => 'r', 'list' => ['x']])];

		$dropped = $this->node->execute(new FlowNodeCall(items: $items, config: ['path' => 'list', 'keepRecord' => false], context: []));
		$this->assertSame(['item' => 'x'], $dropped[0][FlowItems::JSON]);

		$kept = $this->node->execute(new FlowNodeCall(items: $items, config: ['path' => 'list', 'keepRecord' => 0], context: []));
		$this->assertSame(['repo' => 'r', 'list' => ['x'], 'item' => 'x'], $kept[0][FlowItems::JSON]);

		$nullKept = $this->node->execute(new FlowNodeCall(items: $items, config: ['path' => 'list', 'keepRecord' => null], context: []));
		$this->assertSame('r', $nullKept[0][FlowItems::JSON]['repo']);
	}//end testOnlyALiteralFalseDropsTheRecord()

	/**
	 * The entry lands under `item` by default and replaces a same-named field.
	 *
	 * @return void
	 */
	public function testTheEntryLandsUnderItemByDefaultAndWins(): void {
		$items = [FlowItems::item(json: ['item' => 'old', 'list' => ['new']])];

		$out = $this->node->execute(new FlowNodeCall(items: $items, config: ['path' => ' list '], context: []));

		$this->assertSame('new', $out[0][FlowItems::JSON]['item']);
	}//end testTheEntryLandsUnderItemByDefaultAndWins()

	/**
	 * Every entry records the input item it came from, by its index, and
	 * entries keep list order across items.
	 *
	 * @return void
	 */
	public function testProvenanceAndOrderAcrossItems(): void {
		$items = [
			FlowItems::item(json: ['list' => ['a', 'b']]),
			FlowItems::item(json: []),
			FlowItems::item(json: ['list' => ['c']]),
		];

		$out = $this->node->execute(new FlowNodeCall(items: $items, config: ['path' => 'list', 'as' => 'e'], context: []));

		$this->assertSame(
			[['a', ['item' => 0]], ['b', ['item' => 0]], ['c', ['item' => 2]]],
			array_map(
				static fn (array $i): array => [$i[FlowItems::JSON]['e'], $i[FlowItems::PAIRED_ITEM] ?? null],
				$out
			)
		);
		$this->assertSame([0, 1, 2], array_keys($out));
	}//end testProvenanceAndOrderAcrossItems()

	/**
	 * A later item holding a scalar fails the whole step, even after earlier
	 * items exploded.
	 *
	 * @return void
	 */
	public function testALaterScalarFailsTheWholeStep(): void {
		$items = [
			FlowItems::item(json: ['list' => ['a']]),
			FlowItems::item(json: ['list' => 'scalar']),
		];

		$this->expectException(UnexpectedValueException::class);
		$this->expectExceptionMessage('The explode path "list" is not a list.');

		$this->node->execute(new FlowNodeCall(items: $items, config: ['path' => 'list'], context: []));
	}//end testALaterScalarFailsTheWholeStep()
}//end class
