<?php

/**
 * A field whose values come from an integration provider.
 *
 * 🔴 AN `x-` KEY IS ACCEPTED WITHOUT ANY OF THIS, WHICH IS WHY THE CLASS
 * EXISTS. `assertKeysAreInTheVocabulary()` skips every `x-` prefixed key, so
 * before this change a property could carry `{"provider": 7}` or
 * `{"mode": "livee"}` and save cleanly, and the consumer would read what it
 * could and guess the rest. That is the same shape as the concept-scheme
 * binding, which saved for months and could not be forwarded because nothing
 * published it.
 *
 * 🔑 AND IT IS NOT `x-openregister-object-source`. That key serves a WHOLE
 * SCHEMA's objects from a provider. This one serves one property's values. Two
 * keys differing by one word and by their entire blast radius, and a schema
 * declaring the wrong one is accepted by both.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Schemas
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Schemas;

use OCA\OpenRegister\Service\Schemas\PropertySourceDeclaration;
use OCA\OpenRegister\Service\Schemas\PropertySourceException;
use PHPUnit\Framework\TestCase;

/**
 * `x-openregister-property-source`.
 *
 * @covers \OCA\OpenRegister\Service\Schemas\PropertySourceDeclaration
 */
class PropertySourceDeclarationTest extends TestCase {

	/**
	 * A property carrying the given declaration.
	 *
	 * @param mixed $declaration What the author wrote.
	 *
	 * @return array<string, mixed> The property.
	 */
	private function propertyWith(mixed $declaration): array {
		return ['type' => 'string', PropertySourceDeclaration::ANNOTATION => $declaration];
	}//end propertyWith()

	/**
	 * A property with no declaration is left entirely alone.
	 *
	 * @return void
	 */
	public function testAPropertyWithoutADeclarationIsUnchanged(): void {
		$this->assertNull(PropertySourceDeclaration::fromProperty(['type' => 'string']));
	}//end testAPropertyWithoutADeclarationIsUnchanged()

	/**
	 * The defined shape is read back.
	 *
	 * @return void
	 */
	public function testTheDefinedShapeIsReadBack(): void {
		$declaration = PropertySourceDeclaration::fromProperty(
			$this->propertyWith(['provider' => 'kvk', 'mode' => 'default', 'config' => ['veld' => 'naam']]),
			'/bedrijf'
		);

		$this->assertNotNull($declaration);
		$this->assertSame('kvk', $declaration->provider);
		$this->assertSame('default', $declaration->mode);
		$this->assertSame(['veld' => 'naam'], $declaration->config);
	}//end testTheDefinedShapeIsReadBack()

	/**
	 * 🔑 THE MODE DEFAULTS TO `live`, AND THE WEAKER PROMISE IS ASKED FOR BY NAME.
	 *
	 * A registry-backed field exists so the value is looked up when it is used.
	 * `default` means the provider only supplies a starting value a person may
	 * change, which is a different promise to whoever reads the record later.
	 *
	 * @return void
	 */
	public function testTheModeDefaultsToLive(): void {
		$declaration = PropertySourceDeclaration::fromProperty($this->propertyWith(['provider' => 'kvk']));

		$this->assertSame(PropertySourceDeclaration::MODE_LIVE, $declaration->mode);
	}//end testTheModeDefaultsToLive()

	/**
	 * A declaration naming no provider is refused.
	 *
	 * Without one there is nothing to ask for the values, so the field would
	 * be a registry-backed field bound to no registry.
	 *
	 * @return void
	 */
	public function testADeclarationWithNoProviderIsRefused(): void {
		$this->expectException(PropertySourceException::class);

		PropertySourceDeclaration::assert($this->propertyWith(['mode' => 'live']), '/bedrijf');
	}//end testADeclarationWithNoProviderIsRefused()

