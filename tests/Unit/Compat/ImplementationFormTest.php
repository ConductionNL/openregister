<?php

/**
 * ImplementationForm tells the parameter-object form from the positional form.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Compat
 *
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Compat;

use ArrayObject;
use Countable;
use OCA\OpenRegister\Kernel\Compat\ImplementationForm;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ImplementationFormTest extends TestCase {

	/**
	 * Implementers and whether each takes an ArrayObject first.
	 *
	 * @return array<string, array{object, bool}>
	 */
	public static function implementers(): array {
		return [
			'exact class'     => [new class { public function run(ArrayObject $o): void {} }, true],
			'parent type'     => [new class { public function run(Countable $o): void {} }, true],
			'union with it'   => [new class { public function run(ArrayObject|array $o): void {} }, true],
			'nullable'        => [new class { public function run(?ArrayObject $o): void {} }, true],
			'untyped'         => [new class { public function run($o): void {} }, true],
			'mixed'           => [new class { public function run(mixed $o): void {} }, true],
			'object'          => [new class { public function run(object $o): void {} }, true],
			'array'           => [new class { public function run(array $o, array $p): void {} }, false],
			'string'          => [new class { public function run(string $o): void {} }, false],
			'scalar union'    => [new class { public function run(string|int $o): void {} }, false],
			'unrelated class' => [new class { public function run(\DateTime $o): void {} }, false],
			'no parameters'   => [new class { public function run(): void {} }, false],
		];
	}

	#[DataProvider('implementers')]
	public function testTakesObject(object $implementer, bool $expected): void {
		$this->assertSame($expected, (new ImplementationForm())->takesObject(implementer: $implementer, method: 'run', parameterClass: ArrayObject::class));
	}
}
