<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Party;

use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\Party\PartyAnnotationValidator;
use OCA\OpenRegister\Service\Party\PartyDefinition;
use PHPUnit\Framework\TestCase;

/**
 * What a schema says when it says its objects are parties, and what a schema
 * says about the kinds of party its objects accept.
 *
 * @spec openspec/changes/party-roles-beyond-the-requester/specs/party-model/spec.md
 */
class PartyDeclarationTest extends TestCase {

	/**
	 * A schema carrying a configuration.
	 *
	 * @param array<string, mixed> $configuration The configuration.
	 * @param array<string, mixed> $properties The declared properties.
	 *
	 * @return Schema The schema.
	 */
	private function schema(array $configuration, array $properties = []): Schema {
		$schema = new Schema();
		$schema->setProperties($properties);
		$schema->setConfiguration($configuration);

		return $schema;
	}//end schema()

	/**
	 * The declaration names the properties the party model reads.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/party-roles-beyond-the-requester/specs/party-model/spec.md#requirement-a-party-without-an-account-carries-its-own-fields-and-is-reachable-req-prm-002
	 */
	public function testTheDeclarationNamesTheProperties(): void {
		$schema = $this->schema(
			configuration: [
				'x-openregister-party' => [
					'kind' => 'organisation',
					'nameProperty' => 'naam',
					'addressesProperty' => 'adressen',
					'indicatorsProperty' => 'indicatoren',
					'parentProperty' => 'moeder',
					'maxDepth' => 4,
				],
			],
			properties: ['naam' => [], 'adressen' => [], 'indicatoren' => [], 'moeder' => []]
		);

		$definition = PartyDefinition::fromSchema(schema: $schema);

		$this->assertNotNull($definition);
		$this->assertSame('organisation', $definition->kind());
		$this->assertSame('naam', $definition->nameProperty());
		$this->assertSame('adressen', $definition->addressesProperty());
		$this->assertSame('indicatoren', $definition->indicatorsProperty());
		$this->assertSame('moeder', $definition->parentProperty());
		$this->assertSame(4, $definition->maxDepth());
	}//end testTheDeclarationNamesTheProperties()

	/**
	 * A schema that declares nothing is not a party schema.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/party-roles-beyond-the-requester/specs/party-model/spec.md#requirement-a-party-without-an-account-carries-its-own-fields-and-is-reachable-req-prm-002
	 */
	public function testASchemaWithoutTheDeclarationIsNotAParty(): void {
		$this->assertNull(PartyDefinition::fromSchema(schema: $this->schema(configuration: [])));
	}//end testASchemaWithoutTheDeclarationIsNotAParty()

	/**
	 * A declaration naming no properties falls back to the documented names,
	 * so the shortest possible declaration still reads a party.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/party-roles-beyond-the-requester/specs/party-model/spec.md#requirement-a-party-without-an-account-carries-its-own-fields-and-is-reachable-req-prm-002
	 */
	public function testTheShortestDeclarationDefaults(): void {
		$definition = PartyDefinition::fromAnnotation(annotation: []);

		$this->assertSame('person', $definition->kind());
		$this->assertSame('addresses', $definition->addressesProperty());
		$this->assertSame('indicators', $definition->indicatorsProperty());
		$this->assertNull($definition->parentProperty());
		$this->assertSame(10, $definition->maxDepth());
		$this->assertTrue($definition->unionAddresses());
	}//end testTheShortestDeclarationDefaults()

	/**
	 * A property field naming a property the schema does not declare is the
	 * phantom-annotation case: it reads fine and finds nothing. It is named.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/party-roles-beyond-the-requester/specs/party-model/spec.md#requirement-a-party-without-an-account-carries-its-own-fields-and-is-reachable-req-prm-002
	 */
	public function testAnUnknownPropertyIsNamed(): void {
		$errors = (new PartyAnnotationValidator())->validate(
			schema: [
				'properties' => ['naam' => []],
				'x-openregister-party' => ['addressesProperty' => 'adressen'],
			]
		);

		$this->assertCount(1, $errors);
		$this->assertSame('party.unknown-property', $errors[0]['code']);
		$this->assertStringContainsString('adressen', $errors[0]['message']);
	}//end testAnUnknownPropertyIsNamed()

