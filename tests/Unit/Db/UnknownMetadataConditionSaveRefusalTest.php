<?php

/**
 * Saving a rule conditioned on metadata no record carries is refused.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Db
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/row-field-level-security/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db;

use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Exception\AuthorizationBlockException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IDBConnection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionProperty;

/**
 * The schema and register saves refuse an unknown metadata condition, naming the key and the rule.
 *
 * @covers \OCA\OpenRegister\Db\SchemaMapper
 * @covers \OCA\OpenRegister\Db\RegisterMapper
 * @covers \OCA\OpenRegister\Service\Rbac\MetadataConditionFindings
 * @uses \OCA\OpenRegister\Service\Rbac\StoredRuleBlocks
 * @covers \OCA\OpenRegister\Service\Rbac\RecordMetadataConditions
 * @covers \OCA\OpenRegister\Service\Rbac\RuleColumns
 * @covers \OCA\OpenRegister\Service\Rbac\UnknownRuleKeyFindings
 * @uses   \OCA\OpenRegister\Db\ObjectEntity
 * @uses   \OCA\OpenRegister\Db\Register
 * @uses   \OCA\OpenRegister\Db\Schema
 * @uses   \OCA\OpenRegister\Event\PermissionsDeclaringEvent
 * @uses   \OCA\OpenRegister\Exception\AuthorizationBlockException
 * @uses   \OCA\OpenRegister\Service\Rbac\AuthorizationDenyValidator
 * @uses   \OCA\OpenRegister\Service\Rbac\DenyEntryMatcher
 * @uses   \OCA\OpenRegister\Service\Rbac\DenyResolver
 * @uses   \OCA\OpenRegister\Service\Rbac\PermissionCatalogue
 * @uses \OCA\OpenRegister\Db\SchemaMapper\AuthorizationBlockCheck
 */
class UnknownMetadataConditionSaveRefusalTest extends TestCase {

	/**
	 * Run a mapper's save-time check, without a database behind it.
	 *
	 * @param string              $mapperClass The mapper.
	 * @param Schema|Register     $entity      The entity being saved.
	 * @param array<string, string>|null $row  The stored row the query answers with, or null for none.
	 *
	 * @return void
	 */
	private function validateAsTheSaveDoes(string $mapperClass, Schema|Register $entity, ?array $row = null): void {
		$reflection = new ReflectionClass($mapperClass);
		$mapper = $reflection->newInstanceWithoutConstructor();

		$dispatcher = $reflection->getProperty('eventDispatcher');
		$dispatcher->setAccessible(true);
		$dispatcher->setValue($mapper, $this->createMock(originalClassName: IEventDispatcher::class));

		$result = $this->createMock(originalClassName: IResult::class);
		$result->method('fetch')->willReturn($row ?? false);
		$queryBuilder = $this->createMock(originalClassName: IQueryBuilder::class);
		foreach (['select', 'from', 'where'] as $step) {
			$queryBuilder->method($step)->willReturnSelf();
		}

		$queryBuilder->method('expr')->willReturn($this->createMock(originalClassName: IExpressionBuilder::class));
		$queryBuilder->method('executeQuery')->willReturn($result);
		$connection = $this->createMock(originalClassName: IDBConnection::class);
		$connection->method('getQueryBuilder')->willReturn($queryBuilder);
		$db = new ReflectionProperty(QBMapper::class, 'db');
		$db->setAccessible(true);
		$db->setValue($mapper, $connection);

		$method = $reflection->getMethod('validateAuthorizationDeny');
		$method->setAccessible(true);
		$method->invoke($mapper, $entity);
	}//end validateAsTheSaveDoes()

	/**
	 * Run the save-time check and assert the save goes through: the check
	 * raises no AuthorizationBlockException. Each caller's refusal pair flips
	 * one input (an added rule, an unknown key) and expects the exception.
	 *
	 * @param string                     $mapperClass The mapper.
	 * @param Schema|Register            $entity      The entity being saved.
	 * @param array<string, string>|null $row         The stored row, or null for none.
	 *
	 * @return void
	 */
	private function assertSaveAccepted(string $mapperClass, Schema|Register $entity, ?array $row = null): void {
		$refusal = null;
		try {
			$this->validateAsTheSaveDoes($mapperClass, $entity, $row);
		} catch (AuthorizationBlockException $exception) {
			$refusal = $exception;
		}

		$this->assertNull($refusal, 'save refused: '.($refusal?->getMessage() ?? ''));
	}//end assertSaveAccepted()

