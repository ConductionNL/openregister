<?php

/**
 * Unit tests for the forced-anonymous evaluation scope.
 *
 * @category  Test
 * @package   OCA\OpenRegister\Tests\Unit\Service
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git-id>
 * @link      https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service;

use OCA\OpenRegister\Service\AnonymousEvaluationContext;
use OCA\OpenRegister\Service\SystemOperationContext;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use RuntimeException;

/**
 * The scope is a static marker: active inside run(), released after, and it
 * outranks the system-operation scope while it is active (WOO-578).
 */
class AnonymousEvaluationContextTest extends TestCase {


	public function testInactiveByDefault(): void {
		$this->assertFalse(AnonymousEvaluationContext::isActive());
	}//end testInactiveByDefault()


	public function testActiveInsideRunAndReleasedAfter(): void {
		$observed = null;
		$result = AnonymousEvaluationContext::run(
			function () use (&$observed) {
				$observed = AnonymousEvaluationContext::isActive();
				return 'done';
			}
		);
		$this->assertTrue($observed);
		$this->assertSame('done', $result);
		$this->assertFalse(AnonymousEvaluationContext::isActive());
	}//end testActiveInsideRunAndReleasedAfter()


	public function testScopeReleasedOnException(): void {
		try {
			AnonymousEvaluationContext::run(
				function (): void {
					throw new RuntimeException('boom');
				}
			);
			$this->fail('Expected RuntimeException');
		} catch (RuntimeException $e) {
			$this->assertSame('boom', $e->getMessage());
		}
		$this->assertFalse(AnonymousEvaluationContext::isActive());
	}//end testScopeReleasedOnException()


	public function testNestedScopesCompose(): void {
		$afterInner = null;
		AnonymousEvaluationContext::run(
			function () use (&$afterInner): void {
				AnonymousEvaluationContext::run(static fn (): bool => true);
				$afterInner = AnonymousEvaluationContext::isActive();
			}
		);
		$this->assertTrue($afterInner, 'the outer scope must survive the inner one');
		$this->assertFalse(AnonymousEvaluationContext::isActive());
	}//end testNestedScopesCompose()


	/**
	 * Narrowing wins over elevating: a system operation that asks to be judged
	 * as an anonymous caller is not the system for the duration of that ask,
	 * and becomes the system again the moment the ask ends.
	 */
	public function testTheSystemScopeYieldsWhileAnonymousIsActive(): void {
		$insideBoth = null;
		$afterAnonymous = null;
		SystemOperationContext::run(
			function () use (&$insideBoth, &$afterAnonymous): void {
				AnonymousEvaluationContext::run(
					function () use (&$insideBoth): void {
						$insideBoth = SystemOperationContext::isActive();
					}
				);
				$afterAnonymous = SystemOperationContext::isActive();
			}
		);
		$this->assertFalse($insideBoth, 'system trust must be withheld inside the anonymous scope');
		$this->assertTrue($afterAnonymous, 'system trust must return once the anonymous scope ends');
	}//end testTheSystemScopeYieldsWhileAnonymousIsActive()


	/**
	 * The hard requirement of WOO-578: nothing in a request can switch this on
	 * or off. The scope has exactly two public entry points, both static, and
	 * neither takes a value — there is no setter a query key could be mapped to.
	 */
	public function testTheScopeHasNoSettableSurface(): void {
		$reflection = new ReflectionClass(AnonymousEvaluationContext::class);
		$public = array_map(
			static fn (\ReflectionMethod $m): string => $m->getName(),
			$reflection->getMethods(\ReflectionMethod::IS_PUBLIC)
		);
		sort($public);
		$this->assertSame(['isActive', 'run'], $public);
		$this->assertFalse($reflection->getConstructor()?->isPublic() ?? true, 'not instantiable');
	}//end testTheScopeHasNoSettableSurface()
}//end class