	/**
	 * A kind wider than the link table's column, a negative depth and a
	 * non-object annotation are each refused.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/party-roles-beyond-the-requester/specs/party-model/spec.md#requirement-a-party-without-an-account-carries-its-own-fields-and-is-reachable-req-prm-002
	 */
	public function testTheMalformedDeclarationsAreRefused(): void {
		$validator = new PartyAnnotationValidator();

		$wideKind = $validator->validate(
			schema: ['properties' => [], 'x-openregister-party' => ['kind' => str_repeat('k', 65)]]
		);
		$this->assertSame('party.invalid-kind', $wideKind[0]['code']);

		$depth = $validator->validate(
			schema: ['properties' => [], 'x-openregister-party' => ['maxDepth' => 0]]
		);
		$this->assertSame('party.invalid-max-depth', $depth[0]['code']);

		$notObject = $validator->validate(
			schema: ['properties' => [], 'x-openregister-party' => 'person']
		);
		$this->assertSame('party.not-object', $notObject[0]['code']);

		$this->assertSame([], $validator->validate(schema: ['properties' => []]));
	}//end testTheMalformedDeclarationsAreRefused()

	/**
	 * The accepted party kinds round-trip through the schema configuration,
	 * roles and all.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/party-roles-beyond-the-requester/specs/party-model/spec.md#requirement-a-party-holds-a-typed-role-on-an-object-for-a-period-req-prm-001
	 */
	public function testThePartyKindsRoundTrip(): void {
		$schema = $this->schema(
			configuration: [
				'partyKinds' => [
					['key' => 'person', 'label' => 'Persoon', 'roles' => ['aanvrager', 'gemachtigde']],
					'organisation',
				],
			]
		);

		$kinds = $schema->getPartyKinds();

		$this->assertCount(2, $kinds);
		$this->assertSame('person', $kinds[0]['key']);
		$this->assertSame(['aanvrager', 'gemachtigde'], $kinds[0]['roles']);
		$this->assertSame('organisation', $kinds[1]['key']);
		$this->assertSame('organisation', $kinds[1]['label']);
		$this->assertArrayNotHasKey('roles', $kinds[1]);
	}//end testThePartyKindsRoundTrip()

	/**
	 * A duplicate key and a key wider than the column each lose only that
	 * configuration key, never the whole configuration.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/party-roles-beyond-the-requester/specs/party-model/spec.md#requirement-a-party-holds-a-typed-role-on-an-object-for-a-period-req-prm-001
	 */
	public function testABadPartyKindsValueLosesOnlyThatKey(): void {
		$schema = $this->schema(
			configuration: [
				'partyKinds' => [['key' => 'person'], ['key' => 'person']],
				'objectNameField' => 'naam',
			]
		);

		$this->assertSame([], $schema->getPartyKinds());
		$this->assertSame('naam', $schema->getConfiguration()['objectNameField']);
	}//end testABadPartyKindsValueLosesOnlyThatKey()

	/**
	 * THE REGRESSION. A schema that declares no party kinds behaves as it did
	 * before the party model: no vocabulary, and the link roles it already
	 * declared are untouched.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/party-roles-beyond-the-requester/specs/party-model/spec.md#requirement-a-party-holds-a-typed-role-on-an-object-for-a-period-req-prm-001
	 */
	public function testASchemaDeclaringNoPartyKindsBehavesAsBefore(): void {
		$schema = $this->schema(
			configuration: ['linkRoles' => [['key' => 'handler', 'label' => 'Handler']]]
		);

		$this->assertSame([], $schema->getPartyKinds());
		$this->assertSame([], $schema->getPartyAnnotation());
		$this->assertSame([['key' => 'handler', 'label' => 'Handler']], $schema->getLinkRoles());
	}//end testASchemaDeclaringNoPartyKindsBehavesAsBefore()
}//end class