	/**
	 * A stored rule on unknown metadata does not block a save that keeps it, edits around it or removes it.
	 *
	 * @return void
	 */
	public function testASaveOverAStoredRuleIsNotRefused(): void {
		$bad = ['group' => 'g', 'match' => ['_published' => true]];
		$stored = ['authorization' => json_encode(['read' => [$bad]]), 'properties' => '{}'];

		foreach ([['read' => [$bad]], ['read' => [$bad, 'editors']], ['read' => ['editors']]] as $authorization) {
			$schema = $this->schemaWith($authorization);
			$schema->setId(5);
			$this->assertSaveAccepted(SchemaMapper::class, $schema, $stored);
		}
	}//end testASaveOverAStoredRuleIsNotRefused()

	/**
	 * A stored property rule does not block a save that keeps it.
	 *
	 * @return void
	 */
	public function testASaveOverAStoredPropertyRuleIsNotRefused(): void {
		$properties = ['secret' => ['type' => 'string', 'authorization' => ['read' => [['group' => 'g', 'match' => ['_published' => true]]]]]];
		$schema = $this->schemaWith([], $properties);
		$schema->setId(5);

		$this->assertSaveAccepted(SchemaMapper::class, $schema, ['authorization' => '{}', 'properties' => json_encode($properties)]);
	}//end testASaveOverAStoredPropertyRuleIsNotRefused()

	/**
	 * A save over a stored rule that adds another one is refused, naming only the new one.
	 *
	 * @return void
	 */
	public function testASaveThatAddsARuleOverAStoredOneIsRefused(): void {
		$bad = ['group' => 'g', 'match' => ['_published' => true]];
		$schema = $this->schemaWith(['read' => [$bad, ['group' => 'h', 'match' => ['@self.depublished' => true]]]]);
		$schema->setId(5);

		try {
			$this->validateAsTheSaveDoes(SchemaMapper::class, $schema, ['authorization' => json_encode(['read' => [$bad]]), 'properties' => '{}']);
			$this->fail('The save accepted a new condition on unknown metadata');
		} catch (AuthorizationBlockException $e) {
			$this->assertStringContainsString('"@self.depublished"', $e->getMessage());
			$this->assertStringNotContainsString('"_published"', $e->getMessage());
		}
	}//end testASaveThatAddsARuleOverAStoredOneIsRefused()

	/**
	 * A schema with an id the database does not hold is treated as new, and refused.
	 *
	 * @return void
	 */
	public function testAnIdTheDatabaseDoesNotHoldIsRefused(): void {
		$schema = $this->schemaWith(['read' => [['group' => 'g', 'match' => ['_published' => true]]]]);
		$schema->setId(5);

		$this->expectException(AuthorizationBlockException::class);
		$this->validateAsTheSaveDoes(SchemaMapper::class, $schema);
	}//end testAnIdTheDatabaseDoesNotHoldIsRefused()

	/**
	 * A register save keeps a stored rule and refuses a new one.
	 *
	 * @return void
	 */
	public function testARegisterSaveComparesWithTheStoredBlock(): void {
		$bad = ['group' => 'g', 'match' => ['_published' => true]];
		$stored = ['authorization' => json_encode(['read' => [$bad]])];

		$same = new Register();
		$same->setId(5);
		$same->setSlug('zaken');
		$same->setAuthorization(['read' => [$bad, 'editors']]);
		$this->validateAsTheSaveDoes(RegisterMapper::class, $same, $stored);

		$added = new Register();
		$added->setId(5);
		$added->setSlug('zaken');
		$added->setAuthorization(['read' => [$bad, ['group' => 'h', 'match' => ['_depublished' => true]]]]);
		$this->expectException(AuthorizationBlockException::class);
		$this->expectExceptionMessage('"_depublished"');
		$this->validateAsTheSaveDoes(RegisterMapper::class, $added, $stored);
	}//end testARegisterSaveComparesWithTheStoredBlock()

	/**
	 * A schema with one block and optional properties.
	 *
	 * @param array $authorization The schema's block.
	 * @param array $properties    The schema's properties.
	 *
	 * @return Schema The schema.
	 */
	private function schemaWith(array $authorization, array $properties = []): Schema {
		$schema = new Schema();
		$schema->setSlug('zaak');
		$schema->setAuthorization($authorization);
		$schema->setProperties($properties);

		return $schema;
	}//end schemaWith()

	/**
	 * Block shapes that each carry a `_published` condition.
	 *
	 * @return array<string, array{0: array, 1: array, 2: string}>
	 */
	public static function refusedSchemas(): array {
		$published = ['_published' => ['$lte' => '$now']];

		return [
			'an allow rule'  => [['read' => [['group' => 'public', 'match' => $published]]], [], 'read rule for group "public"'],
			'a deny rule'    => [['read' => ['viewers'], 'deny' => ['read' => [['group' => 'editors', 'match' => $published]]]], [], 'deny read rule for group "editors"'],
			'a property rule' => [
				[],
				['secret' => ['type' => 'string', 'authorization' => ['read' => [['group' => 'g', 'match' => $published]]]]],
				'read rule for group "g" in property "secret"',
			],
		];
	}//end refusedSchemas()

