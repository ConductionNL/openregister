<?php

/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller\Support;

use OCA\OpenRegister\Controller\Support\ActionParameters;
use OCA\OpenRegister\Exception\ActionParameterTypeException;
use PHPUnit\Framework\TestCase;

/**
 * Pins the casts ActionParameters shares with Nextcloud's binding of typed
 * action arguments: defaults for absent and null, settype() for int and
 * bool, `'false'` as false, and a refusal for a string or array of another type.
 */
final class ActionParametersTest extends TestCase {

	public function testAbsentAndNullTakeTheDefault(): void {
		$read = new ActionParameters(params: ['s' => null, 'i' => null, 'b' => null, 'l' => null]);

		$this->assertSame(
			['d', null, 25, 25, true, false, null],
			[$read->string(key: 's', default: 'd'), $read->string(key: 'absent'), $read->int(key: 'i', default: 25), $read->int(key: 'absent', default: 25), $read->bool(key: 'b', default: true), $read->bool(key: 'absent', default: false), $read->list(key: 'l')]
		);
	}

	public function testIntAndBoolAreCastWithSettype(): void {
		$read = new ActionParameters(params: ['n' => '7', 'x' => 'abc', 'f' => '7.9', 'false' => 'false', 'zero' => '0', 'empty' => '', 'no' => 'no']);

		$this->assertSame(
			[7, 0, 7, false, false, false, true],
			[$read->int(key: 'n', default: 1), $read->int(key: 'x', default: 1), $read->int(key: 'f', default: 1), $read->bool(key: 'false', default: true), $read->bool(key: 'zero', default: true), $read->bool(key: 'empty', default: true), $read->bool(key: 'no', default: false)]
		);
	}

	public function testAStringParameterOfAnotherTypeIsRefused(): void {
		$this->expectException(ActionParameterTypeException::class);
		$this->expectExceptionMessage('Parameter `n` must be a string');

		(new ActionParameters(params: ['n' => 5]))->string(key: 'n');
	}

	public function testAnArrayParameterOfAnotherTypeIsRefused(): void {
		$this->expectException(ActionParameterTypeException::class);
		$this->expectExceptionMessage('Parameter `l` must be an array');

		(new ActionParameters(params: ['l' => 'x']))->list(key: 'l');
	}
}
