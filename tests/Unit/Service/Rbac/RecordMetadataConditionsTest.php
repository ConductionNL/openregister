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
use OCA\OpenRegister\Service\Rbac\RecordMetadataConditions;
use PHPUnit\Framework\TestCase;

/**
 * The known metadata is the record envelope, and every rule naming other metadata is found.
 *
 * @covers \OCA\OpenRegister\Service\Rbac\RecordMetadataConditions
 * @uses   \OCA\OpenRegister\Db\ObjectEntity
 * @uses   \OCA\OpenRegister\Db\Schema
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

		$this->assertSame(array_keys($envelope), $this->conditions->fields());
		$this->assertContains('organisation', $this->conditions->fields());
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

		$findings = $this->conditions->findings(authorization: $authorization, context: 'schema "zaak"');

		$this->assertCount(2, $findings);
		$this->assertStringContainsString('read rule for group "public" in schema "zaak"', $findings[0]);
		$this->assertStringContainsString('"_published"', $findings[0]);
		$this->assertStringContainsString('deny delete rule for user "bob" in schema "zaak"', $findings[1]);
		$this->assertStringContainsString('"_published"', $findings[1]);
	}//end testGrantAndDenyRulesAreFoundAndNamed()

	/**
	 * A block without such conditions, or without any block, has no findings.
	 *
	 * @return void
	 */
	public function testABlockWithKnownMetadataHasNoFindings(): void {
		$this->assertSame([], $this->conditions->findings(authorization: null, context: 'schema "x"'));
		$this->assertSame(
			[],
			$this->conditions->findings(
				authorization: ['read' => [['group' => 'g', 'match' => ['_owner' => '$userId']]], 'inheritFromPublic' => true],
				context: 'schema "x"'
			)
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

		$findings = $this->conditions->schemaFindings(schema: $schema);

		$this->assertCount(2, $findings);
		$this->assertStringContainsString('schema "zaak"', $findings[0]);
		$this->assertStringContainsString('property "secret" of schema "zaak"', $findings[1]);
		$this->assertStringContainsString('"_depublished"', $findings[1]);
	}//end testASchemasFindingsCoverItsPropertyRules()
}//end class