	/**
	 * A schema rule on `_published` is refused, naming the key and the rule.
	 *
	 * @param array  $authorization The schema's block.
	 * @param array  $properties    The schema's properties.
	 * @param string $rule          How the message names the rule.
	 *
	 * @return void
	 *
	 * @dataProvider refusedSchemas
	 */
	public function testASchemaRuleOnPublishedIsRefused(array $authorization, array $properties, string $rule): void {
		try {
			$this->validateAsTheSaveDoes(mapperClass: SchemaMapper::class, entity: $this->schemaWith($authorization, $properties));
			$this->fail('The save accepted a condition on _published');
		} catch (AuthorizationBlockException $e) {
			$this->assertStringContainsString('"_published"', $e->getMessage());
			$this->assertStringContainsString($rule, $e->getMessage());
		}
	}//end testASchemaRuleOnPublishedIsRefused()

	/**
	 * The control: the same rules on record metadata save.
	 *
	 * @return void
	 */
	public function testARuleOnRecordMetadataSaves(): void {
		$own = ['_organisation' => '$organisation', '_owner' => '$userId'];
		$this->assertSaveAccepted(
			mapperClass: SchemaMapper::class,
			entity: $this->schemaWith(
				['read' => [['group' => 'public', 'match' => $own]], 'deny' => ['update' => [['group' => 'public', 'match' => $own]]]],
				['secret' => ['type' => 'string', 'authorization' => ['read' => [['group' => 'g', 'match' => $own]]]]]
			)
		);
	}//end testARuleOnRecordMetadataSaves()

	/**
	 * A register rule on unknown metadata is refused too.
	 *
	 * @return void
	 */
	public function testARegisterRuleOnUnknownMetadataIsRefused(): void {
		$register = new Register();
		$register->setSlug('zaken');
		$register->setAuthorization(['read' => [['group' => 'public', 'match' => ['_depublished' => null]]]]);

		$this->expectException(AuthorizationBlockException::class);
		$this->expectExceptionMessage('"_depublished"');
		$this->validateAsTheSaveDoes(mapperClass: RegisterMapper::class, entity: $register);
	}//end testARegisterRuleOnUnknownMetadataIsRefused()

	/**
	 * A schema save refuses a key that is malformed or names no property, naming the rule.
	 *
	 * @param string $key The key.
	 *
	 * @return void
	 */
	#[DataProvider('refusedKeys')]
	public function testASchemaRuleOnARefusedKeyIsRefused(string $key): void {
		$schema = $this->schemaWith(
			['deny' => ['read' => [['group' => 'editors', 'match' => [$key => 'a']]]]],
			['status' => ['type' => 'string']]
		);

		$this->expectException(AuthorizationBlockException::class);
		$this->expectExceptionMessage('The deny read rule for group "editors"');
		$this->expectExceptionMessage('"'.$key.'"');
		$this->validateAsTheSaveDoes(mapperClass: SchemaMapper::class, entity: $schema);
	}//end testASchemaRuleOnARefusedKeyIsRefused()

	/**
	 * Keys a schema save refuses.
	 *
	 * @return array<string, array{0: string}> The keys.
	 */
	public static function refusedKeys(): array {
		return [
			'injection'  => ['x) or (1=1'],
			'dotted'     => ['other.status'],
			'undeclared' => ['secret'],
		];
	}//end refusedKeys()

	/**
	 * A key on a metadata prefix with injected text is refused by the metadata check.
	 *
	 * @return void
	 */
	public function testAnInjectedMetadataKeyIsRefused(): void {
		$schema = $this->schemaWith(['read' => [['group' => 'g', 'match' => ['_owner) or (1=1' => 'a']]]]);

		$this->expectException(AuthorizationBlockException::class);
		$this->validateAsTheSaveDoes(mapperClass: SchemaMapper::class, entity: $schema);
	}//end testAnInjectedMetadataKeyIsRefused()

	/**
	 * A property's own rule is judged against the schema's properties too.
	 *
	 * @return void
	 */
	public function testAPropertyRuleOnARefusedKeyIsRefused(): void {
		$schema = $this->schemaWith(
			[],
			['note' => ['type' => 'string', 'authorization' => ['read' => [['group' => 'g', 'match' => ['secret' => 'a']]]]]]
		);

		$this->expectException(AuthorizationBlockException::class);
		$this->expectExceptionMessage('in property "note" of schema');
		$this->validateAsTheSaveDoes(mapperClass: SchemaMapper::class, entity: $schema);
	}//end testAPropertyRuleOnARefusedKeyIsRefused()

