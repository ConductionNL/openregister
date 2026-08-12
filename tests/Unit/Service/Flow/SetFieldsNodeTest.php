<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace Unit\Service\Flow;

use OCA\OpenRegister\Service\Flow\FlowItems;
use OCA\OpenRegister\Service\Flow\Nodes\SetFieldsNode;
use OCP\IL10N;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;
use UnexpectedValueException;

class SetFieldsNodeTest extends TestCase {
	private SetFieldsNode $node;

	protected function setUp(): void {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);

		$this->node = new SetFieldsNode($l10n, $this->createMock(IURLGenerator::class));
	}

	/** @param array<int, array<string, mixed>> $records */
	private function items(array $records): array {
		return array_map(static fn (array $r): array => FlowItems::item(json: $r), $records);
	}

	/**
	 * The property the item model exists for: one configuration, applied to
	 * every item, with no loop drawn by the author.
	 */
	public function testOneConfigurationIsAppliedToEveryItem(): void {
		$out = $this->node->execute(
			$this->items([['name' => 'a'], ['name' => 'b'], ['name' => 'c']]),
			['set' => ['status' => 'reviewed']],
			[]
		);

		$this->assertCount(3, $out);
		$this->assertSame(['reviewed', 'reviewed', 'reviewed'], array_column(array_column($out, 'json'), 'status'));
		$this->assertSame(['a', 'b', 'c'], array_column(array_column($out, 'json'), 'name'));
	}

	public function testEveryOutputItemPointsBackAtItsOwnInput(): void {
		$out = $this->node->execute($this->items([['n' => 1], ['n' => 2]]), ['set' => ['x' => 1]], []);

		$this->assertSame(['item' => 0], $out[0]['pairedItem']);
		$this->assertSame(['item' => 1], $out[1]['pairedItem']);
	}

	public function testFieldsAreRemovedRenamedAndSet(): void {
		$out = $this->node->execute(
			$this->items([['drop' => 1, 'old' => 'v', 'keep' => 'k']]),
			['remove' => ['drop'], 'rename' => ['old' => 'new'], 'set' => ['added' => true]],
			[]
		);

		$this->assertSame(['keep' => 'k', 'new' => 'v', 'added' => true], $out[0]['json']);
	}

	/**
	 * Remove runs before rename, so a rename may legitimately reuse a name the
	 * same step removed.
	 */
	public function testARenameCanReuseAJustRemovedName(): void {
		$out = $this->node->execute(
			$this->items([['a' => 'old-a', 'b' => 'b-value']]),
			['remove' => ['a'], 'rename' => ['b' => 'a']],
			[]
		);

		$this->assertSame(['a' => 'b-value'], $out[0]['json']);
	}

	public function testKeepOnlySetDropsEverythingElse(): void {
		$out = $this->node->execute(
			$this->items([['noise' => 1, 'more' => 2]]),
			['set' => ['only' => 'this'], 'keepOnlySet' => true],
			[]
		);

		$this->assertSame(['only' => 'this'], $out[0]['json']);
	}

	public function testBinaryAttachmentsSurviveTheStep(): void {
		$item = FlowItems::item(json: ['a' => 1], binary: ['file' => ['mimeType' => 'text/plain']]);

		$out = $this->node->execute([$item], ['set' => ['b' => 2]], []);

		$this->assertSame(['file' => ['mimeType' => 'text/plain']], $out[0]['binary']);
	}

	public function testNoItemsInMeansNoItemsOut(): void {
		$this->assertSame([], $this->node->execute([], ['set' => ['a' => 1]], []));
	}

	/**
	 * A step that silently does nothing is worse than one that refuses to save.
	 */
	public function testAConfigurationThatWouldDoNothingIsRefused(): void {
		$this->expectException(UnexpectedValueException::class);
		$this->node->validateConfig([]);
	}

	public function testAUsefulConfigurationValidates(): void {
		$this->node->validateConfig(['set' => ['a' => 1]]);
		$this->addToAssertionCount(1);
	}

	/**
	 * `set` values are rendered against the item, not stored verbatim.
	 *
	 * Before this, the one node whose entire job is setting fields was the only
	 * one that could not refer to the item it was setting them on: `{{retries}}`
	 * was stored as the literal seven characters. It is also the reference
	 * implementation other node authors copy, so the gap propagated.
	 *
	 * @return void
	 */
	public function testSetValuesAreRenderedFromTheItem(): void {
		$out = $this->node->execute(
			[FlowItems::item(json: ['repo' => 'ConductionNL/hydra', 'retries' => 2])],
			['set' => ['label' => 'retry {{retries}} on {{repo}}', 'count' => '{{retries}}']],
			[]
		);

		$this->assertSame('retry 2 on ConductionNL/hydra', $out[0]['json']['label']);

		// A whole placeholder keeps its type — a counter must stay a number,
		// or every downstream `<` comparison becomes a string comparison.
		$this->assertSame(2, $out[0]['json']['count']);
	}

	/**
	 * A literal with no placeholder is untouched.
	 *
	 * @return void
	 */
	public function testALiteralValueIsUnchanged(): void {
		$out = $this->node->execute(
			[FlowItems::item(json: [])],
			['set' => ['stage' => 'builder', 'max' => 3]],
			[]
		);

		$this->assertSame('builder', $out[0]['json']['stage']);
		$this->assertSame(3, $out[0]['json']['max']);
	}

	/**
	 * `compute` DERIVES a value, which a template cannot do.
	 *
	 * `{{retries}}` can copy a counter; nothing could add one to it, because a
	 * template substitutes and does not calculate. That is what stopped hydra's
	 * retry/escalation flow — read the count, increment, store: the read and the
	 * store were expressible and the `+ 1` was not, so the counter sat at 0 and
	 * a run "retried" forever.
	 *
	 * @return void
	 */
	public function testComputeDerivesAValue(): void {
		$out = $this->node->execute(
			[FlowItems::item(json: ['retries' => 2])],
			['compute' => ['next' => ['+' => [['var' => 'json.retries'], 1]]]],
			[]
		);

		$this->assertSame(3, $out[0]['json']['next']);
	}

	/**
	 * `compute` runs AFTER `set`, so it can build on a value just substituted.
	 *
	 * @return void
	 */
	public function testComputeSeesWhatSetJustWrote(): void {
		$out = $this->node->execute(
			[FlowItems::item(json: ['retries' => 1])],
			[
				'set' => ['base' => '{{retries}}'],
				'compute' => ['next' => ['+' => [['var' => 'json.base'], 10]]],
			],
			[]
		);

		$this->assertSame(11, $out[0]['json']['next']);
	}

	/**
	 * A malformed expression is refused when the flow is SAVED.
	 *
	 * `FlowExpression` swallows a broken rule and returns null, which is
	 * indistinguishable from a field that legitimately resolved to nothing. So
	 * the only place it can be caught honestly is at save time.
	 *
	 * @return void
	 */
	public function testAMalformedExpressionIsRefusedAtSaveTime(): void {
		$this->expectException(\UnexpectedValueException::class);

		$this->node->validateConfig(['compute' => ['next' => ['nosuchoperator' => [1, 2]]]]);
	}

	/**
	 * A step that only computes is a legitimate step.
	 *
	 * @return void
	 */
	public function testAComputeOnlyStepValidates(): void {
		$this->node->validateConfig(['compute' => ['next' => ['+' => [1, 1]]]]);

		$this->addToAssertionCount(1);
	}

	/**
	 * A dotted key WRITES a nested structure rather than a literal key.
	 *
	 * Reads have always gone through `{{dotted.path}}`; writes did not, so
	 * `"entry.owner"` produced a top-level key literally CALLED `entry.owner`
	 * beside any real `entry`. Nothing failed — the flow ran, the item came out,
	 * and the object the author meant to build was simply never there. That is
	 * indistinguishable from success until something downstream reads the shape
	 * and finds it empty.
	 */
	public function testADottedKeyWritesANestedStructure(): void {
		$out = $this->node->execute(
			$this->items([['who' => 'ruben', 'code' => 0]]),
			['set' => ['entry.owner' => '{{who}}', 'entry.exit_code' => '{{code}}']],
			[]
		);

		$this->assertSame('ruben', $out[0]['json']['entry']['owner']);
		// The whole-placeholder type rule still holds through a nested write.
		$this->assertSame(0, $out[0]['json']['entry']['exit_code']);
		$this->assertArrayNotHasKey('entry.owner', $out[0]['json']);
	}

	/**
	 * Several dotted keys build ONE object rather than clobbering each other.
	 */
	public function testSiblingDottedKeysShareTheirContainer(): void {
		$out = $this->node->execute(
			$this->items([[]]),
			['set' => ['e.a' => '1', 'e.b' => '2', 'e.c' => '3']],
			[]
		);

		$this->assertSame(['a' => '1', 'b' => '2', 'c' => '3'], $out[0]['json']['e']);
	}

	/**
	 * A dotted write MERGES into an existing container instead of replacing it.
	 *
	 * A record composer appends a stage to a cycle that already has fields; a
	 * write that replaced the container would silently drop them.
	 */
	public function testADottedWriteMergesIntoAnExistingObject(): void {
		$out = $this->node->execute(
			$this->items([['entry' => ['kept' => 'yes']]]),
			['set' => ['entry.added' => 'also']],
			[]
		);

		$this->assertSame('yes', $out[0]['json']['entry']['kept']);
		$this->assertSame('also', $out[0]['json']['entry']['added']);
	}

	/**
	 * Deep paths create every level they need.
	 */
	public function testADeepPathCreatesEveryLevel(): void {
		$out = $this->node->execute($this->items([[]]), ['set' => ['a.b.c.d' => 'deep']], []);

		$this->assertSame('deep', $out[0]['json']['a']['b']['c']['d']);
	}

	/**
	 * A scalar in the path is REPLACED by a container, not merged into.
	 *
	 * Merging into a scalar has no meaning, and silently skipping would
	 * reintroduce the invisible no-op this fixes.
	 */
	public function testAScalarInThePathBecomesAContainer(): void {
		$out = $this->node->execute(
			$this->items([['entry' => 'i am a string']]),
			['set' => ['entry.owner' => 'ruben']],
			[]
		);

		$this->assertSame(['owner' => 'ruben'], $out[0]['json']['entry']);
	}

	/**
	 * An undotted key is untouched by any of this.
	 */
	public function testAnUndottedKeyStillWritesFlat(): void {
		$out = $this->node->execute($this->items([[]]), ['set' => ['plain' => 'value']], []);

		$this->assertSame('value', $out[0]['json']['plain']);
	}

}
