<?php

declare(strict_types=1);

/**
 * ToolReachResolver unit tests (agent-capability-reach).
 *
 * 🔴 The whole class fails closed to `external`, which makes it trivially easy
 * to write a suite that passes while NOTHING is wired: every assertion would
 * read `external` and every test would be green. So each resolution branch is
 * tested separately AND asserted to produce a DIFFERENT verdict from the
 * others — that is the positive control. A single fail-closed test cannot tell
 * "all four branches work" from "everything falls through".
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Capability
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

namespace OCA\OpenRegister\Tests\Unit\Service\Capability;

use OCA\OpenRegister\Service\Capability\ToolReachResolver;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Service\Capability\ToolReachResolver
 */
class ToolReachResolverTest extends TestCase {

	/**
	 * A declared, valid reach wins over any inference.
	 */
	public function testADeclaredReachWins(): void {
		// The id would infer `instance`; the declaration must beat it.
		$this->assertSame(
			ToolReachResolver::REACH_SELF,
			ToolReachResolver::resolve('hermiq.memory.create', ['reach' => 'self'])
		);

	}//end testADeclaredReachWins()

	/**
	 * A derived read verb infers `user` — no effect, no disclosure.
	 */
	public function testADerivedReadVerbInfersUser(): void {
		$this->assertSame(ToolReachResolver::REACH_USER, ToolReachResolver::resolve('openregister.zaak.search'));
		$this->assertSame(ToolReachResolver::REACH_USER, ToolReachResolver::resolve('openregister.zaak.get'));

	}//end testADerivedReadVerbInfersUser()

	/**
	 * A derived write verb infers `instance` — other users can see the result.
	 */
	public function testADerivedWriteVerbInfersInstance(): void {
		foreach (['create', 'update', 'delete'] as $verb) {
			$this->assertSame(
				ToolReachResolver::REACH_INSTANCE,
				ToolReachResolver::resolve('openregister.zaak.' . $verb),
				sprintf('The %s verb must infer instance.', $verb)
			);
		}

	}//end testADerivedWriteVerbInfersInstance()

	/**
	 * 🔴 Everything unknown fails closed to `external` — never `self`.
	 */
	public function testUnknownShapesFailClosedToExternal(): void {
		$cases = [
			'no descriptor, curated 2-segment id' => ['hermiq.sendMail', null],
			'verb outside the closed set' => ['openregister.zaak.frobnicate', null],
			'reach value outside the vocabulary' => ['hermiq.sendMail', ['reach' => 'galaxy']],
			'reach declared as a non-string' => ['hermiq.sendMail', ['reach' => 42]],
			'descriptor present but no reach' => ['hermiq.sendMail', ['scope' => 'read']],
			'empty id' => ['', null],
		];

		foreach ($cases as $label => [$id, $descriptor]) {
			$this->assertSame(
				ToolReachResolver::REACH_EXTERNAL,
				ToolReachResolver::resolve($id, $descriptor),
				sprintf('%s must fail closed to external.', $label)
			);
		}

	}//end testUnknownShapesFailClosedToExternal()

	/**
	 * 🔴 THE POSITIVE CONTROL.
	 *
	 * The four branches must yield four DIFFERENT verdicts. If someone breaks
	 * resolution so everything falls through to the fail-closed default, every
	 * other test in this file still passes — and this one does not.
	 */
	public function testTheFourBranchesProduceFourDifferentVerdicts(): void {
		$verdicts = [
			'declared' => ToolReachResolver::resolve('hermiq.rememberMemory', ['reach' => 'self']),
			'inferred read' => ToolReachResolver::resolve('openregister.zaak.search'),
			'inferred write' => ToolReachResolver::resolve('openregister.zaak.create'),
			'fail closed' => ToolReachResolver::resolve('hermiq.sendMail'),
		];

		$this->assertSame(
			['self', 'user', 'instance', 'external'],
			array_values($verdicts),
			'Each branch must resolve differently — identical verdicts mean the branches are not wired.'
		);
		$this->assertCount(4, array_unique($verdicts), 'All four verdicts must be distinct.');

	}//end testTheFourBranchesProduceFourDifferentVerdicts()

	/**
	 * Reach is NEVER derived from scope — that inference is the conflation this
	 * class exists to undo.
	 */
	public function testReachIsNeverDerivedFromScope(): void {
		// A read scope on a curated id must not soften the fail-closed default:
		// this is exactly the `webFetch` shape.
		$this->assertSame(
			ToolReachResolver::REACH_EXTERNAL,
			ToolReachResolver::resolve('hermiq.webFetch', ['scope' => 'read', 'readOnlyHint' => true])
		);

	}//end testReachIsNeverDerivedFromScope()

	/**
	 * The order is total and the comparison respects it.
	 */
	public function testTheOrderIsTotalAndComparable(): void {
		$this->assertSame(['self', 'user', 'instance', 'external'], ToolReachResolver::ORDER);

		$this->assertTrue(ToolReachResolver::atLeast('external', 'instance'));
		$this->assertTrue(ToolReachResolver::atLeast('instance', 'instance'));
		$this->assertFalse(ToolReachResolver::atLeast('user', 'instance'));
		$this->assertFalse(ToolReachResolver::atLeast('self', 'user'));

		// Garbage is never "at least" anything.
		$this->assertFalse(ToolReachResolver::atLeast('galaxy', 'self'));
		$this->assertFalse(ToolReachResolver::atLeast('external', 'galaxy'));

	}//end testTheOrderIsTotalAndComparable()

	/**
	 * max() is what stops a delegation laundering reach, and an unrecognised
	 * operand must not win by losing the comparison.
	 */
	public function testMaxTakesTheFartherReachAndFailsClosedOnGarbage(): void {
		$this->assertSame('external', ToolReachResolver::max('instance', 'external'));
		$this->assertSame('external', ToolReachResolver::max('external', 'instance'));
		$this->assertSame('instance', ToolReachResolver::max('self', 'instance'));
		$this->assertSame('self', ToolReachResolver::max('self', 'self'));

		$this->assertSame('external', ToolReachResolver::max('galaxy', 'self'));

	}//end testMaxTakesTheFartherReachAndFailsClosedOnGarbage()
}//end class
