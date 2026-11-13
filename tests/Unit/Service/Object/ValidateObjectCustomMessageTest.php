<?php

/**
 * A property's declared validation message replaces the generated one.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Object
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/modelling-validation-messages/specs/schema-validation-messages/spec.md#requirement-a-failed-rule-returns-the-declared-message-in-the-request-language
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Object;

use OCA\OpenRegister\Db\MagicMapperInterface;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Service\LanguageService;
use OCA\OpenRegister\Service\Object\ValidateObject;
use OCA\OpenRegister\Service\Object\ValidationErrorMessages;
use OCA\OpenRegister\Service\Object\ValidationLookups;
use OCA\OpenRegister\Service\Object\ValidationSchemaSource;
use Opis\JsonSchema\ValidationResult;
use Opis\JsonSchema\Validator;
use OCP\IAppConfig;
use OCP\IURLGenerator;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for declared validation messages, through real Opis validation.
 */
class ValidateObjectCustomMessageTest extends TestCase {

	/**
	 * The postcode rule of the design's example.
	 */
	private const POSTCODE = '^[1-9][0-9]{3} ?[A-Z]{2}$';

	/**
	 * A failed pattern carries the declared message, not the generated one.
	 *
	 * @return void
	 */
	public function testAFailedPatternCarriesTheDeclaredMessage(): void {
		$result = $this->validate(data: ['postcode' => 'ABCD'], messages: ['pattern' => 'Vul een postcode in zoals 1234 AB.']);

		$this->assertSame('Vul een postcode in zoals 1234 AB.', (new ValidationErrorMessages())->forResult(result: $result));
	}//end testAFailedPatternCarriesTheDeclaredMessage()

	/**
	 * A missing property carries the message the MISSING property declares for required.
	 *
	 * @return void
	 */
	public function testAMissingPropertyCarriesItsRequiredMessage(): void {
		$result = $this->validate(data: ['naam' => 'Jan'], messages: ['required' => 'Postcode is verplicht.']);

		$this->assertSame('Postcode is verplicht.', (new ValidationErrorMessages())->forResult(result: $result));
	}//end testAMissingPropertyCarriesItsRequiredMessage()

	/**
	 * A property without a message keeps exactly the message it had before.
	 *
	 * @return void
	 */
	public function testAPropertyWithoutAMessageKeepsTheGeneratedOne(): void {
		$data = ['postcode' => '1234 AB', 'naam' => 'abc'];
		$naam = ['type' => 'string', 'minLength' => 10];
		$withMessages = $this->validate(data: $data, messages: ['pattern' => 'Vul een postcode in.'], naam: $naam);
		$without = $this->validate(data: $data, messages: [], naam: $naam);

		$this->assertSame(
			"Property 'naam' requires at least 10 chars, has 3. Please provide a longer string value.",
			(new ValidationErrorMessages())->forResult(result: $without)
		);
		$this->assertSame(
			(new ValidationErrorMessages())->forResult(result: $without),
			(new ValidationErrorMessages(language: 'en'))->forResult(result: $withMessages)
		);
	}//end testAPropertyWithoutAMessageKeepsTheGeneratedOne()

	/**
	 * The request language wins, then nl, then the first declared language.
	 *
	 * @return void
	 */
	public function testTheLanguageFallsBackFromRequestToDutchToFirstDeclared(): void {
		$both = $this->validate(data: ['postcode' => 'ABCD'], messages: ['pattern' => ['nl' => 'Vul een postcode in.', 'en' => 'Enter a postcode.']]);
		$onlyEn = $this->validate(data: ['postcode' => 'ABCD'], messages: ['pattern' => ['en' => 'Enter a postcode.']]);

		$this->assertSame('Enter a postcode.', (new ValidationErrorMessages(language: 'en'))->forResult(result: $both));
		$this->assertSame('Enter a postcode.', (new ValidationErrorMessages(language: 'en-GB'))->forResult(result: $both));
		$this->assertSame('Vul een postcode in.', (new ValidationErrorMessages(language: 'nl'))->forResult(result: $both));
		$this->assertSame('Vul een postcode in.', (new ValidationErrorMessages(language: 'de'))->forResult(result: $both));
		$this->assertSame('Enter a postcode.', (new ValidationErrorMessages(language: 'nl'))->forResult(result: $onlyEn));
	}//end testTheLanguageFallsBackFromRequestToDutchToFirstDeclared()

