<?php

/**
 * Tests for PublicFormDefinition: which forms a visitor without an account sees, and with which fields.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Form
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/capability-rows-verified-a/specs/form-and-journey-registry/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Form;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Service\Form\PublicFormDefinition;
use OCA\OpenRegister\Service\ObjectService;
use PHPUnit\Framework\TestCase;

/**
 * A form opens to visitors only when it says so, and its fields come from the form or its destination.
 *
 * @covers \OCA\OpenRegister\Service\Form\PublicFormDefinition
 */
class PublicFormDefinitionTest extends TestCase {

	/**
	 * The schema id the reader was asked for.
	 *
	 * @var array<int, string>
	 */
	private array $schemaLookups = [];

	/**
	 * A reader over one stored form body and one destination schema.
	 *
	 * @param array<string, mixed>|null $body   The stored form; null when it does not exist.
	 * @param Schema|null               $schema The destination schema; null when it cannot be read.
	 *
	 * @return PublicFormDefinition The reader.
	 */
	private function forms(?array $body, ?Schema $schema = null): PublicFormDefinition {
		$objects = $this->createMock(ObjectService::class);
		$objects->method('find')->willReturnCallback(
			static function () use ($body): ?ObjectEntity {
				if ($body === null) {
					return null;
				}

				$entity = new ObjectEntity();
				$entity->setUuid('form-1');
				$entity->setObject($body);
				return $entity;
			}
		);
		$schemas = $this->createMock(SchemaMapperInterface::class);
		$schemas->method('find')->willReturnCallback(
			function (string $id) use ($schema): Schema {
				$this->schemaLookups[] = $id;
				if ($schema === null) {
					throw new \RuntimeException('no schema');
				}

				return $schema;
			}
		);

		return new PublicFormDefinition(objects: $objects, schemas: $schemas);
	}//end forms()

	/**
	 * The `melding` destination schema of the lamp post scenario.
	 *
	 * @return Schema The schema.
	 */
	private static function melding(): Schema {
		$schema = new Schema();
		$schema->setProperties(
			[
				'location'    => ['type' => 'string', 'title' => 'Where is it?', 'description' => 'Street and number'],
				'kind'        => ['type' => 'string', 'title' => 'What is broken?', 'enum' => ['lamp post', 'bench']],
				'urgent'      => ['type' => 'boolean'],
				'count'       => ['type' => 'integer', 'title' => 'How many'],
				'photos'      => ['type' => 'array'],
				'_internal'   => ['type' => 'string'],
				'handledBy'   => ['type' => 'string', 'readOnly' => true],
				'broken'      => 'not a definition',
			]
		);
		$schema->setRequired(['location', 'kind']);
		return $schema;
	}//end melding()

	/**
	 * A published anonymous form derives its fields from the schema it writes to.
	 *
	 * @return void
	 */
	public function testAnAnonymousFormShowsTheFieldsOfItsDestination(): void {
		$form = $this->forms(
			body: [
				'status'      => 'published',
				'access'      => 'anonymous',
				'title'       => 'Report a problem in the street',
				'description' => 'We fix it within a week.',
				'destination' => ['register' => 'meldingen', 'schema' => 'melding'],
			],
			schema: self::melding()
		)->forVisitor(formId: 'form-1');

		$this->assertSame('form-1', $form['id']);
		$this->assertSame('Report a problem in the street', $form['title']);
		$this->assertSame('We fix it within a week.', $form['description']);
		$this->assertSame(
			[
				['key' => 'location', 'label' => 'Where is it?', 'type' => 'string', 'description' => 'Street and number', 'required' => true],
				['key' => 'kind', 'label' => 'What is broken?', 'type' => 'enum', 'enum' => ['lamp post', 'bench'], 'required' => true],
				['key' => 'urgent', 'label' => 'urgent', 'type' => 'boolean', 'required' => false],
				['key' => 'count', 'label' => 'How many', 'type' => 'number', 'required' => false],
			],
			$form['fields']
		);
		$this->assertSame([], $form['steps']);
		$this->assertSame(['melding'], $this->schemaLookups);
	}//end testAnAnonymousFormShowsTheFieldsOfItsDestination()

