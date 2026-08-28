<?php

/**
 * Inline-composition resolution regression test.
 *
 * JSON Schema lets an `allOf` / `oneOf` / `anyOf` entry be an inline schema object
 * ({ "required": [...], "not": {...} }) rather than a $ref to another schema.
 * OpenRegister's composition model treats these keywords as a list of schema IDENTIFIERS
 * and resolves each via `loadSchema()`. Before this fix it handed the inline array to
 * `loadSchema(string|int $identifier)`, which fatals with a TypeError — aborting the whole
 * register import.
 *
 * That silently broke OpenConnector's register import: its `lti_deployment` schema uses a
 * standard XOR constraint, `oneOf: [{required:[ltiPlatformId], not:{required:[ltiToolId]}},
 * {required:[ltiToolId], not:{required:[ltiPlatformId]}}]`. Every schema change after that
 * point failed to import (the recorded import version was stuck three releases behind).
 *
 * These tests pin that an all-inline composition resolves without ever calling loadSchema
 * — verified by leaving the DB mock throwing, so any loadSchema call would surface.
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
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db;

use OCA\OpenRegister\Db\OrganisationMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Schemas\PropertyValidatorHandler;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IAppConfig;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;

class SchemaMapperInlineCompositionTest extends TestCase {
	private SchemaMapper $mapper;

	protected function setUp(): void {
		// The DB mock deliberately throws on any query: an inline composition entry must
		// never reach the DB (via loadSchema), so a throw here would prove a regression.
		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willThrowException(new DoesNotExistException('no DB in this unit test'));

		$this->mapper = new SchemaMapper(
			$db,
			$this->createMock(IEventDispatcher::class),
			$this->createMock(PropertyValidatorHandler::class),
			$this->createMock(OrganisationMapper::class),
			$this->createMock(IUserSession::class),
			$this->createMock(IGroupManager::class),
			$this->createMock(IAppConfig::class),
			$this->createMock(LoggerInterface::class)
		);
	}

	/**
	 * Invoke the private resolveSchemaExtension().
	 *
	 * @param Schema $schema The schema to resolve.
	 *
	 * @return Schema
	 */
	private function resolve(Schema $schema): Schema {
		$method = new ReflectionMethod(SchemaMapper::class, 'resolveSchemaExtension');
		$method->setAccessible(true);

		return $method->invoke($this->mapper, $schema, []);
	}

	/**
	 * The XOR constraint from openconnector's lti_deployment: an all-inline oneOf resolves
	 * without touching the DB.
	 *
	 * @return void
	 */
	public function testInlineOneOfConstraintResolvesWithoutLoadingSchemas(): void {
		$schema = new Schema();
		$schema->setTitle('lti_deployment');
		$schema->setOneOf(
			[
				['required' => ['ltiPlatformId'], 'not' => ['required' => ['ltiToolId']]],
				['required' => ['ltiToolId'], 'not' => ['required' => ['ltiPlatformId']]],
			]
		);

		// If the inline entries were passed to loadSchema(), the throwing DB mock would
		// surface here. Reaching a return proves they were skipped.
		$resolved = $this->resolve($schema);

		$this->assertSame('lti_deployment', $resolved->getTitle());
	}

	/**
	 * An all-inline allOf is likewise skipped (same guard, different keyword).
	 *
	 * @return void
	 */
	public function testInlineAllOfConstraintResolvesWithoutLoadingSchemas(): void {
		$schema = new Schema();
		$schema->setTitle('constrained');
		$schema->setAllOf([['required' => ['a']], ['required' => ['b']]]);

		$resolved = $this->resolve($schema);

		$this->assertSame('constrained', $resolved->getTitle());
	}

	/**
	 * An all-inline anyOf is likewise skipped.
	 *
	 * @return void
	 */
	public function testInlineAnyOfConstraintResolvesWithoutLoadingSchemas(): void {
		$schema = new Schema();
		$schema->setTitle('flexible');
		$schema->setAnyOf([['required' => ['a']], ['minProperties' => 1]]);

		$resolved = $this->resolve($schema);

		$this->assertSame('flexible', $resolved->getTitle());
	}

	/**
	 * Invoke the private extractSchemaDelta().
	 *
	 * THE SECOND ENTRY POINT, and the one that was still broken. The three
	 * tests above exercise `resolveSchemaExtension`, which is what a READ goes
	 * through. A WRITE goes somewhere else entirely:
	 *
	 *     SchemasController::create -> createFromArray
	 *                               -> extractSchemaDelta
	 *                               -> extractAllOfDelta -> loadSchema
	 *
	 * `oneOf` and `anyOf` return early from `extractSchemaDelta`, so they were
	 * never exposed on this path — only `allOf` reaches `loadSchema`, which is
	 * why the earlier fix looked complete and was not.
	 *
	 * @param Schema $schema The schema to extract a delta from.
	 *
	 * @return Schema
	 */
	private function extractDelta(Schema $schema): Schema {
		$method = new ReflectionMethod(SchemaMapper::class, 'extractSchemaDelta');
		$method->setAccessible(true);

		return $method->invoke($this->mapper, $schema);
	}

	/**
	 * scholiq's `Lesson`: an `allOf` holding a conditional, not a parent.
	 *
	 * ⚠️ THIS IS THE ARM THAT 500'd (openregister#2534). The entry is an array,
	 * `loadSchema(string|int)` raised a TypeError, and the `catch (Exception)`
	 * around it could not catch an `Error` — so `POST /api/schemas` answered
	 * 500 and the schema was simply unimportable. scholiq's CI seed had grown a
	 * documented workaround: it created the schema with the `allOf` STRIPPED and
	 * logged that conditional validation was dropped for the fixture.
	 *
	 * The properties must survive: a conditional names no parent, so there is
	 * nothing to subtract, and an empty delta would silently erase the schema.
	 *
	 * @return void
	 */
	public function testConditionalAllOfExtractsNoDeltaAndKeepsProperties(): void {
		$schema = new Schema();
		$schema->setTitle('Lesson');
		$schema->setProperties(
			[
				'contentType' => ['type' => 'string'],
				'contentRef' => ['type' => 'string'],
			]
		);
		$schema->setRequired(['contentType']);
		$schema->setAllOf(
			[
				[
					'if' => ['properties' => ['contentType' => ['const' => 'text']], 'required' => ['contentType']],
					'then' => [],
					'else' => ['required' => ['contentRef']],
				],
			]
		);

		$delta = $this->extractDelta($schema);

		$this->assertSame(['contentType', 'contentRef'], array_keys($delta->getProperties()));
		$this->assertSame(['contentType'], $delta->getRequired());
	}

	/**
	 * A `$ref` entry still names a parent, and is still looked up.
	 *
	 * THE ANTI-WIDENING ARM. "Skip entries that are arrays" would also skip
	 * `{"$ref": "#/components/schemas/Person"}`, quietly turning a real
	 * inheritance into no inheritance — a schema that stores its parent's
	 * properties again instead of extending. The throwing DB mock makes the
	 * lookup observable: reaching it is the proof, and the surrounding
	 * try/catch then returns the schema unchanged.
	 *
	 * @return void
	 */
	public function testRefEntryStillResolvesToAParentIdentifier(): void {
		$method = new ReflectionMethod(SchemaMapper::class, 'parentIdentifierFromAllOfEntry');
		$method->setAccessible(true);

		$this->assertSame('Person', $method->invoke($this->mapper, ['$ref' => '#/components/schemas/Person']));
		$this->assertSame('person', $method->invoke($this->mapper, 'person'));
		$this->assertSame(42, $method->invoke($this->mapper, 42));
		$this->assertNull($method->invoke($this->mapper, ['if' => [], 'then' => []]));
		$this->assertNull($method->invoke($this->mapper, ['required' => ['a']]));
		$this->assertNull($method->invoke($this->mapper, ''));
	}
}
