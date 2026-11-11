<?php

/**
 * RegistryUpdateTargetGuard: an `owned` declaration that is not a list owns
 * nothing, and numeric owned names still match their supplied keys.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Registry
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Registry;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Db\RegistrySubscription;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Service\Registry\RegistryOwnedPropertyGuard;
use OCA\OpenRegister\Service\Registry\RegistryUpdateTargetGuard;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Service\Registry\RegistryUpdateTargetGuard
 */
class RegistryUpdateTargetGuardOwnedShapeTest extends TestCase {

	private function guardFor(mixed $owned): RegistryUpdateTargetGuard {
		$schema = new Schema();
		$schema->setConfiguration(['x-openregister-registry' => ['registry' => 'brp', 'identity' => 'bsn', 'owned' => $owned]]);
		$mapper = $this->createMock(SchemaMapperInterface::class);
		$mapper->expects($this->once())->method('find')->with('7')->willReturn($schema);
		return new RegistryUpdateTargetGuard($mapper, new RegistryOwnedPropertyGuard());
	}

	private static function row(): RegistrySubscription {
		$row = new RegistrySubscription();
		$row->setSchema('7');
		return $row;
	}

	public function testANonListOwnedDeclarationRejectsEverySuppliedProperty(): void {
		$result = $this->guardFor('address')->evaluate(self::row(), ['address' => 'Dam 1', 'city' => 'A']);

		$this->assertSame(['allowed' => false, 'rejected' => ['address', 'city']], $result);
	}

	public function testAnAbsentOwnedListRejectsEverySuppliedProperty(): void {
		$schema = new Schema();
		$schema->setConfiguration(['x-openregister-registry' => ['registry' => 'brp']]);
		$mapper = $this->createMock(SchemaMapperInterface::class);
		$mapper->method('find')->willReturn($schema);
		$guard = new RegistryUpdateTargetGuard($mapper, new RegistryOwnedPropertyGuard());

		$this->assertSame(['allowed' => false, 'rejected' => ['address']], $guard->evaluate(self::row(), ['address' => 'x']));
	}

	public function testOwnedNamesAreComparedAsStrings(): void {
		$result = $this->guardFor([1, 'address'])->evaluate(self::row(), ['1' => 'x', 'address' => 'y']);

		$this->assertSame(['allowed' => true, 'rejected' => []], $result);
	}

	public function testAnEmptyPayloadIsAllowed(): void {
		$this->assertSame(['allowed' => true, 'rejected' => []], $this->guardFor(['address'])->evaluate(self::row(), []));
	}
}