	/**
	 * Placeholders are replaced as plain text, the value cut to 100 characters, markup kept literal.
	 *
	 * @return void
	 */
	public function testPlaceholdersAreSubstitutedAsPlainText(): void {
		$value = '<b>'.str_repeat('x', 300);
		$result = $this->validate(
			data: ['postcode' => '1234 AB', 'naam' => $value],
			messages: ['pattern' => 'x'],
			naam: ['type' => 'string', 'maxLength' => 20, 'x-error-messages' => ['maxLength' => '{value} is te lang voor {property} (max {limit})']]
		);

		$this->assertSame(
			mb_substr($value, 0, 100).' is te lang voor naam (max 20)',
			(new ValidationErrorMessages())->forResult(result: $result)
		);
	}//end testPlaceholdersAreSubstitutedAsPlainText()

	/**
	 * A placeholder inside the submitted value is not substituted again.
	 *
	 * @return void
	 */
	public function testAPlaceholderInTheValueIsNotExpanded(): void {
		$result = $this->validate(data: ['postcode' => '{property}'], messages: ['pattern' => '{value} is geen postcode']);

		$this->assertSame('{property} is geen postcode', (new ValidationErrorMessages())->forResult(result: $result));
	}//end testAPlaceholderInTheValueIsNotExpanded()

	/**
	 * ValidateObject asks the request's language and uses it.
	 *
	 * @return void
	 */
	public function testValidateObjectUsesTheRequestLanguage(): void {
		$language = new LanguageService();
		$language->setPreferredLanguage('en');
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(fn (string $id): ?object => $id === LanguageService::class ? $language : null);
		$handler = new ValidateObject(
			schemas: new ValidationSchemaSource(
				config: $this->createMock(IAppConfig::class),
				schemaMapper: $this->createMock(SchemaMapperInterface::class),
				urlGenerator: $this->createMock(IURLGenerator::class),
			),
			lookups: new ValidationLookups(
				objectMapper: $this->createMock(MagicMapperInterface::class),
				userManager: $this->createMock(IUserManager::class),
				container: $container,
			),
			logger: $this->createMock(LoggerInterface::class),
		);

		$result = $this->validate(data: ['postcode' => 'ABCD'], messages: ['pattern' => ['nl' => 'Vul een postcode in.', 'en' => 'Enter a postcode.']]);

		$this->assertSame('Enter a postcode.', $handler->generateErrorMessage(result: $result));
	}//end testValidateObjectUsesTheRequestLanguage()

	/**
	 * Validate data against a melding schema whose postcode declares the given messages.
	 *
	 * @param array<string, mixed> $data     The object.
	 * @param array<string, mixed> $messages The postcode's x-error-messages.
	 * @param array<string, mixed> $naam     The naam property.
	 *
	 * @return ValidationResult The result.
	 */
	private function validate(array $data, array $messages, array $naam=['type' => 'string']): ValidationResult {
		$postcode = ['type' => 'string', 'pattern' => self::POSTCODE];
		if ($messages !== []) {
			$postcode['x-error-messages'] = $messages;
		}

		$schema = [
			'type' => 'object',
			'required' => ['postcode'],
			'properties' => ['postcode' => $postcode, 'naam' => $naam],
		];

		$result = (new Validator())->validate(json_decode((string)json_encode($data)), json_decode((string)json_encode($schema)));
		$this->assertFalse($result->isValid());

		return $result;
	}//end validate()
}//end class
