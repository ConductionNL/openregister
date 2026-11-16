<?php

/**
 * DefensiveGetters: getter reads that never throw.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\ObjectSource
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/unit-test-coverage/tasks.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\ObjectSource;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Service\ObjectSource\DefensiveGetters;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * @covers \OCA\OpenRegister\Service\ObjectSource\DefensiveGetters
 */
class DefensiveGettersTest extends TestCase {

	/**
	 * A reader exposing the trait's private getters.
	 *
	 * @return object
	 */
	private function reader(): object {
		return new class {
			use DefensiveGetters;

			public function int(object $entity, string $getter): ?int {
				return $this->intGetter(entity: $entity, getter: $getter);
			}

			public function string(object $entity, string $getter): string {
				return $this->stringGetter(entity: $entity, getter: $getter);
			}
		};
	}

	/**
	 * An entity whose getters return fixed values or throw.
	 *
	 * @return object
	 */
	private function entity(): object {
		return new class {
			public function getRegister(): string {
				return '12';
			}

			public function getSchema(): ?int {
				return null;
			}

			public function getTitle(): int {
				return 7;
			}

			public function getBroken(): never {
				throw new RuntimeException('no value');
			}
		};
	}

	public function testIntGetterCastsAndKeepsNull(): void {
		$this->assertSame(12, $this->reader()->int($this->entity(), 'getRegister'));
		$this->assertNull($this->reader()->int($this->entity(), 'getSchema'));
	}

	public function testStringGetterCasts(): void {
		$this->assertSame('7', $this->reader()->string($this->entity(), 'getTitle'));
		$this->assertSame('', $this->reader()->string($this->entity(), 'getSchema'));
	}

	public function testAThrowingOrMissingGetterReadsAsEmpty(): void {
		$this->assertNull($this->reader()->int($this->entity(), 'getBroken'));
		$this->assertSame('', $this->reader()->string($this->entity(), 'getBroken'));
		$this->assertNull($this->reader()->int($this->entity(), 'getMissing'));
		$this->assertSame('', $this->reader()->string($this->entity(), 'getMissing'));
	}
}
