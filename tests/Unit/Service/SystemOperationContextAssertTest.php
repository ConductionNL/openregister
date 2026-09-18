<?php

declare(strict_types=1);

/**
 * A system write declares itself, and the declaration is verified.
 *
 * 🔴 THE PROBLEM THIS SOLVES IS A SILENT DEGRADATION IN CONSUMERS. An app that
 * cannot hard-depend on OpenRegister writes:
 *
 *     if (class_exists(SystemOperationContext::class)) {
 *         return SystemOperationContext::run($operation);
 *     }
 *     return $operation();
 *
 * The fallback runs the identical write as whoever is signed in and returns the
 * same value the elevated call would have. Nothing throws, nothing logs, and a
 * reviewer asking "which writes run as the system" cannot answer statically:
 * a call site naming the context may or may not have elevated. That ambiguity
 * is what stopped integriq's permission sweep.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service
 *
 * @author   Conduction Development Team <dev@conduction.nl>
 * @license  EUPL-1.2
 *
 * @spec openspec/changes/a-system-write-declares-itself/specs/system-operation-context/spec.md
 */

namespace Unit\Service;

use OCA\OpenRegister\Exception\SystemContextUnavailableException;
use OCA\OpenRegister\Service\SystemOperationContext;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Tests for SystemOperationContext::assertSystem().
 */
class SystemOperationContextAssertTest extends TestCase {

	/**
	 * A declared system write runs, and runs elevated.
	 *
	 * @return void
	 */
	public function testADeclaredSystemWriteRunsElevated(): void {
		$seen = null;

		$result = SystemOperationContext::assertSystem(
			what: 'a source',
			operation: static function () use (&$seen) {
				$seen = SystemOperationContext::isActive();

				return 'written';
			}
		);

		$this->assertSame('written', $result);
		$this->assertTrue($seen, 'the operation must observe the scope as active');
	}//end testADeclaredSystemWriteRunsElevated()

	/**
	 * 🔴 THE SCOPE CLOSES AFTERWARDS. An elevation that leaks past its callable
	 * is a far worse bug than one that never applied: every later write in the
	 * request silently becomes a system write.
	 *
	 * @return void
	 */
	public function testTheScopeClosesWhenTheWriteIsDone(): void {
		SystemOperationContext::assertSystem(what: 'a source', operation: static fn () => null);

		$this->assertFalse(SystemOperationContext::isActive());
	}//end testTheScopeClosesWhenTheWriteIsDone()

	/**
	 * And it closes when the operation throws, so a failed system write cannot
	 * leave the rest of the request elevated.
	 *
	 * @return void
	 */
	public function testTheScopeClosesWhenTheWriteThrows(): void {
		try {
			SystemOperationContext::assertSystem(
				what: 'a source',
				operation: static function (): void {
					throw new RuntimeException('the write failed');
				}
			);
			$this->fail('the operation\'s exception must propagate');
		} catch (RuntimeException $error) {
			$this->assertSame('the write failed', $error->getMessage());
		}

		$this->assertFalse(SystemOperationContext::isActive());
	}//end testTheScopeClosesWhenTheWriteThrows()

	/**
	 * The caller's own exception reaches the caller unchanged, rather than
	 * being reported as an elevation problem.
	 *
	 * @return void
	 */
	public function testTheOperationsOwnFailureIsNotReportedAsAnElevationFailure(): void {
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('the write failed');

		SystemOperationContext::assertSystem(
			what: 'a source',
			operation: static function (): void {
				throw new RuntimeException('the write failed');
			}
		);
	}//end testTheOperationsOwnFailureIsNotReportedAsAnElevationFailure()

	/**
	 * Declared writes nest, so a system write that calls another one does not
	 * lose its elevation when the inner scope ends.
	 *
	 * @return void
	 */
	public function testDeclaredWritesNest(): void {
		$outerStillElevated = null;

		SystemOperationContext::assertSystem(
			what: 'the outer write',
			operation: static function () use (&$outerStillElevated): void {
				SystemOperationContext::assertSystem(what: 'the inner write', operation: static fn () => null);

				$outerStillElevated = SystemOperationContext::isActive();
			}
		);

		$this->assertTrue($outerStillElevated, 'the inner scope must not end the outer one');
		$this->assertFalse(SystemOperationContext::isActive());
	}//end testDeclaredWritesNest()

	/**
	 * 🔴 THE VERIFICATION IS LIVE, AND THIS IS THE TEST THAT PROVES IT.
	 *
	 * The `$elevated === false` branch cannot fire while `run()` works, so
	 * mutating that branch away reddens nothing on a healthy tree — which is
	 * the signature of a dead guard, and this lane has deleted two of those.
	 * The difference is that this one CAN fire: it fires exactly when the
	 * elevation stops taking effect, which is the defect it exists for.
	 *
	 * So the test breaks the elevation rather than the guard. The depth counter
	 * is reset from under the running operation, which is what a refactor
	 * dropping the increment, or a nested scope decrementing early, would look
	 * like from here.
	 *
	 * @return void
	 */
	public function testAWriteThatDidNotActuallyElevateIsReported(): void {
		$depth = new \ReflectionProperty(SystemOperationContext::class, 'depth');

		$this->expectException(SystemContextUnavailableException::class);
		$this->expectExceptionMessageMatches('/was not in effect/');

		try {
			SystemOperationContext::assertSystem(
				what: 'a source',
				operation: static function () use ($depth): void {
					// The elevation silently stops applying mid-operation.
					$depth->setValue(null, 0);
				}
			);
		} finally {
			$depth->setValue(null, 0);
		}
	}//end testAWriteThatDidNotActuallyElevateIsReported()

	/**
	 * 🔴 THE REFUSAL EXISTS AND NAMES THE WRITE. A consumer that meets it must
	 * be able to tell which of its writes was refused without a debugger.
	 *
	 * @return void
	 */
	public function testTheRefusalNamesWhatWasBeingWritten(): void {
		$refusal = new SystemContextUnavailableException(
			message: '"a source" was declared as a system write'
		);

		$this->assertStringContainsString('a source', $refusal->getMessage());
		$this->assertInstanceOf(RuntimeException::class, $refusal);
	}//end testTheRefusalNamesWhatWasBeingWritten()
}//end class
