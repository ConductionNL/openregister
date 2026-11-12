<?php

/**
 * Which underscore-prefixed conditions name metadata a record carries.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Rbac
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Rbac;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\Rbac\AuthorizationEdit;
use OCA\OpenRegister\Service\Rbac\MetadataConditionFindings;
use OCA\OpenRegister\Service\Rbac\RecordMetadataConditions;
use PHPUnit\Framework\TestCase;

/**
 * The known metadata is the record envelope, and every rule naming other metadata is found.
 *
 * @covers \OCA\OpenRegister\Service\Rbac\RecordMetadataConditions
 * @covers \OCA\OpenRegister\Service\Rbac\MetadataConditionFindings
 * @uses \OCA\OpenRegister\Service\Rbac\StoredRuleBlocks
 * @uses   \OCA\OpenRegister\Db\ObjectEntity
 * @uses   \OCA\OpenRegister\Db\Schema
 * @uses   \OCA\OpenRegister\Service\Rbac\RuleColumns
 * @uses   \OCA\OpenRegister\Service\Rbac\UnknownRuleKeyFindings
 */
class RecordMetadataConditionsTest extends TestCase {

	/**
	 * The class under test.
	 *
	 * @var RecordMetadataConditions
	 */
	private RecordMetadataConditions $conditions;

	/**
	 * Build the class under test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->conditions = new RecordMetadataConditions();
	}//end setUp()

	/**
	 * Every field of a record's `@self` is known metadata, and published is not.
	 *
	 * @return void
	 */
	public function testTheKnownFieldsAreTheRecordEnvelope(): void {
		$envelope = (new ObjectEntity())->getMatchEnvelope()['@self'];

		$this->assertSame(array_merge(array_keys($envelope), ['uuid']), $this->conditions->fields());
		$this->assertContains('organisation', $this->conditions->fields());
		$this->assertSame([], $this->conditions->unknownKeys(match: ['_uuid' => 'x']));
		$this->assertNotContains('published', $this->conditions->fields());
		$this->assertNotContains('depublished', $this->conditions->fields());
	}//end testTheKnownFieldsAreTheRecordEnvelope()

	/**
	 * Only an underscore-prefixed key outside the envelope is unknown.
	 *
	 * @return void
	 */
	public function testOnlyUnderscoreKeysOutsideTheEnvelopeAreUnknown(): void {
		$match = [
			'_published'    => ['$lte' => '$now'],
			'_organisation' => '$organisation',
			'_owner'        => '$userId',
			'status'        => 'open',
			'_frobnicate'   => true,
		];

		$this->assertSame(['_published', '_frobnicate'], $this->conditions->unknownKeys(match: $match));
		$this->assertSame([], $this->conditions->unknownKeys(match: ['_schema' => '7', 'title' => 'x']));
	}//end testOnlyUnderscoreKeysOutsideTheEnvelopeAreUnknown()

	/**
	 * A grant rule and a deny rule are both found, each named by action, principal and key.
	 *
	 * @return void
	 */
	public function testGrantAndDenyRulesAreFoundAndNamed(): void {
		$authorization = [
			'read'   => ['editors', ['group' => 'public', 'match' => ['_published' => ['$lte' => '$now']]]],
			'update' => [['group' => 'editors', 'match' => ['_organisation' => '$organisation']]],
			'deny'   => ['delete' => [['user' => 'bob', 'match' => ['_published' => true]]]],
			'matrix' => ['field' => 'afdeling', 'rows' => [['where' => ['_published' => true]]]],
		];

		$findings = (new MetadataConditionFindings())->findings(edit: new AuthorizationEdit(authorization: $authorization), context: 'schema "zaak"');

		$this->assertCount(2, $findings);
		$this->assertStringContainsString('read rule for group "public" in schema "zaak"', $findings[0]);
		$this->assertStringContainsString('"_published"', $findings[0]);
		$this->assertStringContainsString('deny delete rule for user "bob" in schema "zaak"', $findings[1]);
		$this->assertStringContainsString('"_published"', $findings[1]);
	}//end testGrantAndDenyRulesAreFoundAndNamed()

	/**
	 * A `@self.` key follows the same rule as its underscore spelling, and a dotted field is not a field.
	 *
	 * @return void
	 */
	public function testSelfKeysFollowTheSameRule(): void {
		$match = ['@self.published' => null, '@self.owner' => 'bob', '@self.uuid' => 'x', '@self.owner.name' => 'y', 'title' => 'z'];

		$this->assertSame(['@self.published', '@self.owner.name'], $this->conditions->unknownKeys(match: $match));
	}//end testSelfKeysFollowTheSameRule()

