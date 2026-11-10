<?php

/**
 * Golden test of the order of checks in the create and update mutations.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\GraphQL
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\GraphQL;

use GraphQL\Error\Error;
use OCA\OpenRegister\Service\PropertyWriteCheck;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Exception\NotAuthorizedException;
use OCA\OpenRegister\Search\Service\Aggregation\TimeseriesRequestValidator;
use OCA\OpenRegister\Service\Aggregation\AggregationRunner;
use OCA\OpenRegister\Service\GraphQL\GraphQLResolver;
use OCA\OpenRegister\Service\GraphQL\GraphQLResolverShaping;
use OCA\OpenRegister\Service\GraphQL\GraphQLResolverRights;
use OCA\OpenRegister\Service\GraphQL\GraphQLResolverObjects;
use OCA\OpenRegister\Service\Object\GetObject;
use OCA\OpenRegister\Service\Object\PermissionHandler;
use OCA\OpenRegister\Service\Object\RelationHandler;
use OCA\OpenRegister\Service\Object\TranslationHandler;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\PropertyRbacHandler;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use OCA\OpenRegister\Service\Object\ObjectSaveOptions;

/**
 * The schema check, then the property check, then the write; a refusal stops the rest.
 */
class GraphQLResolverMutationGoldenTest extends TestCase {

	private const INPUT = ['title' => 'u', 'secret' => 's'];

	/**
	 * What the collaborators were asked, in order.
	 *
	 * @var array<int, array<int, mixed>>
	 */
	private array $calls = [];

	/**
	 * Properties the property check refuses.
	 *
	 * @var array<int, string>
	 */
	private array $refused = [];

	/**
	 * Whether the schema check refuses.
	 *
	 * @var bool
	 */
	private bool $schemaRefused = false;

	/**
	 * The register the schema belongs to.
	 *
	 * @var Register
	 */
	private Register $register;

	/**
	 * The schema under test.
	 *
	 * @var Schema
	 */
	private Schema $schema;

	/**
	 * A stored record.
	 *
	 * @return ObjectEntity The record.
	 */
	private function record(): ObjectEntity {
		$record = new ObjectEntity();
		$record->setUuid('obj-1');
		$record->setObject(['title' => 't']);

		return $record;
	}//end record()

	/**
	 * Build the resolver over doubles that log each call.
	 *
	 * @return GraphQLResolver The resolver.
	 */
	private function resolver(): GraphQLResolver {
		$this->schema = new Schema();
		$this->schema->setId(1);
		$this->schema->setSlug('zaak');
		$this->register = new Register();
		$this->register->setId(1);
		$this->register->setSchemas([1]);

		$permissions = $this->createMock(PermissionHandler::class);
		$permissions->method('checkPermission')->willReturnCallback(
			function (Schema $schema, string $action): void {
				$this->calls[] = ['schemaCheck', $action];
				if ($this->schemaRefused === true) {
					throw new NotAuthorizedException('schema refused');
				}
			}
		);

		$rbac = $this->createMock(PropertyRbacHandler::class);
		$rbac->method('getUnauthorizedProperties')->willReturnCallback(
			function (PropertyWriteCheck $write): array {
				[$schema, $stored, $input, $isCreate] = [$write->schema, $write->request->object, $write->incoming, $write->request->isCreate];
				$this->calls[] = ['propertyCheck', ($stored['title'] ?? null), $input, $isCreate];
				return $this->refused;
			}
		);
		$rbac->method('filterReadableProperties')->willReturnArgument(1);

		$getObject = $this->createMock(GetObject::class);
		$getObject->method('find')->willReturnCallback(
			function (mixed $id, mixed $register, mixed $schema, ...$rest): ObjectEntity {
				$this->calls[] = ['find', $id, ($register === $this->register), ($schema === $this->schema)];
				return $this->record();
			}
		);

		$objects = $this->createMock(ObjectService::class);
		$objects->method('saveObject')->willReturnCallback(
			function (array|ObjectEntity $object, ObjectSaveOptions $options = new ObjectSaveOptions(), bool $_rbac = true, bool $_multitenancy = true): ObjectEntity {
				$extend = $options->extend;
				$register = $options->target->register;
				$schema = $options->target->schema;
				$uuid = $options->uuid;
				$this->calls[] = ['save', $object, $extend, ($register === $this->register), ($schema === $this->schema), $uuid];
				return $this->record();
			}
		);

		$registers = $this->createMock(RegisterMapper::class);
		$registers->method('findAll')->willReturn([$this->register]);

		return new GraphQLResolver(
			new GraphQLResolverObjects(
				$getObject,
				$objects,
				$this->createMock(RelationHandler::class),
				$registers
			),
			new GraphQLResolverRights(
				$permissions,
				$rbac
			),
			new GraphQLResolverShaping(
				$this->createMock(TranslationHandler::class),
				$this->createMock(AggregationRunner::class),
				new TimeseriesRequestValidator()
			),
			$this->createMock(LoggerInterface::class)
		);
	}//end resolver()

