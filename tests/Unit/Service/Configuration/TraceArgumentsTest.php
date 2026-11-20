<?php

/**
 * TraceArgumentsTest: exceptions created inside the scope carry no arguments, and the setting is put back.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Configuration
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Service\Configuration;

use Exception;
use OCA\OpenRegister\Service\Configuration\TraceArguments;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * @covers \OCA\OpenRegister\Service\Configuration\TraceArguments
 */
class TraceArgumentsTest extends TestCase {

	private string|false $before;

	protected function setUp(): void {
		$this->before = ini_get('zend.exception_ignore_args');
		ini_set('zend.exception_ignore_args', '0');
	}//end setUp()

	protected function tearDown(): void {
		ini_set('zend.exception_ignore_args', (string) $this->before);
	}//end tearDown()

	/**
	 * An exception as a logger deep in a call chain would create it, with a large argument on the stack.
	 *
	 * @param array $configuration The argument a trace would otherwise carry.
	 *
	 * @return Exception
	 */
	private function exceptionBelow(array $configuration): Exception {
		return new Exception('dirty table reads');
	}//end exceptionBelow()

	/**
	 * Whether any frame of an exception's trace carries arguments.
	 *
	 * @param Exception $exception The exception.
	 *
	 * @return bool
	 */
	private static function carriesArguments(Exception $exception): bool {
		foreach ($exception->getTrace() as $frame) {
			// An include frame names its file as an argument whatever the setting says.
			if (in_array($frame['function'], ['include', 'include_once', 'require', 'require_once'], true) === true) {
				continue;
			}

			if (($frame['args'] ?? []) !== []) {
				return true;
			}
		}

		return false;
	}//end carriesArguments()

	public function testOutsideTheScopeATraceCarriesItsArguments(): void {
		$this->assertTrue(self::carriesArguments($this->exceptionBelow(['schemas' => range(1, 50)])));
	}//end testOutsideTheScopeATraceCarriesItsArguments()

	public function testInsideTheScopeATraceCarriesNoArguments(): void {
		$exception = (new TraceArguments())->withheldDuring(fn (): Exception => $this->exceptionBelow(['schemas' => range(1, 50)]));

		$this->assertFalse(self::carriesArguments($exception));
		$this->assertNotSame([], $exception->getTrace());
	}//end testInsideTheScopeATraceCarriesNoArguments()

	public function testTheResultIsReturnedAndTheSettingPutBack(): void {
		$this->assertSame(42, (new TraceArguments())->withheldDuring(static fn (): int => 42));
		$this->assertSame('0', ini_get('zend.exception_ignore_args'));
	}//end testTheResultIsReturnedAndTheSettingPutBack()

	public function testTheSettingIsPutBackWhenTheOperationThrows(): void {
		try {
			(new TraceArguments())->withheldDuring(static fn () => throw new RuntimeException('import failed'));
			$this->fail('The exception should have passed through.');
		} catch (RuntimeException $e) {
			$this->assertSame('import failed', $e->getMessage());
		}

		$this->assertSame('0', ini_get('zend.exception_ignore_args'));
	}//end testTheSettingIsPutBackWhenTheOperationThrows()

	public function testANestedScopeLeavesTheOuterOneInEffect(): void {
		$guard = new TraceArguments();

		$inside = $guard->withheldDuring(
			static function () use ($guard): string {
				$guard->withheldDuring(static fn (): null => null);
				return (string) ini_get('zend.exception_ignore_args');
			}
		);

		$this->assertSame('1', $inside);
		$this->assertSame('0', ini_get('zend.exception_ignore_args'));
	}//end testANestedScopeLeavesTheOuterOneInEffect()

	public function testAnInstanceThatAlreadyWithholdsThemIsLeftThatWay(): void {
		ini_set('zend.exception_ignore_args', '1');

		(new TraceArguments())->withheldDuring(static fn (): null => null);

		$this->assertSame('1', ini_get('zend.exception_ignore_args'));
	}//end testAnInstanceThatAlreadyWithholdsThemIsLeftThatWay()
}//end class
