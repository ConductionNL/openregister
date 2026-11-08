<?php

/**
 * Golden test pinning the URI, UUID and Email GraphQL scalars.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Search\Service\GraphQL
 *
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Search\Service\GraphQL;

use GraphQL\Error\Error;
use GraphQL\Language\AST\IntValueNode;
use GraphQL\Language\AST\StringValueNode;
use GraphQL\Type\Definition\ScalarType;
use OCA\OpenRegister\Search\Service\GraphQL\Scalar\EmailType;
use OCA\OpenRegister\Search\Service\GraphQL\Scalar\UriType;
use OCA\OpenRegister\Search\Service\GraphQL\Scalar\UuidType;
use PHPUnit\Framework\TestCase;

/**
 * Pins name, description, accepted values and every error message.
 *
 * @covers \OCA\OpenRegister\Search\Service\GraphQL\Scalar\EmailType
 * @covers \OCA\OpenRegister\Search\Service\GraphQL\Scalar\UriType
 * @covers \OCA\OpenRegister\Search\Service\GraphQL\Scalar\UuidType
 */
final class StringScalarsGoldenTest extends TestCase {

	/**
	 * Each scalar with its valid value, invalid value and messages.
	 *
	 * @return array<string, array{ScalarType, string, string, string, string, string, string}>
	 */
	public static function scalars(): array {
		return [
			'uri' => [
				new UriType(),
				'URI',
				'A valid URI string (RFC 3986).',
				'https://example.com/a?b=1',
				'not a uri',
				'URI cannot represent non-string value',
				'URI cannot represent invalid URI value: not a uri',
			],
			'uuid' => [
				new UuidType(),
				'UUID',
				'UUID v4 string (e.g., 550e8400-e29b-41d4-a716-446655440000).',
				'550e8400-e29b-41d4-a716-446655440000',
				'550e8400',
				'UUID cannot represent non-string value',
				'UUID cannot represent invalid UUID value: 550e8400',
			],
			'email' => [
				new EmailType(),
				'Email',
				'RFC 5321 email address string.',
				'someone@example.com',
				'someone@',
				'Email cannot represent non-string value',
				'Email cannot represent invalid email value: someone@',
			],
		];
	}//end scalars()

	/**
	 * Name, description and the accepted value on every entry point.
	 *
	 * @param ScalarType $type        The scalar.
	 * @param string     $name        Its name.
	 * @param string     $description Its description.
	 * @param string     $valid       A value it accepts.
	 *
	 * @return void
	 *
	 * @dataProvider scalars
	 */
	public function testAccepts(ScalarType $type, string $name, string $description, string $valid): void {
		$this->assertSame($name, $type->name);
		$this->assertSame($description, $type->description);
		$this->assertSame($valid, $type->serialize($valid));
		$this->assertSame($valid, $type->parseValue($valid));
		$this->assertSame($valid, $type->parseLiteral(new StringValueNode(['value' => $valid])));
	}//end testAccepts()

	/**
	 * Serialize accepts any string, valid or not.
	 *
	 * @param ScalarType $type        The scalar.
	 * @param string     $name        Its name.
	 * @param string     $description Its description.
	 * @param string     $valid       A value it accepts.
	 * @param string     $invalid     A string it refuses on input.
	 *
	 * @return void
	 *
	 * @dataProvider scalars
	 */
	public function testSerializeDoesNotValidate(
		ScalarType $type,
		string $name,
		string $description,
		string $valid,
		string $invalid
	): void {
		$this->assertSame($invalid, $type->serialize($invalid));
	}//end testSerializeDoesNotValidate()

	/**
	 * The exact message of every refusal.
	 *
	 * @param ScalarType $type        The scalar.
	 * @param string     $name        Its name.
	 * @param string     $description Its description.
	 * @param string     $valid       A value it accepts.
	 * @param string     $invalid     A string it refuses on input.
	 * @param string     $nonString   The non-string message prefix.
	 * @param string     $invalidMsg  The invalid-value message.
	 *
	 * @return void
	 *
	 * @dataProvider scalars
	 */
	public function testRefusals(
		ScalarType $type,
		string $name,
		string $description,
		string $valid,
		string $invalid,
		string $nonString,
		string $invalidMsg
	): void {
		$this->assertSame($nonString . ': 42', $this->message(fn () => $type->serialize(42)));
		$this->assertSame($nonString . ': []', $this->message(fn () => $type->parseValue([])));
		$this->assertSame($invalidMsg, $this->message(fn () => $type->parseValue($invalid)));
		$this->assertSame($invalidMsg, $this->message(fn () => $type->parseLiteral(new StringValueNode(['value' => $invalid]))));
		$this->assertSame($nonString, $this->message(fn () => $type->parseLiteral(new IntValueNode(['value' => '1']))));
	}//end testRefusals()

	/**
	 * The message of the GraphQL error a call throws.
	 *
	 * @param callable $call The call.
	 *
	 * @return string The message.
	 */
	private function message(callable $call): string {
		try {
			$call();
		} catch (Error $e) {
			return $e->getMessage();
		}

		$this->fail('No GraphQL error was thrown.');
	}//end message()
}//end class