	/**
	 * CONTROL: rules on declared properties and record metadata save.
	 *
	 * @return void
	 */
	public function testARuleOnADeclaredPropertySaves(): void {
		$schema = $this->schemaWith(
			['read' => [['group' => 'g', 'match' => ['firstName' => 'a', '_owner' => '$userId', '@self.organisation' => 'b']]]],
			['firstName' => ['type' => 'string']]
		);

		$this->assertSaveAccepted(mapperClass: SchemaMapper::class, entity: $schema);
	}//end testARuleOnADeclaredPropertySaves()

	/**
	 * A condition the stored block holds can stay, and other edits to the schema still save.
	 *
	 * @return void
	 */
	public function testASaveThatKeepsAStoredConditionSaves(): void {
		$bad = ['group' => 'g', 'match' => ['x) or (1=1' => 'a']];
		$schema = $this->schemaWith(['read' => [$bad]], ['status' => ['type' => 'string']]);
		$schema->setId(5);

		$this->assertSaveAccepted(SchemaMapper::class, $schema, ['authorization' => json_encode(['read' => [$bad]]), 'properties' => '{}']);
	}//end testASaveThatKeepsAStoredConditionSaves()

	/**
	 * A property's stored condition on such a key can stay too.
	 *
	 * @return void
	 */
	public function testASaveThatKeepsAStoredPropertyConditionSaves(): void {
		$properties = ['note' => ['type' => 'string', 'authorization' => ['read' => [['group' => 'g', 'match' => ['secret' => 'a']]]]]];
		$schema = $this->schemaWith([], $properties);
		$schema->setId(5);

		$this->assertSaveAccepted(SchemaMapper::class, $schema, ['authorization' => '{}', 'properties' => json_encode($properties)]);
	}//end testASaveThatKeepsAStoredPropertyConditionSaves()

	/**
	 * Dropping a property a stored rule names saves; the rule then admits no row.
	 *
	 * @return void
	 */
	public function testASaveThatDropsAPropertyARuleNamesSaves(): void {
		$rule = ['group' => 'g', 'match' => ['status' => 'a']];
		$schema = $this->schemaWith(['read' => [$rule]]);
		$schema->setId(5);

		$this->assertSaveAccepted(
			SchemaMapper::class,
			$schema,
			['authorization' => json_encode(['read' => [$rule]]), 'properties' => json_encode(['status' => ['type' => 'string']])]
		);
	}//end testASaveThatDropsAPropertyARuleNamesSaves()

	/**
	 * Changing the value of a stored condition on such a key is refused.
	 *
	 * @return void
	 */
	public function testASaveThatChangesAStoredConditionIsRefused(): void {
		$schema = $this->schemaWith(['read' => [['group' => 'g', 'match' => ['secret' => 'new']]]]);
		$schema->setId(5);

		$this->expectException(AuthorizationBlockException::class);
		$this->expectExceptionMessage('"secret"');
		$this->validateAsTheSaveDoes(
			SchemaMapper::class,
			$schema,
			['authorization' => json_encode(['read' => [['group' => 'g', 'match' => ['secret' => 'old']]]]), 'properties' => '{}']
		);
	}//end testASaveThatChangesAStoredConditionIsRefused()

	/**
	 * A register's rule can name any schema's property, so only a malformed key is refused.
	 *
	 * @return void
	 */
	public function testARegisterRefusesOnlyAMalformedKey(): void {
		$register = new Register();
		$register->setSlug('zaken');
		$register->setAuthorization(['read' => [['group' => 'public', 'match' => ['anyProperty' => 'a']]]]);
		$this->validateAsTheSaveDoes(mapperClass: RegisterMapper::class, entity: $register);

		$register->setAuthorization(['read' => [['group' => 'public', 'match' => ['x) or (1=1' => 'a']]]]);
		$this->expectException(AuthorizationBlockException::class);
		$this->expectExceptionMessage('"x) or (1=1"');
		$this->validateAsTheSaveDoes(mapperClass: RegisterMapper::class, entity: $register);
	}//end testARegisterRefusesOnlyAMalformedKey()

	/**
	 * A register save can keep a malformed condition it already holds.
	 *
	 * @return void
	 */
	public function testARegisterSaveKeepsAStoredMalformedKey(): void {
		$rule = ['group' => 'public', 'match' => ['x) or (1=1' => 'a']];
		$register = new Register();
		$register->setId(9);
		$register->setSlug('zaken');
		$register->setAuthorization(['read' => [$rule]]);

		$this->assertSaveAccepted(RegisterMapper::class, $register, ['authorization' => json_encode(['read' => [$rule]])]);
	}//end testARegisterSaveKeepsAStoredMalformedKey()
}//end class