	/**
	 * Run a mutation that must be refused and return the error.
	 *
	 * @param callable $mutation The mutation.
	 *
	 * @return Error The error.
	 */
	private function refusal(callable $mutation): Error {
		try {
			$mutation();
		} catch (Error $e) {
			return $e;
		}

		$this->fail('the mutation was not refused');
	}//end refusal()

	/**
	 * A create checks the schema, then the properties, then writes.
	 *
	 * @return void
	 */
	public function testCreateOrder(): void {
		$resolver = $this->resolver();

		$result = $resolver->resolveCreate(schema: $this->schema, args: ['input' => self::INPUT]);

		$this->assertSame('t', $result['title']);
		$this->assertSame(
			[
				['schemaCheck', 'create'],
				['propertyCheck', null, self::INPUT, true],
				['save', self::INPUT, [], true, true, null],
			],
			$this->calls
		);
	}//end testCreateOrder()

	/**
	 * A forbidden property on create stops before anything is written.
	 *
	 * @return void
	 */
	public function testCreateForbiddenField(): void {
		$resolver = $this->resolver();
		$this->refused = ['secret', 'other'];

		$error = $this->refusal(fn () => $resolver->resolveCreate(schema: $this->schema, args: ['input' => self::INPUT]));

		$this->assertSame('Not authorized to write fields: secret, other', $error->getMessage());
		$this->assertSame(['code' => 'FIELD_FORBIDDEN'], $error->getExtensions());
		$this->assertCount(2, $this->calls);
	}//end testCreateForbiddenField()

	/**
	 * A schema refusal on create stops before the property check.
	 *
	 * @return void
	 */
	public function testCreateSchemaRefusal(): void {
		$resolver = $this->resolver();
		$this->schemaRefused = true;

		$error = $this->refusal(fn () => $resolver->resolveCreate(schema: $this->schema, args: ['input' => self::INPUT]));

		$this->assertSame(['code' => 'FORBIDDEN'], $error->getExtensions());
		$this->assertSame([['schemaCheck', 'create']], $this->calls);
	}//end testCreateSchemaRefusal()

	/**
	 * An update checks the schema, reads the stored record, checks the properties
	 * against it, then writes.
	 *
	 * @return void
	 */
	public function testUpdateOrder(): void {
		$resolver = $this->resolver();

		$result = $resolver->resolveUpdate(schema: $this->schema, args: ['id' => 'obj-1', 'input' => self::INPUT]);

		$this->assertSame('t', $result['title']);
		$this->assertSame(
			[
				['schemaCheck', 'update'],
				['find', 'obj-1', true, true],
				['propertyCheck', 't', self::INPUT, false],
				['save', self::INPUT, [], true, true, 'obj-1'],
			],
			$this->calls
		);
	}//end testUpdateOrder()

	/**
	 * A forbidden property on update stops before anything is written.
	 *
	 * @return void
	 */
	public function testUpdateForbiddenField(): void {
		$resolver = $this->resolver();
		$this->refused = ['secret'];

		$error = $this->refusal(fn () => $resolver->resolveUpdate(schema: $this->schema, args: ['id' => 'obj-1', 'input' => self::INPUT]));

		$this->assertSame('Not authorized to write fields: secret', $error->getMessage());
		$this->assertSame(['code' => 'FIELD_FORBIDDEN'], $error->getExtensions());
		$this->assertCount(3, $this->calls);
	}//end testUpdateForbiddenField()

	/**
	 * A schema refusal on update reads nothing.
	 *
	 * @return void
	 */
	public function testUpdateSchemaRefusal(): void {
		$resolver = $this->resolver();
		$this->schemaRefused = true;

		$error = $this->refusal(fn () => $resolver->resolveUpdate(schema: $this->schema, args: ['id' => 'obj-1', 'input' => self::INPUT]));

		$this->assertSame(['code' => 'FORBIDDEN'], $error->getExtensions());
		$this->assertSame([['schemaCheck', 'update']], $this->calls);
	}//end testUpdateSchemaRefusal()
}//end class
