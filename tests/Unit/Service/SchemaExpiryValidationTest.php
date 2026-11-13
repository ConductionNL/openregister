<?php

/**
 * Schema save validation of x-openregister.expiry.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/expired-object-sweep/specs/expired-object-sweep/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service;

use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper\RecordAnnotationRefusals;
use OCA\OpenRegister\Service\Retention\ExpiryDeclaration;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * REQ-EXP-001: a schema save with a valid expiry block passes; an unknown
 * action or a kept property the schema lacks is refused, naming the fault.
 *
 * @covers \OCA\OpenRegister\Db\SchemaMapper\RecordAnnotationRefusals
 * @covers \OCA\OpenRegister\Service\Retention\ExpiryDeclaration
 * @uses   \OCA\OpenRegister\Db\Schema
 * @uses   \OCA\OpenRegister\Db\SchemaMapper\SchemaAnnotation
 * @uses   \OCA\OpenRegister\Db\SchemaMapper\AnnotationFaults
 */
final class SchemaExpiryValidationTest extends TestCase {

	/**
	 * A schema with three properties and the given expiry block.
	 *
	 * @param mixed $expiry The block, or null for none.
	 *
	 * @return Schema
	 */
	private static function schema(mixed $expiry): Schema {
		$schema = new Schema();
		$schema->setSlug('portalIntakeSubmission');
		$schema->setProperties(['reference' => ['type' => 'string'], 'answers' => ['type' => 'object'], 'retentionMethod' => ['type' => 'string']]);
		if ($expiry !== null) {
			$schema->setConfiguration([ExpiryDeclaration::ANNOTATION => [ExpiryDeclaration::KEY => $expiry]]);
		}

		return $schema;
	}//end schema()

	/**
	 * The save check.
	 *
	 * @return RecordAnnotationRefusals
	 */
	private function refusals(): RecordAnnotationRefusals {
		return new RecordAnnotationRefusals(logger: $this->createMock(LoggerInterface::class));
	}//end refusals()

	/**
	 * A good block and an absent block both save.
	 *
	 * @return void
	 */
	public function testAGoodOrAbsentBlockSaves(): void {
		$this->refusals()->checkExpiry(schema: self::schema(['action' => 'anonymise', 'keep' => ['reference'], 'actionField' => 'retentionMethod']));
		$this->refusals()->checkExpiry(schema: self::schema(['action' => 'delete']));
		$this->refusals()->checkExpiry(schema: self::schema(null));

		$this->assertSame('anonymise', ExpiryDeclaration::of(schema: self::schema(['action' => 'anonymise', 'keep' => ['reference']]))?->action);

	}//end testAGoodOrAbsentBlockSaves()

	/**
	 * An unknown action is refused naming the allowed actions.
	 *
	 * @return void
	 */
	public function testAnUnknownActionIsRefused(): void {
		$this->expectException(\Exception::class);
		$this->expectExceptionMessage('x-openregister: expiry.action "remove" is not allowed; use one of: delete, anonymise.');

		$this->refusals()->checkExpiry(schema: self::schema(['action' => 'remove']));

	}//end testAnUnknownActionIsRefused()

	/**
	 * A kept property the schema lacks is refused.
	 *
	 * @return void
	 */
	public function testAnUnknownKeptPropertyIsRefused(): void {
		$this->expectException(\Exception::class);
		$this->expectExceptionMessage('expiry.keep names "binding", which is not a property of this schema.');

		$this->refusals()->checkExpiry(schema: self::schema(['action' => 'anonymise', 'keep' => ['reference', 'binding']]));

	}//end testAnUnknownKeptPropertyIsRefused()

	/**
	 * Every other malformed block is named.
	 *
	 * @return void
	 */
	public function testMalformedBlocksAreNamed(): void {
		$properties = ['reference' => []];
		$this->assertSame(['expiry must be an object with an action.'], ExpiryDeclaration::faults(block: 'delete', properties: $properties));
		$this->assertSame(['expiry.keep must be a list of property names.'], ExpiryDeclaration::faults(block: ['action' => 'delete', 'keep' => 'reference'], properties: $properties));
		$this->assertSame(
			['expiry.actionField names "method", which is not a property of this schema.'],
			ExpiryDeclaration::faults(block: ['action' => 'delete', 'actionField' => 'method'], properties: $properties)
		);
		$this->assertSame(
			['expiry.action "NULL" is not allowed; use one of: delete, anonymise.', 'expiry.keep names "3", which is not a property of this schema.'],
			ExpiryDeclaration::faults(block: ['keep' => [3]], properties: $properties)
		);
		$this->assertSame([], ExpiryDeclaration::faults(block: null, properties: $properties));
		$this->assertNull(ExpiryDeclaration::of(schema: self::schema(['action' => 'remove'])));
		$this->assertNull(ExpiryDeclaration::rawBlock(configuration: ['x-openregister' => 'shared']));

	}//end testMalformedBlocksAreNamed()
}//end class
