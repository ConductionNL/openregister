<?php

/**
 * Unit tests for FlowRunContext — the ambient attribution stack.
 *
 * The class is small; the failure it guards against is not. An unbalanced stack
 * attributes later writes to a run that already finished, and produces rows
 * that look entirely correct while doing it. So these tests are weighted
 * towards the LEAK direction — what the stack says AFTER something ends — and
 * not towards the happy path, which passes either way.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/flow-object-attribution/specs/flow-object-attribution/spec.md
 */

namespace Unit\Service\Flow;

use OCA\OpenRegister\Service\Flow\FlowRunContext;
use PHPUnit\Framework\TestCase;

class FlowRunContextTest extends TestCase {

	private FlowRunContext $context;

	protected function setUp(): void {
		$this->context = new FlowRunContext();
	}//end setUp()

	/**
	 * Outside any run there is nothing to attribute to.
	 */
	public function testEmptyStackAttributesNothing(): void {
		$this->assertNull($this->context->current());
		$this->assertSame(0, $this->context->depth());
	}//end testEmptyStackAttributesNothing()

	/**
	 * A pushed frame is what a write is filed under.
	 */
	public function testCurrentReturnsThePushedFrame(): void {
		$this->context->push(runUuid: 'run-a', nodeId: 'node-1', sequence: 7);

		$this->assertSame(
			['run' => 'run-a', 'node' => 'node-1', 'step' => 7],
			$this->context->current()
		);
	}//end testCurrentReturnsThePushedFrame()

	/**
	 * Popping restores the ENCLOSING frame, not emptiness.
	 *
	 * This is the sub-flow case. If pop cleared instead of restoring, a parent
	 * run's remaining steps would silently stop being attributed the moment it
	 * called a sub-flow — and nothing about the resulting rows would look wrong.
	 */
	public function testNestedPopRestoresTheParentFrame(): void {
		$this->context->push(runUuid: 'parent', nodeId: 'call-subflow', sequence: 2);
		$this->context->push(runUuid: 'child', nodeId: 'child-node', sequence: 0);

		$this->assertSame('child', $this->context->current()['run']);

		$this->context->pop();

		$this->assertSame(
			'parent',
			$this->context->current()['run'],
			'A sub-flow returning must hand the parent run back its own attribution.'
		);
	}//end testNestedPopRestoresTheParentFrame()

	/**
	 * A run that is not attributable does not INHERIT the enclosing one.
	 *
	 * The wrong behaviour here files a child's writes under its parent: still a
	 * plausible-looking row, attributed to a run that never performed it.
	 */
	public function testUnattributableFrameDoesNotInheritTheParent(): void {
		$this->context->push(runUuid: 'parent', nodeId: 'outer', sequence: 1);
		$this->context->push(runUuid: null, nodeId: 'inner', sequence: 0);

		$this->assertNull(
			$this->context->current(),
			'An inner hop with no run must attribute to nothing, not to the run outside it.'
		);

		$this->context->pop();

		$this->assertSame('parent', $this->context->current()['run']);
	}//end testUnattributableFrameDoesNotInheritTheParent()

	/**
	 * An empty run uuid is the same as none. A run uuid is never blank, so a
	 * blank one is a bug upstream and must not become an attribution of "".
	 */
	public function testBlankRunUuidIsNotAnAttribution(): void {
		$this->context->push(runUuid: '   ', nodeId: 'node', sequence: 0);

		$this->assertNull($this->context->current());
		$this->assertSame(1, $this->context->depth(), 'It still occupies a frame, so pop stays paired.');
	}//end testBlankRunUuidIsNotAnAttribution()

	/**
	 * Popping more than was pushed must not throw.
	 *
	 * pop() is called from a `finally`. A `finally` that throws REPLACES the
	 * exception the step actually failed with, turning a diagnosable node
	 * failure into a bookkeeping error about the context.
	 */
	public function testPoppingAnEmptyStackIsHarmless(): void {
		$this->context->pop();
		$this->context->pop();

		$this->assertNull($this->context->current());
		$this->assertSame(0, $this->context->depth());
	}//end testPoppingAnEmptyStackIsHarmless()

	/**
	 * Two sequential runs do not bleed into one another.
	 *
	 * FlowRunWorker advances several runs per process, so a leak here crosses
	 * runs rather than merely steps.
	 */
	public function testSequentialRunsDoNotBleed(): void {
		$this->context->push(runUuid: 'run-1', nodeId: 'n', sequence: 0);
		$this->context->pop();

		$this->assertNull($this->context->current(), 'A finished run must leave nothing behind.');

		$this->context->push(runUuid: 'run-2', nodeId: 'n', sequence: 0);

		$this->assertSame('run-2', $this->context->current()['run']);
	}//end testSequentialRunsDoNotBleed()
}//end class
