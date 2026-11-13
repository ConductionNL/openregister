<?php

/**
 * ExpiryDeclaration: per-object action, anonymisation and the @self.expires stamp.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Retention
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

namespace OCA\OpenRegister\Tests\Unit\Service\Retention;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Exception\ValidationException;
use OCA\OpenRegister\Service\Retention\ExpiryDeclaration;
use PHPUnit\Framework\TestCase;

/**
 * The action an object gets, what an anonymisation leaves, and that
 * `@self.expires` is stored for an opted-in schema and ignored for another.
 *
 * @covers \OCA\OpenRegister\Service\Retention\ExpiryDeclaration
 * @uses   \OCA\OpenRegister\Db\Schema
 * @uses   \OCA\OpenRegister\Db\ObjectEntity
 * @uses   \OCA\OpenRegister\Exception\ValidationException
 */
final class ExpiryDeclarationTest extends TestCase {

	/**
	 * A submission schema, opted in unless $block is null.
	 *
	 * @param array|null $block The expiry block.
	 *
	 * @return Schema
	 */
	private static function schema(?array $block=['action' => 'anonymise', 'keep' => ['reference', 'state'], 'actionField' => 'retentionMethod']): Schema {
		$schema = new Schema();
		$schema->setProperties(['reference' => [], 'state' => [], 'answers' => [], 'files' => [], 'retentionMethod' => [], 'note' => []]);
		$schema->setConfiguration($block === null ? [] : ['x-openregister' => ['expiry' => $block]]);
		return $schema;
	}//end schema()

	/**
	 * The object's action field overrides the default when it names an action.
	 *
	 * @return void
	 */
	public function testTheActionFieldDecidesPerObject(): void {
		$declaration = ExpiryDeclaration::of(schema: self::schema());
		$this->assertNotNull($declaration);
		$this->assertSame('delete', $declaration->actionFor(data: ['retentionMethod' => 'delete']));
		$this->assertSame('anonymise', $declaration->actionFor(data: ['retentionMethod' => 'shred']));
		$this->assertSame('anonymise', $declaration->actionFor(data: []));

		$noField = ExpiryDeclaration::of(schema: self::schema(['action' => 'delete']));
		$this->assertSame('delete', $noField?->actionFor(data: ['retentionMethod' => 'anonymise']));
		$this->assertNull($noField?->actionField);

	}//end testTheActionFieldDecidesPerObject()

	/**
	 * Kept properties stay; others are emptied by kind.
	 *
	 * @return void
	 */
	public function testAnonymisationKeepsOnlyTheNamedProperties(): void {
		$declaration = ExpiryDeclaration::of(schema: self::schema());
		$this->assertSame(
			['id' => 'u-1', 'reference' => 'PT-1', 'state' => 'failed', 'answers' => [], 'files' => [], 'note' => null, 'retentionMethod' => null, '@self' => ['owner' => 'x']],
			$declaration?->anonymised(data: ['id' => 'u-1', 'reference' => 'PT-1', 'state' => 'failed', 'answers' => ['name' => 'Jan'], 'files' => ['a.pdf'], 'note' => 'call back', 'retentionMethod' => 'anonymise', '@self' => ['owner' => 'x']])
		);

	}//end testAnonymisationKeepsOnlyTheNamedProperties()

	/**
	 * An opted-in schema stores the requested expiry, and an empty value clears it.
	 *
	 * @return void
	 */
	public function testAnOptedInSchemaStoresTheExpiry(): void {
		$object = new ObjectEntity();
		ExpiryDeclaration::stampRequestedExpiry(schema: self::schema(), object: $object, selfData: ['expires' => '2026-12-13T10:00:00+00:00']);
		$this->assertSame('2026-12-13T10:00:00+00:00', $object->getExpires()?->format(DATE_ATOM));

		ExpiryDeclaration::stampRequestedExpiry(schema: self::schema(), object: $object, selfData: ['owner' => 'x']);
		$this->assertNotNull($object->getExpires());

		ExpiryDeclaration::stampRequestedExpiry(schema: self::schema(), object: $object, selfData: ['expires' => '']);
		$this->assertNull($object->getExpires());

	}//end testAnOptedInSchemaStoresTheExpiry()

	/**
	 * Another schema ignores `@self.expires`, as before.
	 *
	 * @return void
	 */
	public function testAnotherSchemaIgnoresTheExpiry(): void {
		$object = new ObjectEntity();
		ExpiryDeclaration::stampRequestedExpiry(schema: self::schema(null), object: $object, selfData: ['expires' => '2026-12-13T10:00:00+00:00']);
		$this->assertNull($object->getExpires());

		ExpiryDeclaration::stampRequestedExpiry(schema: self::schema(['action' => 'remove']), object: $object, selfData: ['expires' => '2026-12-13']);
		$this->assertNull($object->getExpires());

	}//end testAnotherSchemaIgnoresTheExpiry()

	/**
	 * A value that is not a date is refused, not dropped.
	 *
	 * @return void
	 */
	public function testANonDateIsRefused(): void {
		foreach (['next month', '13/12/2026', 42, '2026-13-45'] as $value) {
			try {
				ExpiryDeclaration::stampRequestedExpiry(schema: self::schema(), object: new ObjectEntity(), selfData: ['expires' => $value]);
				$this->fail('expected a refusal for '.var_export($value, true));
			} catch (ValidationException $e) {
				$this->assertStringStartsWith('@self.expires must be a date and time', $e->getMessage());
			}
		}

	}//end testANonDateIsRefused()
}//end class
