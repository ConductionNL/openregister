<?php

/**
 * SampleValidationErrors turns a validation failure into per-property rows.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Object
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://github.com/ConductionNL/openregister
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Object;

use OCA\OpenRegister\Exception\CustomValidationException;
use OCA\OpenRegister\Exception\ValidationException;
use OCA\OpenRegister\Service\Object\RetentionDestruction;
use OCA\OpenRegister\Service\Object\SampleValidationErrors;
use Opis\JsonSchema\Errors\ValidationError;
use Opis\JsonSchema\Validator;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Rows from a schema failure and from a custom rule failure, and the
 * retention-destruction run marker.
 *
 * @covers \OCA\OpenRegister\Service\Object\SampleValidationErrors
 * @covers \OCA\OpenRegister\Service\Object\RetentionDestruction
 * @uses   \OCA\OpenRegister\Exception\CustomValidationException
 * @uses   \OCA\OpenRegister\Exception\ValidationException
 */
class SampleValidationErrorsTest extends TestCase {

	/**
	 * The Opis error tree of validating data against a schema.
	 *
	 * @param mixed                $data   The data.
	 * @param array<string, mixed> $schema The JSON schema.
	 *
	 * @return ValidationError
	 */
	private function failure(mixed $data, array $schema): ValidationError {
		$error = (new Validator())->validate($data, json_encode($schema))->error();
		$this->assertNotNull($error);
		return $error;
	}//end failure()

	/**
	 * A schema failure gives a row for the property that failed.
	 *
	 * @return void
	 */
	public function testASchemaFailureGivesARowPerProperty(): void {
		$tree = $this->failure(
			data: (object) ['title' => 5, 'age' => 'old'],
			schema: ['type' => 'object', 'properties' => ['title' => ['type' => 'string'], 'age' => ['type' => 'integer']]]
		);

		$rows = SampleValidationErrors::fromValidation(new ValidationException(message: 'Invalid', errors: $tree));

		$this->assertSame(['title'], array_column($rows, 'property'));
		$this->assertSame(['schema'], array_column($rows, 'rule'));
		foreach ($rows as $row) {
			$this->assertNotSame('', $row['message']);
		}
	}//end testASchemaFailureGivesARowPerProperty()

	/**
	 * A failure at the root has no property.
	 *
	 * @return void
	 */
	public function testARootFailureHasNoProperty(): void {
		$tree = $this->failure(data: 'text', schema: ['type' => 'object']);

		$rows = SampleValidationErrors::fromValidation(new ValidationException(message: 'Invalid', errors: $tree));

		$this->assertSame([null], array_column($rows, 'property'));
		$this->assertSame('schema', $rows[0]['rule']);
	}//end testARootFailureHasNoProperty()

	/**
	 * Without an error tree the failure came from a write rule, and keeps its message.
	 *
	 * @return void
	 */
	public function testAFailureWithoutATreeIsAWriteRule(): void {
		$rows = SampleValidationErrors::fromValidation(new ValidationException(message: 'Status is locked'));

		$this->assertSame([['property' => null, 'message' => 'Status is locked', 'rule' => 'write-rule']], $rows);
	}//end testAFailureWithoutATreeIsAWriteRule()

	/**
	 * A custom failure gives a row per message; a uniqueness failure is marked as such.
	 *
	 * @return void
	 */
	public function testCustomFailuresKeepTheirPropertyAndRule(): void {
		$typed = SampleValidationErrors::fromCustom(
			new CustomValidationException(message: 'Wrong types', errors: ['age' => ['Not a number', 'Too big'], 'email' => 'Not an email'])
		);
		$unique = SampleValidationErrors::fromCustom(
			new CustomValidationException(message: 'Fields are not unique: code', errors: ['code' => 'Taken'])
		);

		$this->assertSame(
			[
				['property' => 'age', 'message' => 'Not a number', 'rule' => 'field-type'],
				['property' => 'age', 'message' => 'Too big', 'rule' => 'field-type'],
				['property' => 'email', 'message' => 'Not an email', 'rule' => 'field-type'],
			],
			$typed
		);
		$this->assertSame([['property' => 'code', 'message' => 'Taken', 'rule' => 'unique']], $unique);
	}//end testCustomFailuresKeepTheirPropertyAndRule()

	/**
	 * A custom failure without per-property errors keeps its message.
	 *
	 * @return void
	 */
	public function testACustomFailureWithoutErrorsKeepsItsMessage(): void {
		$rows = SampleValidationErrors::fromCustom(new CustomValidationException(message: 'Fields are not unique', errors: []));

		$this->assertSame([['property' => null, 'message' => 'Fields are not unique', 'rule' => 'unique']], $rows);
	}//end testACustomFailureWithoutErrorsKeepsItsMessage()

	/**
	 * The destruction marker is set only while an operation runs, also when it throws or nests.
	 *
	 * @return void
	 */
	public function testRetentionDestructionMarksOnlyWhileRunning(): void {
		$destruction = new RetentionDestruction();
		$this->assertFalse($destruction->isRunning());

		$seen = $destruction->run(
			static fn (): array => [$destruction->isRunning(), $destruction->run(static fn (): bool => $destruction->isRunning())]
		);
		$this->assertSame([true, true], $seen);
		$this->assertFalse($destruction->isRunning());

		try {
			$destruction->run(static function (): void {
				throw new RuntimeException('failed');
			});
			$this->fail('The failure should have been rethrown.');
		} catch (RuntimeException $e) {
			$this->assertSame('failed', $e->getMessage());
		}

		$this->assertFalse($destruction->isRunning());
	}//end testRetentionDestructionMarksOnlyWhileRunning()
}//end class
