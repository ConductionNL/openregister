<?php

/**
 * Unit tests for ExpressionDefaults, the rules engine's answer to the Core expression-defaults port.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Rules
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Rules;

use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Kernel\Contracts\Core\ExpressionDefaultsInterface;
use OCA\OpenRegister\Kernel\Contracts\Core\ExpressionDefaultRefusalInterface;
use OCA\OpenRegister\Service\Rules\ExpressionDefaultException;
use OCA\OpenRegister\Service\Rules\ExpressionDefaultResolver;
use OCA\OpenRegister\Service\Rules\ExpressionDefaults;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * The port derives a schema's expression defaults exactly as the save path did before.
 *
 * @covers \OCA\OpenRegister\Service\Rules\ExpressionDefaults
 * @uses   \OCA\OpenRegister\Service\Rules\ExpressionDefaultResolver
 * @uses   \OCA\OpenRegister\Service\Rules\ExpressionDefaultException
 * @uses   \OCA\OpenRegister\Service\Calculation\CalculationEvaluator
 * @uses   \OCA\OpenRegister\Search\Service\Search\PlaceholderResolvers
 * @uses   \OCA\OpenRegister\Search\Service\Search\PlaceholderResolver
 * @uses   \OCA\OpenRegister\Db\Schema
 */
final class ExpressionDefaultsTest extends TestCase {

	/**
	 * The port over a session nobody is logged in to.
	 *
	 * @return ExpressionDefaults
	 */
	private function defaults(): ExpressionDefaults {
		return new ExpressionDefaults(userSession: $this->createMock(IUserSession::class));
	}//end defaults()

	/**
	 * A schema with the given properties.
	 *
	 * @param array<string, mixed> $properties The properties block.
	 *
	 * @return Schema
	 */
	private static function schema(array $properties): Schema {
		$schema = new Schema();
		$schema->setProperties($properties);

		return $schema;
	}//end schema()

	/**
	 * A schema declaring a due date six weeks after receipt.
	 *
	 * @return Schema
	 */
	private static function sixWeeks(): Schema {
		return self::schema(
			[
				'ontvangstdatum' => ['type' => 'string', 'format' => 'date'],
				'uiterlijkeDatum' => [
					'type' => 'string',
					'format' => 'date',
					ExpressionDefaultResolver::ANNOTATION => ['dateAdd' => ['date' => ['prop' => 'ontvangstdatum'], 'amount' => 42, 'unit' => 'days']],
				],
			]
		);
	}//end sixWeeks()

	/**
	 * It answers the Core port.
	 *
	 * @return void
	 */
	public function testItIsTheCorePort(): void {
		$this->assertInstanceOf(ExpressionDefaultsInterface::class, $this->defaults());
	}//end testItIsTheCorePort()

	/**
	 * A declared default is derived onto the data.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function testADeclaredDefaultIsDerived(): void {
		$result = $this->defaults()->apply(schema: self::sixWeeks(), data: ['ontvangstdatum' => '2026-01-01']);

		$this->assertStringStartsWith('2026-02-12', (string) $result['uiterlijkeDatum']);
	}//end testADeclaredDefaultIsDerived()

	/**
	 * A schema without properties, or without any expression default, leaves the data as given.
	 *
	 * @return void
	 */
	public function testNoDeclarationLeavesTheDataAsGiven(): void {
		$data = ['title' => 'x'];

		$this->assertSame($data, $this->defaults()->apply(schema: self::schema([]), data: $data));
		$this->assertSame($data, $this->defaults()->apply(schema: self::schema(['title' => ['type' => 'string']]), data: $data));
	}//end testNoDeclarationLeavesTheDataAsGiven()

	/**
	 * An unevaluable default refuses, as before.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function testAnUnevaluableDefaultRefuses(): void {
		$this->expectException(ExpressionDefaultException::class);

		$this->defaults()->apply(schema: self::sixWeeks(), data: []);
	}//end testAnUnevaluableDefaultRefuses()

	/**
	 * The refusal is the Core refusal contract the object API answers with 422, its field error unchanged.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/objects-crud/spec.md
	 */
	public function testTheRefusalIsTheCoreRefusalWithItsFieldError(): void {
		$refusal = new ExpressionDefaultException(property: 'deadline', message: 'cannot derive');

		$this->assertInstanceOf(ExpressionDefaultRefusalInterface::class, $refusal);
		$this->assertSame(422, $refusal->getCode());
		$this->assertSame(
			['code' => 'default-expression-unevaluable', 'property' => 'deadline', 'message' => 'cannot derive'],
			$refusal->toArray()
		);
	}//end testTheRefusalIsTheCoreRefusalWithItsFieldError()
}//end class