	/**
	 * A provider id that is not an identifier is refused.
	 *
	 * A typo here becomes an empty list in a form, with nothing to say why.
	 *
	 * @return void
	 */
	public function testAProviderThatIsNotAnIdentifierIsRefused(): void {
		$this->expectException(PropertySourceException::class);

		PropertySourceDeclaration::assert($this->propertyWith(['provider' => 'kvk provider!']), '/bedrijf');
	}//end testAProviderThatIsNotAnIdentifierIsRefused()

	/**
	 * 🔴 A MODE NOBODY KNOWS IS REFUSED, NOT READ AS A GUESS.
	 *
	 * The two modes differ in whether a person may change what the provider
	 * returned, so guessing picks a promise the author did not make.
	 *
	 * @return void
	 */
	public function testAModeNobodyKnowsIsRefused(): void {
		$this->expectException(PropertySourceException::class);

		PropertySourceDeclaration::assert(
			$this->propertyWith(['provider' => 'kvk', 'mode' => 'livee']),
			'/bedrijf'
		);
	}//end testAModeNobodyKnowsIsRefused()

	/**
	 * Every mode the class declares is actually accepted.
	 *
	 * Derived from the constant rather than restated, so the accepted list and
	 * the advertised list cannot drift.
	 *
	 * @return void
	 */
	public function testEveryDeclaredModeIsAccepted(): void {
		foreach (PropertySourceDeclaration::MODES as $mode) {
			$declaration = PropertySourceDeclaration::fromProperty(
				$this->propertyWith(['provider' => 'kvk', 'mode' => $mode])
			);

			$this->assertSame($mode, $declaration->mode, $mode . ' is advertised but refused');
		}
	}//end testEveryDeclaredModeIsAccepted()

	/**
	 * A declaration that is not an object at all is refused.
	 *
	 * @return void
	 */
	public function testANonObjectDeclarationIsRefused(): void {
		$this->expectException(PropertySourceException::class);

		PropertySourceDeclaration::assert($this->propertyWith('kvk'), '/bedrijf');
	}//end testANonObjectDeclarationIsRefused()

	/**
	 * A config that is not an object is refused.
	 *
	 * @return void
	 */
	public function testANonObjectConfigIsRefused(): void {
		$this->expectException(PropertySourceException::class);

		PropertySourceDeclaration::assert(
			$this->propertyWith(['provider' => 'kvk', 'config' => 'naam']),
			'/bedrijf'
		);
	}//end testANonObjectConfigIsRefused()

	/**
	 * 🔴 BOTH SOURCE KEYS ON ONE PROPERTY IS REFUSED RATHER THAN RANKED.
	 *
	 * They answer different questions at different scopes, so a property
	 * carrying both is a schema whose author meant one of them. Picking one
	 * would be right about half the time and silent the rest, and the wrong
	 * half serves an entire register from somewhere unexpected.
	 *
	 * @return void
	 */
	public function testCarryingBothSourceKeysIsRefused(): void {
		$this->expectException(PropertySourceException::class);

		PropertySourceDeclaration::assert(
			[
				'type' => 'string',
				PropertySourceDeclaration::ANNOTATION => ['provider' => 'kvk'],
				PropertySourceDeclaration::NOT_THIS_ONE => ['provider' => 'kvk'],
			],
			'/bedrijf'
		);
	}//end testCarryingBothSourceKeysIsRefused()

	/**
	 * The other key on its own is not this class's business.
	 *
	 * The control for the refusal above: without it, a class that threw
	 * whenever it saw the object-source key would pass while refusing schemas
	 * that never mentioned this one.
	 *
	 * @return void
	 */
	public function testTheOtherKeyAloneIsIgnored(): void {
		$this->assertNull(
			PropertySourceDeclaration::fromProperty(
				['type' => 'string', PropertySourceDeclaration::NOT_THIS_ONE => ['provider' => 'kvk']]
			)
		);
	}//end testTheOtherKeyAloneIsIgnored()
}//end class
