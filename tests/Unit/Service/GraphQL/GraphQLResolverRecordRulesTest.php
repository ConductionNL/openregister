<?php

/**
 * GraphQLResolver: property rules read the stored record's own metadata.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\GraphQL
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace Unit\Service\GraphQL;

use GraphQL\Error\Error;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\Aggregation\AggregationRunner;
use OCA\OpenRegister\Search\Service\Aggregation\TimeseriesRequestValidator;
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
use OCP\AppFramework\Db\DoesNotExistException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The update check and the read filter ask the record, never the data's own `@self` or `_` keys.
 */
class GraphQLResolverRecordRulesTest extends TestCase {

	/**
	 * The object service double.
	 *
	 * @var ObjectService&MockObject
	 */
	private $objectService;

	/**
	 * The record lookup double.
	 *
	 * @var GetObject&MockObject
	 */
	private $getObject;

	/**
	 * The object each property-rule call was given, by method.
	 *
	 * @var array<string, array<int|string, mixed>>
	 */
	private array $seen = [];

	/**
	 * An org-b record whose stored data claims org-a.
	 *
	 * @return ObjectEntity The record.
	 */
	private function record(): ObjectEntity {
		$record = new ObjectEntity();
		$record->setUuid('obj-1');
		$record->setOrganisation('org-b');
		$record->setObject(['title' => 't', '_organisation' => 'org-a', '@self' => ['organisation' => 'org-a']]);

		return $record;
	}//end record()

	/**
	 * Build the resolver over doubles that record what the property rules were asked.
	 *
	 * @return GraphQLResolver The resolver.
	 */
	private function resolver(): GraphQLResolver {
		$this->objectService = $this->createMock(ObjectService::class);
		$this->getObject = $this->createMock(GetObject::class);

		$permissionHandler = $this->createMock(PermissionHandler::class);
		$permissionHandler->method('hasPermission')->willReturn(true);

		$register = new Register();
		$register->setId(1);
		$register->setSchemas([1]);
		$registerMapper = $this->createMock(RegisterMapper::class);
		$registerMapper->method('findAll')->willReturn([$register]);

		$rbac = $this->createMock(PropertyRbacHandler::class);
		$rbac->method('getUnauthorizedProperties')->willReturnCallback(
			function (Schema $schema, array $object): array {
				$this->seen['update'] = $object;
				return [];
			}
		);
		$rbac->method('filterReadableProperties')->willReturnCallback(
			function (Schema $schema, array $object): array {
				$this->seen['read'] = $object;
				return $object;
			}
		);

		return new GraphQLResolver(
			new GraphQLResolverObjects(
				$this->getObject,
				$this->objectService,
				$this->createMock(RelationHandler::class),
				$registerMapper
			),
			new GraphQLResolverRights(
				$permissionHandler,
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
	 * A schema with id 1.
	 *
	 * @return Schema The schema.
	 */
	private function schema(): Schema {
		$schema = new Schema();
		$schema->setId(1);
		$schema->setSlug('zaak');

		return $schema;
	}//end schema()

	/**
	 * The update check is asked of the stored record's envelope.
	 *
	 * @return void
	 */
	public function testTheUpdateCheckReadsTheStoredRecord(): void {
		$resolver = $this->resolver();
		$this->getObject->method('find')->willReturn($this->record());
		$this->objectService->method('saveObject')->willReturn($this->record());

		$resolver->resolveUpdate(schema: $this->schema(), args: ['id' => 'obj-1', 'input' => ['title' => 'u']]);

		$this->assertSame('org-b', $this->seen['update']['@self']['organisation'] ?? null);
		$this->assertArrayNotHasKey('_organisation', $this->seen['update']);
		$this->assertSame('t', $this->seen['update']['title'] ?? null);
	}//end testTheUpdateCheckReadsTheStoredRecord()

	/**
	 * An update of a record the caller cannot find is not found, and nothing is written.
	 *
	 * @return void
	 */
	public function testAnUpdateOfAnUnknownRecordWritesNothing(): void {
		$resolver = $this->resolver();
		$this->getObject->method('find')->willThrowException(new DoesNotExistException('gone'));
		$this->objectService->expects($this->never())->method('saveObject');

		$this->expectException(Error::class);
		$resolver->resolveUpdate(schema: $this->schema(), args: ['id' => 'obj-1', 'input' => ['title' => 'u']]);
	}//end testAnUpdateOfAnUnknownRecordWritesNothing()

	/**
	 * The read filter of a single object reads the record's own metadata.
	 *
	 * @return void
	 */
	public function testTheReadFilterReadsTheRecord(): void {
		$resolver = $this->resolver();
		$this->getObject->method('find')->willReturn($this->record());

		$resolver->resolveSingle(schema: $this->schema(), args: ['id' => 'obj-1']);

		$this->assertSame('org-b', $this->seen['read']['@self']['organisation'] ?? null);
	}//end testTheReadFilterReadsTheRecord()
}//end class
