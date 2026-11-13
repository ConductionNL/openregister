<?php

/**
 * Unit tests for putting an object's identity key on its @self block.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Object
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/modelling-composite-identity/specs/objects-crud/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Object;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\Object\RecordAnnotations;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * `@self.key` carries the identity values in declared order; no identity or a missing value, no key.
 *
 * @covers \OCA\OpenRegister\Service\Object\RecordAnnotations
 * @uses \OCA\OpenRegister\Db\ObjectEntity
 * @uses \OCA\OpenRegister\Db\Schema
 * @uses \OCA\OpenRegister\Db\Schema\ConfigurationEntryRules
 * @uses \OCA\OpenRegister\Db\Schema\ConfigurationValidator
 * @uses \OCA\OpenRegister\Service\Schemas\UniqueConstraintEvaluator
 */
class RecordAnnotationsKeyTest extends TestCase {

	/**
	 * A schema whose constraint `zaaksleutel` over municipality code and case number may be the identity.
	 *
	 * @param bool $identity Whether the constraint is declared the identity.
	 *
	 * @return Schema
	 */
	private function schema(bool $identity): Schema {
		$schema = new Schema();
		$schema->setConfiguration(
			[
				'uniqueConstraints' => [
					[
						'name' => 'zaaksleutel',
						'properties' => ['gemeentecode', 'zaaknummer'],
						'action' => 'refuse',
						'identity' => $identity,
					],
				],
			]
		);

		return $schema;

	}//end schema()

	/**
	 * An entity carrying the given object data.
	 *
	 * @param array<string, mixed> $data The object data.
	 *
	 * @return ObjectEntity
	 */
	private function entity(array $data): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid('5b1f3c1e-0000-4000-8000-000000000001');
		$entity->setObject($data);

		return $entity;

	}//end entity()

	/**
	 * The key joins the identity values in declared order and lands in @self.key.
	 *
	 * @return void
	 */
	public function testTheKeyJoinsTheIdentityValuesInDeclaredOrder(): void {
		$entity = $this->entity(['zaaknummer' => 'Z-2026-0042', 'gemeentecode' => '0363', 'titel' => 'Bouw']);

		(new RecordAnnotations(logger: new NullLogger()))->attachKey(entity: $entity, schema: $this->schema(identity: true));

		self::assertSame('0363:Z-2026-0042', $entity->getIdentityKey());
		self::assertSame('0363:Z-2026-0042', $entity->jsonSerialize()['@self']['key']);

	}//end testTheKeyJoinsTheIdentityValuesInDeclaredOrder()

	/**
	 * A uniqueness constraint that is not the identity gives no key, and @self carries none.
	 *
	 * @return void
	 */
	public function testAConstraintThatIsNotTheIdentityGivesNoKey(): void {
		$entity = $this->entity(['gemeentecode' => '0363', 'zaaknummer' => 'Z-2026-0042']);

		(new RecordAnnotations(logger: new NullLogger()))->attachKey(entity: $entity, schema: $this->schema(identity: false));

		self::assertNull($entity->getIdentityKey());
		self::assertArrayNotHasKey('key', $entity->jsonSerialize()['@self']);

	}//end testAConstraintThatIsNotTheIdentityGivesNoKey()

	/**
	 * An object missing one identity value, as when the reader may not see it, gets no key.
	 *
	 * @return void
	 */
	public function testAMissingIdentityValueGivesNoKey(): void {
		$entity = $this->entity(['gemeentecode' => '0363']);

		(new RecordAnnotations(logger: new NullLogger()))->attachKey(entity: $entity, schema: $this->schema(identity: true));

		self::assertNull($entity->getIdentityKey());

	}//end testAMissingIdentityValueGivesNoKey()

	/**
	 * Without a schema nothing is attached.
	 *
	 * @return void
	 */
	public function testNoSchemaGivesNoKey(): void {
		$entity = $this->entity(['gemeentecode' => '0363', 'zaaknummer' => 'Z-2026-0042']);

		(new RecordAnnotations(logger: new NullLogger()))->attachKey(entity: $entity, schema: null);

		self::assertNull($entity->getIdentityKey());

	}//end testNoSchemaGivesNoKey()
}//end class
