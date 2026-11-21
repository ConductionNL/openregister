<?php

/**
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/modelling-composite-identity/specs/objects-crud/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db\SchemaMapper;

// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use Exception;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper\IdentityDeclarationCheck;
use PHPUnit\Framework\TestCase;

/**
 * The schema save refuses an identity it cannot keep, as an "Invalid" declaration naming the constraint.
 *
 * @covers \OCA\OpenRegister\Db\SchemaMapper\IdentityDeclarationCheck
 * @uses \OCA\OpenRegister\Db\SchemaMapper\AnnotationFaults
 * @uses \OCA\OpenRegister\Service\Schemas\UniqueConstraintEvaluator
 * @uses \OCA\OpenRegister\Service\Schemas\SchemaDeclarationValidators
 * @uses \OCA\OpenRegister\Db\Schema
 * @uses \OCA\OpenRegister\Db\Schema\ConfigurationEntryRules
 * @uses \OCA\OpenRegister\Db\Schema\ConfigurationValidator
 */
final class IdentityDeclarationCheckTest extends TestCase {

	/**
	 * A schema with the given constraints.
	 *
	 * @param array<int,array<string,mixed>> $constraints The declared constraints.
	 *
	 * @return Schema
	 */
	private static function schema(array $constraints): Schema {
		$schema = new Schema();
		$schema->setConfiguration(['uniqueConstraints' => $constraints]);

		return $schema;
	}//end schema()

	public function testAnIdentityOnAReportConstraintIsRefused(): void {
		$this->expectException(Exception::class);
		$this->expectExceptionMessage('Invalid uniqueConstraints declaration: [identity-on-report] The constraint "mail"');

		(new IdentityDeclarationCheck())->check(schema: self::schema([['name' => 'mail', 'properties' => ['email'], 'action' => 'report', 'identity' => true]]));
	}//end testAnIdentityOnAReportConstraintIsRefused()

	public function testASecondIdentityIsRefused(): void {
		$this->expectException(Exception::class);
		$this->expectExceptionMessage('[identity-declared-twice] The constraint "b"');

		(new IdentityDeclarationCheck())->check(
			schema: self::schema(
				[
					['name' => 'a', 'properties' => ['x'], 'action' => 'refuse', 'identity' => true],
					['name' => 'b', 'properties' => ['y'], 'action' => 'refuse', 'identity' => true],
				]
			)
		);
	}//end testASecondIdentityIsRefused()

	public function testOneIdentityIsSaved(): void {
		$schema = self::schema([['name' => 'zaaksleutel', 'properties' => ['gemeentecode', 'zaaknummer'], 'action' => 'refuse', 'identity' => true]]);

		(new IdentityDeclarationCheck())->check(schema: $schema);

		$this->assertTrue($schema->getConfiguration()['uniqueConstraints'][0]['identity']);
	}//end testOneIdentityIsSaved()
}//end class
