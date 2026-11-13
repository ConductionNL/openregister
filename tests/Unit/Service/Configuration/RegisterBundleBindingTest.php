<?php

/**
 * Tests for RegisterBundleBinding.
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

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Service\Configuration;

use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Configuration\RegisterBundleBinding;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Service\Configuration\RegisterBundleBinding
 * @uses   \OCA\OpenRegister\Db\Register
 * @uses   \OCA\OpenRegister\Db\Schema
 * @uses   \OCA\OpenRegister\Service\Rbac\CrossOrganisationMarkerChange
 * @uses \OCA\OpenRegister\Db\Register\RegisterSchemaIds
 * @uses \OCA\OpenRegister\Service\Configuration\RegisterBundleEntryRules
 */
class RegisterBundleBindingTest extends TestCase {

	private const MARKED = ['read' => [['group' => 'public', 'crossOrganisation' => true]]];

	/**
	 * @return array<string, array{0: array<string, mixed>, 1: string|null}>
	 */
	public static function bundles(): array {
		$own = ['slug' => 'own', 'title' => 'Own'];

		return [
			'no components'                      => [[], null],
			'an own schema by key'               => [['components' => ['schemas' => ['own' => ['title' => 'Own']]]], null],
			'an own schema with its slug'        => [['components' => ['schemas' => ['own' => $own]]], null],
			'the register itself'                => [['components' => ['registers' => ['reg' => ['slug' => 'reg', 'schemas' => ['own']]]]], null],
			'an own schema keeps its marker'     => [['components' => ['schemas' => ['kept' => ['authorization' => self::MARKED]]]], null],
			'another register'                   => [['components' => ['registers' => ['other' => ['slug' => 'other']]]], 'another register'],
			'another register behind the key'    => [['components' => ['registers' => ['reg' => ['slug' => 'other']]]], 'another register'],
			'another register behind the slug'   => [['components' => ['registers' => ['other' => ['slug' => 'reg']]]], 'another register'],
			'a schema list that is no list'      => [['components' => ['registers' => ['reg' => ['schemas' => 'own']]]], 'another register'],
			'linking a schema from elsewhere'    => [['components' => ['registers' => ['reg' => ['schemas' => ['own', 'foreign']]]]], 'another schema'],
			'a schema from elsewhere'            => [['components' => ['schemas' => ['foreign' => ['title' => 'F']]]], 'schemas of this register'],
			'another slug behind an own key'     => [['components' => ['schemas' => ['own' => ['slug' => 'foreign']]]], 'schemas of this register'],
			'a schema that is no document'       => [['components' => ['schemas' => ['own' => 'text']]], 'schemas of this register'],
			'another component'                  => [['components' => ['schemas' => [], 'sources' => ['s' => []]]], 'only import'],
			'objects'                            => [['components' => ['objects' => [['title' => 'x']]]], 'only import'],
			'components that are no list'        => [['components' => 'x'], 'only import'],
			'a registers part that is no list'   => [['components' => ['registers' => 'x']], 'unreadable'],
			'a schemas part that is no list'     => [['components' => ['schemas' => 'x']], 'unreadable'],
			'a new marker'                       => [['components' => ['schemas' => ['own' => ['authorization' => self::MARKED]]]], 'crossOrganisation'],
			'a new marker as JSON text'          => [
				['components' => ['schemas' => ['own' => ['authorization' => json_encode(self::MARKED)]]]],
				'crossOrganisation',
			],
		];
	}//end bundles()

	#[DataProvider('bundles')]
	public function testAManagersBundleStaysInsideTheRegister(array $bundle, ?string $refusal): void {
		$register = new Register();
		$register->setSlug('reg');
		$register->setSchemas([1, 2]);

		$plain = new Schema();
		$plain->setSlug('own');
		$kept = new Schema();
		$kept->setSlug('kept');
		$kept->setAuthorization(self::MARKED);

		$mapper = $this->createMock(SchemaMapper::class);
		$mapper->method('findMultiple')->willReturnCallback(
			static function (array $ids) use ($plain, $kept): array {
				self::assertSame([1, 2], $ids);

				return [$plain, $kept];
			}
		);

		$result = (new RegisterBundleBinding(schemaMapper: $mapper))->refusalFor(register: $register, bundle: $bundle);

		if ($refusal === null) {
			self::assertNull($result);

			return;
		}

		self::assertStringContainsString($refusal, (string)$result);
	}//end testAManagersBundleStaysInsideTheRegister()
}//end class
