<?php

/**
 * HandoffDeclarationRefusalsTest
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Db\SchemaMapper
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db\SchemaMapper;

// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.
// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.

use Exception;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper\HandoffDeclarationRefusals;
use PHPUnit\Framework\TestCase;

/**
 * The hand-off annotation and contract binding refuse a schema save as they did inside ExchangeAnnotationRefusals.
 *
 * @covers \OCA\OpenRegister\Db\SchemaMapper\HandoffDeclarationRefusals
 */
class HandoffDeclarationRefusalsTest extends TestCase {

	/**
	 * A schema `meldingen` with one string property and this configuration.
	 *
	 * @param array<string, mixed> $configuration The configuration.
	 *
	 * @return Schema
	 */
	private static function schema(array $configuration): Schema {
		$schema = new Schema();
		$schema->setSlug('meldingen');
		$schema->setProperties(['titel' => ['type' => 'string']]);
		$schema->setConfiguration($configuration);

		return $schema;
	}//end schema()

	public function testASchemaWithoutEitherDeclarationPasses(): void {
		$refusals = new HandoffDeclarationRefusals();
		$refusals->checkHandoff(schema: self::schema([]));
		$refusals->checkHandoffContract(schema: self::schema([]));

		$this->addToAssertionCount(1);
	}//end testASchemaWithoutEitherDeclarationPasses()

	public function testAHandoffWithoutAMappingRefusesTheSaveWithItsCode(): void {
		$this->expectException(Exception::class);
		$this->expectExceptionMessage('x-openregister-handoff: handoff-');

		(new HandoffDeclarationRefusals())->checkHandoff(schema: self::schema(['x-openregister-handoff' => [['id' => 'send']]]));
	}//end testAHandoffWithoutAMappingRefusesTheSaveWithItsCode()

	public function testAHandoffWrittenAsAScalarIsNotChecked(): void {
		(new HandoffDeclarationRefusals())->checkHandoff(schema: self::schema(['x-openregister-handoff' => 'nonsense']));

		$this->addToAssertionCount(1);
	}//end testAHandoffWrittenAsAScalarIsNotChecked()

	public function testAnIncompleteContractBindingRefusesTheSave(): void {
		$this->expectException(Exception::class);
		$this->expectExceptionMessage('handoffContract: handoff-contract-incomplete: ');

		(new HandoffDeclarationRefusals())->checkHandoffContract(
			schema: self::schema(['implements' => ['https://openregister.app/ns#Case'], 'handoffContract' => ['https://openregister.app/ns#Case' => []]])
		);
	}//end testAnIncompleteContractBindingRefusesTheSave()
}//end class
