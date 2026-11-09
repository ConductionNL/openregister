<?php

/**
 * Golden test for RegisterBundleBinding: the exact reason for every refused
 * bundle, the bundles that are admitted, and which reason wins when a bundle
 * breaks more than one rule.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Configuration
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/data-import-export/spec.md#requirement-a-bundle-uploaded-by-a-register-manager-stays-inside-that-register
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Configuration;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Configuration\RegisterBundleBinding;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Exact outcomes of the register-manager bundle gate.
 */
class RegisterBundleBindingGoldenTest extends TestCase {

	private const MARKED = ['read' => [['group' => 'public', 'crossOrganisation' => true]]];

	private const OTHER_REGISTER = 'A register manager may not import another register';

	private const OTHER_SCHEMA_LINK = 'A register manager may not link another schema to this register';

	private const OWN_SCHEMAS_ONLY = 'A register manager may only import schemas of this register';

	private const CROSS_ORG = 'Only an administrator may let a rule read across organisations (crossOrganisation: true)';

	/**
	 * @return array<string, array{0: array<string, mixed>, 1: string|null}>
	 */
	public static function bundles(): array {
		$marked = ['authorization' => self::MARKED];

		return [
			// Admitted.
			'two own schemas'                       => [['components' => ['schemas' => ['own' => [], 'kept' => $marked]]], null],
			'the register naming both own schemas'  => [['components' => ['registers' => ['reg' => ['schemas' => ['own', 'kept']]]]], null],
			'a register entry that is no document'  => [['components' => ['registers' => ['reg' => 'text']]], null],
			'a register entry that is null'         => [['components' => ['registers' => ['reg' => null]]], null],
			'empty registers and schemas'           => [['components' => ['registers' => [], 'schemas' => []]], null],
			'a marker removed'                      => [['components' => ['schemas' => ['kept' => ['authorization' => []]]]], null],
			// Refused, with the exact reason.
			'components that are no list'           => [['components' => 7], 'A register manager may only import schemas and the register itself'],
			'a registers part that is no list'      => [['components' => ['registers' => 'x']], 'The bundle has an unreadable registers part'],
			'a schemas part that is no list'        => [['components' => ['schemas' => 3]], 'The bundle has an unreadable schemas part'],
			'a numeric register key'                => [['components' => ['registers' => [0 => ['slug' => 'reg']]]], self::OTHER_REGISTER],
			'a numeric schema list entry'           => [['components' => ['registers' => ['reg' => ['schemas' => [1]]]]], self::OTHER_SCHEMA_LINK],
			'a second register after the own one'   => [['components' => ['registers' => ['reg' => [], 'other' => []]]], self::OTHER_REGISTER],
			'another register after the own one'   => [
				['components' => ['registers' => ['reg' => ['schemas' => ['own']], 'x' => ['slug' => 'x']]]],
				self::OTHER_REGISTER,
			],
			'a numeric schema key'                  => [['components' => ['schemas' => [0 => ['slug' => 'own']]]], self::OWN_SCHEMAS_ONLY],
			'a schema that is null'                 => [['components' => ['schemas' => ['own' => null]]], self::OWN_SCHEMAS_ONLY],
			'a marker on the plain schema'          => [['components' => ['schemas' => ['own' => $marked]]], self::CROSS_ORG],
			'a foreign schema after a marker'       => [['components' => ['schemas' => ['own' => $marked, 'foreign' => []]]], self::CROSS_ORG],
			'a marker after a foreign schema'       => [['components' => ['schemas' => ['foreign' => [], 'own' => $marked]]], self::OWN_SCHEMAS_ONLY],
			'register and schema both wrong'        => [
				['components' => ['schemas' => ['foreign' => []], 'registers' => ['other' => []]]],
				self::OTHER_REGISTER,
			],
			'a bad register link and a bad marker'  => [
				['components' => ['registers' => ['reg' => ['schemas' => ['foreign']]], 'schemas' => ['own' => $marked]]],
				self::OTHER_SCHEMA_LINK,
			],
		];
	}//end bundles()

	#[DataProvider('bundles')]
	public function testTheExactOutcome(array $bundle, ?string $refusal): void {
		$register = new Register();
		$register->setSlug('reg');
		$register->setSchemas([1, 2]);

		$plain = new Schema();
		$plain->setSlug('own');
		$kept = new Schema();
		$kept->setSlug('kept');
		$kept->setAuthorization(self::MARKED);

		$calls = 0;
		$mapper = $this->createMock(SchemaMapper::class);
		$mapper->method('findMultiple')->willReturnCallback(
			static function (array $ids) use ($plain, $kept, &$calls): array {
				$calls++;

				return [$plain, $kept];
			}
		);

		$result = (new RegisterBundleBinding(schemaMapper: $mapper))->refusalFor(register: $register, bundle: $bundle);

		self::assertSame($refusal, $result);
		self::assertLessThanOrEqual(1, $calls);
	}//end testTheExactOutcome()
}//end class
