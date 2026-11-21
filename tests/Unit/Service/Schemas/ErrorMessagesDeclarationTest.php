<?php

/**
 * A property's x-error-messages is checked at schema save.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Schemas
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/modelling-validation-messages/specs/schema-validation-messages/spec.md#requirement-a-property-may-declare-its-own-validation-messages
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Schemas;

use Exception;
use OCA\OpenRegister\Service\Schemas\PropertyValidatorHandler;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for {@see \OCA\OpenRegister\Service\Schemas\ErrorMessagesDeclaration}, through the property validator.
 */
class ErrorMessagesDeclarationTest extends TestCase {

	/**
	 * A string and a language map are both accepted.
	 *
	 * @return void
	 */
	public function testAStringAndALanguageMapAreAccepted(): void {
		$this->assertTrue(
			(new PropertyValidatorHandler())->validateProperty(
				$this->postcode(messages: ['pattern' => ['nl' => 'Vul een postcode in zoals 1234 AB.', 'en-GB' => 'Enter a postcode.'], 'required' => 'Postcode is verplicht.']),
				'/postcode'
			)
		);
	}//end testAStringAndALanguageMapAreAccepted()

	/**
	 * An unsupported keyword is refused, naming the property and the key.
	 *
	 * @return void
	 */
	public function testAnUnsupportedKeywordIsRefused(): void {
		$this->assertRefused(messages: ['colour' => 'Rood.'], fragments: ['/postcode', 'colour']);
	}//end testAnUnsupportedKeywordIsRefused()

	/**
	 * A message that is neither a string nor a language map is refused.
	 *
	 * @return void
	 */
	public function testAMessageOfAnotherShapeIsRefused(): void {
		$this->assertRefused(messages: ['pattern' => 42], fragments: ['/postcode', 'pattern']);
		$this->assertRefused(messages: ['pattern' => ['Vul een postcode in.']], fragments: ['/postcode', 'pattern']);
		$this->assertRefused(messages: ['pattern' => ''], fragments: ['/postcode', 'pattern']);
		$this->assertRefused(messages: ['pattern' => ['nl' => '']], fragments: ['/postcode', 'pattern', "'nl'"]);
	}//end testAMessageOfAnotherShapeIsRefused()

	/**
	 * A language tag that is not BCP 47 is refused.
	 *
	 * @return void
	 */
	public function testAnInvalidLanguageTagIsRefused(): void {
		$this->assertRefused(messages: ['pattern' => ['Dutch' => 'Vul een postcode in.']], fragments: ['/postcode', 'pattern', 'Dutch']);
	}//end testAnInvalidLanguageTagIsRefused()

	/**
	 * A list instead of a keyword map is refused.
	 *
	 * @return void
	 */
	public function testAListInsteadOfAMapIsRefused(): void {
		$this->assertRefused(messages: ['Vul een postcode in.'], fragments: ['/postcode', 'x-error-messages']);
	}//end testAListInsteadOfAMapIsRefused()

	/**
	 * A property declaring no messages is untouched.
	 *
	 * @return void
	 */
	public function testAPropertyWithoutMessagesIsUntouched(): void {
		$this->assertTrue((new PropertyValidatorHandler())->validateProperty(['type' => 'string', 'pattern' => '^[0-9]+$'], '/postcode'));
	}//end testAPropertyWithoutMessagesIsUntouched()

	/**
	 * Assert the property is refused with a message holding every fragment.
	 *
	 * @param mixed              $messages  The x-error-messages value.
	 * @param array<int, string> $fragments What the refusal must name.
	 *
	 * @return void
	 */
	private function assertRefused(mixed $messages, array $fragments): void {
		try {
			(new PropertyValidatorHandler())->validateProperty($this->postcode(messages: $messages), '/postcode');
			$this->fail('The property was saved with x-error-messages '.json_encode($messages));
		} catch (Exception $refusal) {
			foreach ($fragments as $fragment) {
				$this->assertStringContainsString($fragment, $refusal->getMessage());
			}
		}
	}//end assertRefused()

	/**
	 * The postcode property with the given messages.
	 *
	 * @param mixed $messages The x-error-messages value.
	 *
	 * @return array<string, mixed> The property.
	 */
	private function postcode(mixed $messages): array {
		return ['type' => 'string', 'pattern' => '^[1-9][0-9]{3} ?[A-Z]{2}$', 'x-error-messages' => $messages];
	}//end postcode()
}//end class
