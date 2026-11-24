<?php

/**
 * ErrorEntryIdentityTest: each validation error entry keeps its keyword and property.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
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

// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Service\Object;

use OCA\OpenRegister\Exception\ValidationException;
use OCA\OpenRegister\Service\Object\ErrorEntryIdentity;
use OCA\OpenRegister\Service\Object\ValidateObject;
use OCA\OpenRegister\Service\Object\ValidationErrorMessages;
use Opis\JsonSchema\Errors\ValidationError;
use Opis\JsonSchema\Validator;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * @covers \OCA\OpenRegister\Service\Object\ErrorEntryIdentity
 */
class ErrorEntryIdentityTest extends TestCase {

	private const SCHEMA = [
		'type' => 'object',
		'required' => ['postcode'],
		'properties' => [
			'postcode' => [
				'type' => 'string',
				'pattern' => '^[1-9][0-9]{3} ?[A-Z]{2}$',
				'x-error-messages' => ['pattern' => ['nl' => 'Vul een postcode in zoals 1234 AB.']],
			],
			'adres' => [
				'type' => 'object',
				'required' => ['straat'],
				'properties' => ['straat' => ['type' => 'string'], 'huisnummer' => ['type' => 'integer', 'minimum' => 1]],
			],
			'tags' => ['type' => 'array', 'items' => ['type' => 'string', 'minLength' => 2]],
		],
	];

	/**
	 * The error Opis reports for the data.
	 *
	 * @param array<string, mixed> $data The object data.
	 *
	 * @return ValidationError The error.
	 */
	private function error(array $data): ValidationError {
		$result = (new Validator())->validate(json_decode((string)json_encode($data)), json_decode((string)json_encode(self::SCHEMA)));
		$this->assertFalse($result->isValid());

		return $result->error();
	}//end error()

	/**
	 * @return array<string, array{0: array<string, mixed>, 1: array{keyword: string, property: string|null}}>
	 */
	public static function failures(): array {
		return [
			'pattern' => [['postcode' => 'ABCD'], ['keyword' => 'pattern', 'property' => 'postcode']],
			'required' => [['adres' => ['straat' => 'Laan']], ['keyword' => 'required', 'property' => 'postcode']],
			'nested required' => [['postcode' => '1234 AB', 'adres' => ['huisnummer' => 3]], ['keyword' => 'required', 'property' => 'adres.straat']],
			'nested minimum' => [['postcode' => '1234 AB', 'adres' => ['straat' => 'Laan', 'huisnummer' => 0]], ['keyword' => 'minimum', 'property' => 'adres.huisnummer']],
			'item minLength' => [['postcode' => '1234 AB', 'tags' => ['ok', 'x']], ['keyword' => 'minLength', 'property' => 'tags.1']],
		];
	}//end failures()

	/**
	 * @dataProvider failures
	 *
	 * @param array<string, mixed>                                 $data     The object data.
	 * @param array{keyword: string, property: string|null} $expected The keyword and property.
	 */
	public function testTheEntryNamesTheFailedRuleAndProperty(array $data, array $expected): void {
		$this->assertSame($expected, (new ErrorEntryIdentity())->of($this->error($data)));
	}//end testTheEntryNamesTheFailedRuleAndProperty()

	public function testAFailureOfTheDocumentItselfNamesNoProperty(): void {
		$result = (new Validator())->validate(json_decode('"text"'), json_decode('{"type":"object"}'));

		$this->assertSame(['keyword' => 'type', 'property' => null], (new ErrorEntryIdentity())->of($result->error()));
	}//end testAFailureOfTheDocumentItselfNamesNoProperty()

	/**
	 * The 400 entry carries the declared message and, next to it, the rule and property it is about.
	 */
	public function testTheResponseEntryKeepsKeywordAndPropertyNextToADeclaredMessage(): void {
		$error   = $this->error(['postcode' => 'ABCD']);
		$message = (new ValidationErrorMessages(language: 'nl'))->forResult(
			result: (new Validator())->validate(json_decode('{"postcode":"ABCD"}'), json_decode((string)json_encode(self::SCHEMA)))
		);
		$validator = (new ReflectionClass(ValidateObject::class))->newInstanceWithoutConstructor();

		$response = $validator->handleValidationException(new ValidationException(message: $message, errors: $error));
		$entry    = $response->getData()['errors'][0];

		$this->assertSame(400, $response->getStatus());
		$this->assertSame('Vul een postcode in zoals 1234 AB.', $entry['message']);
		$this->assertSame('pattern', $entry['keyword']);
		$this->assertSame('postcode', $entry['property']);
		$this->assertSame(['property', 'keyword', 'message', 'errors'], array_keys($entry));
	}//end testTheResponseEntryKeepsKeywordAndPropertyNextToADeclaredMessage()

	public function testAMessageOnlyExceptionNamesNeither(): void {
		$validator = (new ReflectionClass(ValidateObject::class))->newInstanceWithoutConstructor();
		$entry     = $validator->handleValidationException(new ValidationException(message: 'Property x is read-only'))->getData()['errors'][0];

		$this->assertNull($entry['keyword']);
		$this->assertNull($entry['property']);
		$this->assertSame([], $entry['errors']);
	}//end testAMessageOnlyExceptionNamesNeither()
}//end class