	/**
	 * A field missing from a partial `@self` is never answered from the writable body.
	 *
	 * @return void
	 */
	public function testAFieldMissingFromAPartialSelfIsNotAnsweredFromTheBody(): void {
		$object = ['_owner' => 'alice', '@self.owner' => 'alice', 'owner' => 'alice', '@self' => ['organisation' => 'org-a']];

		$this->assertNull($this->conditions->valueIn(object: $object, property: '_owner'));
		$this->assertNull($this->conditions->valueIn(object: $object, property: '@self.owner'));
		$this->assertSame('org-a', $this->conditions->valueIn(object: $object, property: '@self.organisation'));
		$this->assertSame('alice', $this->conditions->valueIn(object: $object, property: 'owner'));
	}//end testAFieldMissingFromAPartialSelfIsNotAnsweredFromTheBody()

	/**
	 * Without any `@self`, an underscore key falls back to the body and a `@self.` key does not.
	 *
	 * @return void
	 */
	public function testOnlyAnUnderscoreKeyFallsBackToTheBody(): void {
		$object = ['_owner' => 'alice', '@self.owner' => 'alice'];

		$this->assertSame('alice', $this->conditions->valueIn(object: $object, property: '_owner'));
		$this->assertNull($this->conditions->valueIn(object: $object, property: '@self.owner'));
	}//end testOnlyAnUnderscoreKeyFallsBackToTheBody()

	/**
	 * A condition the stored block already holds is no finding; adding one or changing it is.
	 *
	 * @return void
	 */
	public function testOnlyAnAddedOrChangedConditionIsAFinding(): void {
		$bad = ['group' => 'g', 'match' => ['_published' => true, 'status' => 'open']];
		$stored = ['read' => [$bad]];
		$find = fn (array $new): array => (new MetadataConditionFindings())->findings(edit: new AuthorizationEdit(authorization: $new, stored: $stored), context: 'x');

		$this->assertSame([], $find(['read' => [$bad]]));
		$this->assertSame([], $find(['read' => [['group' => 'g', 'match' => ['_published' => true, 'status' => 'closed']]]]));
		$this->assertSame([], $find(['read' => []]));
		$this->assertCount(1, $find(['read' => [['group' => 'g', 'match' => ['_published' => false]]]]));
		$this->assertCount(1, $find(['read' => [['group' => 'h', 'match' => ['_published' => true]]]]));
		$this->assertCount(1, $find(['update' => [$bad]]));
		$this->assertCount(1, $find(['read' => [$bad, ['group' => 'g', 'match' => ['_depublished' => true]]]]));
	}//end testOnlyAnAddedOrChangedConditionIsAFinding()

	/**
	 * A property's stored rule counts for that property only.
	 *
	 * @return void
	 */
	public function testAStoredPropertyRuleCountsForThatPropertyOnly(): void {
		$rule = ['read' => [['group' => 'g', 'match' => ['_published' => true]]]];
		$stored = new Schema();
		$stored->setProperties(['secret' => ['authorization' => $rule]]);

		$same = new Schema();
		$same->setProperties(['secret' => ['authorization' => $rule]]);
		$moved = new Schema();
		$moved->setProperties(['other' => ['authorization' => $rule]]);

		$this->assertSame([], (new MetadataConditionFindings())->schemaFindings(schema: $same, stored: $stored));
		$this->assertCount(1, (new MetadataConditionFindings())->schemaFindings(schema: $moved, stored: $stored));
	}//end testAStoredPropertyRuleCountsForThatPropertyOnly()

	/**
	 * A block without such conditions, or without any block, has no findings.
	 *
	 * @return void
	 */
	public function testABlockWithKnownMetadataHasNoFindings(): void {
		$this->assertSame([], (new MetadataConditionFindings())->findings(edit: new AuthorizationEdit(authorization: null), context: 'schema "x"'));
		$this->assertSame(
			[],
			(new MetadataConditionFindings())->findings(edit: new AuthorizationEdit(authorization: ['read' => [['group' => 'g', 'match' => ['_owner' => '$userId']]], 'inheritFromPublic' => true]), context: 'schema "x"')
		);
	}//end testABlockWithKnownMetadataHasNoFindings()

	/**
	 * A schema's findings cover its own block and every property's rules.
	 *
	 * @return void
	 */
	public function testASchemasFindingsCoverItsPropertyRules(): void {
		$schema = new Schema();
		$schema->setSlug('zaak');
		$schema->setAuthorization(['read' => [['group' => 'public', 'match' => ['_published' => true]]]]);
		$schema->setProperties(
			[
				'title'  => ['type' => 'string'],
				'secret' => ['type' => 'string', 'authorization' => ['read' => [['group' => 'g', 'match' => ['_depublished' => null]]]]],
			]
		);

		$findings = (new MetadataConditionFindings())->schemaFindings(schema: $schema);

		$this->assertCount(2, $findings);
		$this->assertStringContainsString('schema "zaak"', $findings[0]);
		$this->assertStringContainsString('property "secret" of schema "zaak"', $findings[1]);
		$this->assertStringContainsString('"_depublished"', $findings[1]);
	}//end testASchemasFindingsCoverItsPropertyRules()
}//end class
