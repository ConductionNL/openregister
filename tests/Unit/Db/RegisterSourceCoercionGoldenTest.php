<?php

/**
 * Golden test for the scalar coercion Register and Source apply to loose input.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Db
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db;

use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Source;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Pins how Register and Source treat values of the wrong scalar type.
 *
 * Neither entity declares strict_types, so a wrong scalar is coerced or
 * dropped instead of throwing a TypeError.
 */
class RegisterSourceCoercionGoldenTest extends TestCase {

	/**
	 * Integer keys in a Source body (a list) are skipped, the other keys still land.
	 *
	 * @return void
	 */
	public function testSourceHydrateSkipsIntegerKeys(): void {
		$source = new Source();
		$source->hydrate(['a', 'b', 'title' => 'T']);

		$this->assertSame('T', $source->getTitle());
	}//end testSourceHydrateSkipsIntegerKeys()

	/**
	 * An integer organisation on a Source is stored as its string.
	 *
	 * @return void
	 */
	public function testSourceIntegerOrganisationBecomesString(): void {
		$source = new Source();
		$source->hydrate(['organisation' => 123]);

		$this->assertSame('123', $source->getOrganisation());
	}//end testSourceIntegerOrganisationBecomesString()

	/**
	 * A string organisation on a Source is stored as given.
	 *
	 * @return void
	 */
	public function testSourceStringOrganisationIsKept(): void {
		$source = new Source();
		$source->hydrate(['organisation' => 'org-uuid']);

		$this->assertSame('org-uuid', $source->getOrganisation());
	}//end testSourceStringOrganisationIsKept()

	/**
	 * A scalar configuration on a Register becomes empty, and hydrate carries on.
	 *
	 * @return array<string, array{0: int|bool}>
	 */
	public static function scalarConfigurationProvider(): array {
		return [
			'integer' => [5],
			'boolean' => [true],
		];
	}//end scalarConfigurationProvider()

	/**
	 * A scalar configuration on a Register becomes empty, and hydrate carries on.
	 *
	 * @param int|bool $configuration The scalar configuration value
	 *
	 * @return void
	 */
	#[DataProvider('scalarConfigurationProvider')]
	public function testRegisterScalarConfigurationBecomesEmpty(int|bool $configuration): void {
		$register = new Register();
		$register->hydrate(['configuration' => $configuration, 'title' => 'T']);

		$this->assertSame([], $register->getConfiguration());
		$this->assertSame('T', $register->getTitle());
	}//end testRegisterScalarConfigurationBecomesEmpty()

	/**
	 * An array configuration on a Register is stored as given.
	 *
	 * @return void
	 */
	public function testRegisterArrayConfigurationIsKept(): void {
		$register = new Register();
		$register->hydrate(['configuration' => ['schemas' => ['5' => ['magicMapping' => true]]]]);

		$this->assertSame(['schemas' => ['5' => ['magicMapping' => true]]], $register->getConfiguration());
	}//end testRegisterArrayConfigurationIsKept()

	/**
	 * An integer first language is the default language as its string.
	 *
	 * @return void
	 */
	public function testRegisterIntegerLanguageIsDefaultAsString(): void {
		$register = new Register();
		$register->hydrate(['languages' => [5, 'en']]);

		$this->assertSame('5', $register->getDefaultLanguage());
		$this->assertTrue($register->hasLanguage('en'));
	}//end testRegisterIntegerLanguageIsDefaultAsString()

	/**
	 * A string first language is the default language.
	 *
	 * @return void
	 */
	public function testRegisterStringLanguageIsDefault(): void {
		$register = new Register();
		$register->hydrate(['languages' => ['en', 'nl']]);

		$this->assertSame('en', $register->getDefaultLanguage());
		$this->assertTrue($register->hasLanguage('nl'));
	}//end testRegisterStringLanguageIsDefault()
}//end class
