<?php

/**
 * Golden test for {@see \OCA\OpenRegister\Service\Form\FormPayloadFindings}.
 *
 * Pins the exact findings for opis validation results (nested, required,
 * valid) and for every save-path exception dialect, each with and without a
 * named write, plus `isPayloadRefusal()`. Written against the code before its
 * refactor and left unchanged by it.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Form
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Form;

use OCA\OpenRegister\Exception\CustomValidationException;
use OCA\OpenRegister\Exception\DuplicateBlockedException;
use OCA\OpenRegister\Exception\HookStoppedException;
use OCA\OpenRegister\Exception\ObjectExistsException;
use OCA\OpenRegister\Exception\ValidationException;
use OCA\OpenRegister\Service\Form\FormPayloadFindings;
use Opis\JsonSchema\ValidationResult;
use Opis\JsonSchema\Validator;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * @no-named-arguments
 */
class FormPayloadFindingsGoldenTest extends TestCase {

	/**
	 * @param array<string, mixed> $data The payload.
	 *
	 * @return ValidationResult
	 */
	private static function opisResult(array $data): ValidationResult {
		$schema = json_decode(
			(string) json_encode(
				[
					'type' => 'object',
					'required' => ['title', 'caseType'],
					'properties' => [
						'title' => ['type' => 'string', 'maxLength' => 5],
						'caseType' => ['type' => 'string'],
						'amount' => ['type' => 'integer'],
						'address' => [
							'type' => 'object',
							'required' => ['street', 'city'],
							'properties' => ['street' => ['type' => 'string'], 'zip' => ['type' => 'string', 'pattern' => '^[0-9]{4}$']],
						],
					],
				]
			)
		);
		$validator = new Validator();
		$validator->setMaxErrors(10);

		return $validator->validate(json_decode((string) json_encode($data)), $schema);
	}//end opisResult()

	/**
	 * @return void
	 */
	public function testFindings(): void {
		$findings = new FormPayloadFindings();
		$results = [
			'valid' => self::opisResult(['title' => 'abc', 'caseType' => 'x']),
			'missing two' => self::opisResult([]),
			'nested' => self::opisResult(['title' => 'too long', 'caseType' => 'x', 'amount' => 'ten', 'address' => ['zip' => 'abc']]),
		];
		$exceptions = [
			'validation with errors' => new ValidationException('bad', 0, null, self::opisResult(['caseType' => 1])->error()),
			'validation without errors' => new ValidationException('plain validation'),
			'custom' => new CustomValidationException('Invalid', ['title' => 'Too short', 'list' => ['property' => 'amount', 'message' => 'Not a number'], 3 => ['field' => 'zip', 'error' => 'Wrong'], 'other' => ['x' => 1], 4 => 'positional']),
			'custom unique' => new CustomValidationException('Value is NOT UNIQUE here', ['title' => 'Taken']),
			'hook' => new HookStoppedException('stopped', ['reason' => 'Closed', 0 => ['property' => 'p', 'message' => 'm']]),
			'hook empty' => new HookStoppedException('stopped'),
			'duplicate' => new DuplicateBlockedException(),
			'exists' => new ObjectExistsException('Exists already'),
			'other' => new RuntimeException('Boom'),
		];

		$actual = [];
		foreach ([null, 'contact'] as $write) {
			foreach ($results as $name => $result) {
				$actual[($write ?? '-').'|'.$name] = $findings->fromResult($result, $write);
			}

			foreach ($exceptions as $name => $exception) {
				$actual[($write ?? '-').'|'.$name] = $findings->fromThrowable($exception, $write);
			}
		}

		foreach ($exceptions as $name => $exception) {
			$actual['refusal|'.$name] = $findings->isPayloadRefusal($exception);
		}

		$this->assertSame(self::expected(), $actual);
	}//end testFindings()