	/**
	 * A form that declares its own fields and steps shows those, without reading a schema.
	 *
	 * @return void
	 */
	public function testDeclaredFieldsAndStepsAreShownAsTheyAre(): void {
		$fields = [['key' => 'name', 'label' => 'Name', 'type' => 'string'], ['label' => 'no key'], 'junk'];
		$form = $this->forms(
			body: [
				'published' => true,
				'name'      => 'Sign up',
				'writes'    => [['as' => 'a', 'register' => 'r', 'schema' => 's'], 'junk'],
				'fields'    => $fields,
				'steps'     => [['id' => 'one', 'fields' => ['name']]],
			]
		)->forVisitor(formId: 'form-1');

		$this->assertSame('Sign up', $form['title']);
		$this->assertSame('', $form['description']);
		$this->assertSame([['key' => 'name', 'label' => 'Name', 'type' => 'string']], $form['fields']);
		$this->assertSame([['id' => 'one', 'fields' => ['name']]], $form['steps']);
		$this->assertSame([], $this->schemaLookups);
	}//end testDeclaredFieldsAndStepsAreShownAsTheyAre()

	/**
	 * A form with several writes and no declared fields, or an unreadable schema, shows no fields.
	 *
	 * @return void
	 */
	public function testNoFieldsAreGuessedAcrossSeveralWritesOrAMissingSchema(): void {
		$several = $this->forms(body: ['published' => true, 'access' => 'anonymous', 'writes' => [['schema' => 'a'], ['schema' => 'b']]])->forVisitor(formId: 'form-1');
		$this->assertSame([], $several['fields']);

		$missing = $this->forms(body: ['published' => true, 'destination' => ['schema' => 'gone']])->forVisitor(formId: 'form-1');
		$this->assertSame([], $missing['fields']);
		$this->assertSame(['gone'], $this->schemaLookups);
	}//end testNoFieldsAreGuessedAcrossSeveralWritesOrAMissingSchema()

	/**
	 * A form that declares another access, or is not published, or writes nowhere, is not shown.
	 *
	 * @return void
	 */
	public function testAFormThatNeedsAnAccountStaysClosed(): void {
		$destination = ['register' => 'r', 'schema' => 's'];
		$closed = [
			'another access'              => ['status' => 'published', 'access' => 'authenticated', 'audience' => 'public', 'destination' => $destination],
			'an empty access'             => ['status' => 'published', 'access' => '', 'destination' => $destination],
			'an authenticated audience'   => ['status' => 'published', 'audience' => 'authenticated', 'destination' => $destination],
			'a draft'                     => ['status' => 'draft', 'access' => 'anonymous', 'destination' => $destination],
			'no destination'              => ['status' => 'published', 'access' => 'anonymous'],
			'a destination without schema' => ['status' => 'published', 'access' => 'anonymous', 'destination' => ['register' => 'r']],
		];
		foreach ($closed as $name => $body) {
			$this->assertNull($this->forms(body: $body)->forVisitor(formId: 'form-1'), $name);
		}

		$this->assertNull($this->forms(body: null)->forVisitor(formId: 'form-1'));
	}//end testAFormThatNeedsAnAccountStaysClosed()

	/**
	 * Without `access`, the older audience decides: public by default.
	 *
	 * @return void
	 */
	public function testWithoutAccessThePublicAudienceOpensTheForm(): void {
		$this->assertTrue(PublicFormDefinition::opensToVisitors(body: []));
		$this->assertTrue(PublicFormDefinition::opensToVisitors(body: ['audience' => '']));
		$this->assertTrue(PublicFormDefinition::opensToVisitors(body: ['audience' => 'public']));
		$this->assertFalse(PublicFormDefinition::opensToVisitors(body: ['audience' => 'authenticated']));
		$this->assertTrue(PublicFormDefinition::opensToVisitors(body: ['access' => 'anonymous', 'audience' => 'authenticated']));
		$this->assertFalse(PublicFormDefinition::opensToVisitors(body: ['access' => null]));
	}//end testWithoutAccessThePublicAudienceOpensTheForm()

	/**
	 * A lookup that throws reads as a form that does not exist.
	 *
	 * @return void
	 */
	public function testAFailingLookupReadsAsNoForm(): void {
		$objects = $this->createMock(ObjectService::class);
		$objects->method('find')->willThrowException(new \RuntimeException('database down'));

		$forms = new PublicFormDefinition(objects: $objects, schemas: $this->createMock(SchemaMapperInterface::class));
		$this->assertNull($forms->forVisitor(formId: 'form-1'));
	}//end testAFailingLookupReadsAsNoForm()
}//end class
