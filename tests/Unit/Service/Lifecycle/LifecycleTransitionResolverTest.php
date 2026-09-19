<?php

/**
 * Which declared transition a lifecycle change is, and when a named action wins.
 *
 * Two transitions may share a from/to pair. Before the resolver, every listener
 * took the FIRST such transition, so a named call to the second one was judged
 * by the first one's gates and ran the first one's actions. These tests pin the
 * rule that replaced it: a declared action wins only when it genuinely moves
 * old to new; otherwise first-match by value, as a direct edit always did.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Lifecycle
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/lifecycle-declarative-conditions/specs/object-lifecycle/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Lifecycle;

use OCA\OpenRegister\Service\Lifecycle\LifecycleActionContext;
use OCA\OpenRegister\Service\Lifecycle\LifecycleTransitionResolver;
use PHPUnit\Framework\TestCase;

/**
 * Resolution rule and context stacking.
 */
class LifecycleTransitionResolverTest extends TestCase {

	private const UUID = '00000000-0000-0000-0000-000000000001';

	/**
	 * Two transitions with the SAME from/to pair, plus one that goes elsewhere.
	 * `startVerifying` is declared first, so value matching always picks it.
	 */
	private const TWINS = [
		'startVerifying' => ['from' => ['received'], 'to' => 'verifying'],
		'assign' => ['from' => ['received'], 'to' => 'verifying', 'condition' => ['!!' => ['var' => 'object.handler']]],
		'close' => ['from' => ['received', 'verifying'], 'to' => 'closed'],
	];

	private LifecycleActionContext $context;

	private LifecycleTransitionResolver $resolver;

	/**
	 * @return void
	 */
	protected function setUp(): void {
		$this->context = new LifecycleActionContext();
		$this->resolver = new LifecycleTransitionResolver(context: $this->context);
	}//end setUp()

	/**
	 * @return void
	 */
	public function testWithoutADeclarationTheFirstMatchWins(): void {
		$matched = $this->resolver->resolve(self::TWINS, self::UUID, 'received', 'verifying');

		$this->assertSame('startVerifying', $matched[0]);
	}//end testWithoutADeclarationTheFirstMatchWins()

	/**
	 * @return void
	 */
	public function testADeclaredActionWinsOverItsEarlierTwin(): void {
		// The defect this class exists to close: a named call to `assign` used
		// to be judged as `startVerifying`, so assign's condition never ran.
		$this->context->declare(uuid: self::UUID, action: 'assign');

		$matched = $this->resolver->resolve(self::TWINS, self::UUID, 'received', 'verifying');

		$this->assertSame('assign', $matched[0]);
		$this->assertArrayHasKey(key: 'condition', array: $matched[1]);
	}//end testADeclaredActionWinsOverItsEarlierTwin()

	/**
	 * @return void
	 */
	public function testADeclaredActionThatDoesNotMatchTheEditIsIgnored(): void {
		// 🔴 A declaration names which of several VALID transitions was meant.
		// It must never make a move legal on its own: `close` goes to `closed`,
		// so for a received→verifying edit it is ignored and value matching runs.
		$this->context->declare(uuid: self::UUID, action: 'close');

		$matched = $this->resolver->resolve(self::TWINS, self::UUID, 'received', 'verifying');

		$this->assertSame('startVerifying', $matched[0]);
	}//end testADeclaredActionThatDoesNotMatchTheEditIsIgnored()

	/**
	 * @return void
	 */
	public function testADeclaredActionNeverLegalisesAnUndeclaredMove(): void {
		// No transition moves verifying→received. Declaring one must not
		// conjure a match out of nothing.
		$this->context->declare(uuid: self::UUID, action: 'assign');

		$this->assertNull($this->resolver->resolve(self::TWINS, self::UUID, 'verifying', 'received'));
	}//end testADeclaredActionNeverLegalisesAnUndeclaredMove()

	/**
	 * @return void
	 */
	public function testAnUnknownDeclaredActionFallsBackToValueMatching(): void {
		$this->context->declare(uuid: self::UUID, action: 'nosuchaction');

		$matched = $this->resolver->resolve(self::TWINS, self::UUID, 'received', 'verifying');

		$this->assertSame('startVerifying', $matched[0]);
	}//end testAnUnknownDeclaredActionFallsBackToValueMatching()

	/**
	 * @return void
	 */
	public function testADeclarationOnAnotherObjectDoesNotLeak(): void {
		$this->context->declare(uuid: '00000000-0000-0000-0000-000000000002', action: 'assign');

		$matched = $this->resolver->resolve(self::TWINS, self::UUID, 'received', 'verifying');

		$this->assertSame('startVerifying', $matched[0]);
	}//end testADeclarationOnAnotherObjectDoesNotLeak()

	/**
	 * @return void
	 */
	public function testAStringFromIsReadAsAOneElementList(): void {
		$transitions = ['open' => ['from' => 'draft', 'to' => 'open']];

		$this->assertSame('open', $this->resolver->resolve($transitions, self::UUID, 'draft', 'open')[0]);
	}//end testAStringFromIsReadAsAOneElementList()

	/**
	 * @return void
	 */
	public function testANestedReleaseRestoresTheOuterDeclaration(): void {
		// A transition whose actions save the same object again: the inner
		// release must hand the object back to the outer action, not clear it.
		$this->context->declare(uuid: self::UUID, action: 'assign');
		$this->context->declare(uuid: self::UUID, action: 'startVerifying');
		$this->context->release(uuid: self::UUID);

		$this->assertSame('assign', $this->context->declaredFor(uuid: self::UUID));

		$this->context->release(uuid: self::UUID);
		$this->assertNull($this->context->declaredFor(uuid: self::UUID));
	}//end testANestedReleaseRestoresTheOuterDeclaration()

	/**
	 * @return void
	 */
	public function testReleasingAnUndeclaredObjectIsHarmless(): void {
		$this->context->release(uuid: self::UUID);

		$this->assertNull($this->context->declaredFor(uuid: self::UUID));
	}//end testReleasingAnUndeclaredObjectIsHarmless()
}//end class