	/**
	 * @return array<string, mixed>
	 */
	private static function expected(): array {
		return [
			'-|valid' => [],
			'-|missing two' => [
				[
					'property' => '',
					'code' => 'type',
					'message' => 'The data (array) must match the type: object',
				],
			],
			'-|nested' => [
				[
					'property' => 'title',
					'code' => 'maxLength',
					'message' => 'Maximum string length is 5, found 8',
				],
				[
					'property' => 'amount',
					'code' => 'type',
					'message' => 'The data (string) must match the type: integer',
				],
				[
					'property' => 'address.street',
					'code' => 'required',
					'message' => 'The required properties (street, city) are missing',
				],
				[
					'property' => 'address.city',
					'code' => 'required',
					'message' => 'The required properties (street, city) are missing',
				],
			],
			'-|validation with errors' => [
				[
					'property' => 'title',
					'code' => 'required',
					'message' => 'The required properties (title) are missing',
				],
			],
			'-|validation without errors' => [
				[
					'property' => '',
					'code' => 'invalid',
					'message' => 'plain validation',
				],
			],
			'-|custom' => [
				[
					'property' => 'title',
					'code' => 'invalid',
					'message' => 'Too short',
				],
				[
					'property' => 'amount',
					'code' => 'invalid',
					'message' => 'Not a number',
				],
				[
					'property' => 'zip',
					'code' => 'invalid',
					'message' => 'Wrong',
				],
				[
					'property' => 'other',
					'code' => 'invalid',
					'message' => '{"x":1}',
				],
				[
					'property' => '',
					'code' => 'invalid',
					'message' => 'positional',
				],
			],
			'-|custom unique' => [
				[
					'property' => 'title',
					'code' => 'unique',
					'message' => 'Taken',
				],
			],
			'-|hook' => [
				[
					'property' => 'reason',
					'code' => 'refused',
					'message' => 'Closed',
				],
				[
					'property' => 'p',
					'code' => 'refused',
					'message' => 'm',
				],
			],
			'-|hook empty' => [],
			'-|duplicate' => [
				[
					'property' => '',
					'code' => 'duplicate',
					'message' => 'This object duplicates one that already exists.',
				],
			],
			'-|exists' => [
				[
					'property' => '',
					'code' => 'duplicate',
					'message' => 'Exists already',
				],
			],
			'-|other' => [
				[
					'property' => '',
					'code' => 'invalid',
					'message' => 'Boom',
				],
			],
			'contact|valid' => [],
			'contact|missing two' => [
				[
					'property' => '',
					'code' => 'type',
					'message' => 'The data (array) must match the type: object',
					'write' => 'contact',
				],
			],
			'contact|nested' => [
				[
					'property' => 'title',
					'code' => 'maxLength',
					'message' => 'Maximum string length is 5, found 8',
					'write' => 'contact',
				],
				[
					'property' => 'amount',
					'code' => 'type',
					'message' => 'The data (string) must match the type: integer',
					'write' => 'contact',
				],
				[
					'property' => 'address.street',
					'code' => 'required',
					'message' => 'The required properties (street, city) are missing',
					'write' => 'contact',
				],
				[
					'property' => 'address.city',
					'code' => 'required',
					'message' => 'The required properties (street, city) are missing',
					'write' => 'contact',
				],
			],
			'contact|validation with errors' => [
				[
					'property' => 'title',
					'code' => 'required',
					'message' => 'The required properties (title) are missing',
					'write' => 'contact',
				],
			],
			'contact|validation without errors' => [
				[
					'property' => '',
					'code' => 'invalid',
					'message' => 'plain validation',
					'write' => 'contact',
				],
			],
			'contact|custom' => [
				[
					'property' => 'title',
					'code' => 'invalid',
					'message' => 'Too short',
					'write' => 'contact',
				],
				[
					'property' => 'amount',
					'code' => 'invalid',
					'message' => 'Not a number',
					'write' => 'contact',
				],
				[
					'property' => 'zip',
					'code' => 'invalid',
					'message' => 'Wrong',
					'write' => 'contact',
				],
				[
					'property' => 'other',
					'code' => 'invalid',
					'message' => '{"x":1}',
					'write' => 'contact',
				],
				[
					'property' => '',
					'code' => 'invalid',
					'message' => 'positional',
					'write' => 'contact',
				],
			],
			'contact|custom unique' => [
				[
					'property' => 'title',
					'code' => 'unique',
					'message' => 'Taken',
					'write' => 'contact',
				],
			],
			'contact|hook' => [
				[
					'property' => 'reason',
					'code' => 'refused',
					'message' => 'Closed',
					'write' => 'contact',
				],
				[
					'property' => 'p',
					'code' => 'refused',
					'message' => 'm',
					'write' => 'contact',
				],
			],
			'contact|hook empty' => [],
			'contact|duplicate' => [
				[
					'property' => '',
					'code' => 'duplicate',
					'message' => 'This object duplicates one that already exists.',
					'write' => 'contact',
				],
			],
			'contact|exists' => [
				[
					'property' => '',
					'code' => 'duplicate',
					'message' => 'Exists already',
					'write' => 'contact',
				],
			],
			'contact|other' => [
				[
					'property' => '',
					'code' => 'invalid',
					'message' => 'Boom',
					'write' => 'contact',
				],
			],
			'refusal|validation with errors' => true,
			'refusal|validation without errors' => true,
			'refusal|custom' => true,
			'refusal|custom unique' => true,
			'refusal|hook' => true,
			'refusal|hook empty' => true,
			'refusal|duplicate' => true,
			'refusal|exists' => true,
			'refusal|other' => false,
		];
	}//end expected()
}//end class
