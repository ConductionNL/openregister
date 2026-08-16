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
}
