<?php

/**
 * Unit tests for GraphQLContextFactory and the service bundles it reads.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Test
 * @package   OCA\OpenRegister\Tests\Unit\Search\Service\GraphQL
 * @author    Conduction B.V. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://OpenRegister.app
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Search\Service\GraphQL;

use OCA\OpenRegister\Db\AuditTrailMapperInterface;
use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Db\SchemaCatalogInterface;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCA\OpenRegister\Search\Service\GraphQL\GraphQLAccessServices;
use OCA\OpenRegister\Search\Service\GraphQL\GraphQLContextFactory;
use OCA\OpenRegister\Search\Service\GraphQL\GraphQLDataServices;
use OCA\OpenRegister\Search\Service\GraphQL\SchemaGenerator;
use OCA\OpenRegister\Service\Object\PermissionHandlerInterface;
use OCA\OpenRegister\Service\PropertyRbacHandlerInterface;
use OCP\IRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * The resolver context carries exactly the stores, the access services and the request it was built from.
 */
#[CoversClass(GraphQLContextFactory::class)]
#[CoversClass(GraphQLDataServices::class)]
#[UsesClass(GraphQLAccessServices::class)]
#[UsesClass(\OCA\OpenRegister\Kernel\Contracts\Value\BulkBatchDefaults::class)]
#[UsesClass(\OCA\OpenRegister\Kernel\Contracts\Value\BulkSaveOptions::class)]
#[UsesClass(\OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindTarget::class)]
#[UsesClass(\OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions::class)]
#[UsesClass(\OCA\OpenRegister\Kernel\Contracts\Value\SaveActor::class)]
#[UsesClass(\OCA\OpenRegister\Kernel\Contracts\Value\SavePolicy::class)]
#[UsesClass(\OCA\OpenRegister\Service\Object\PermissionCheckOptions::class)]
final class GraphQLContextFactoryTest extends TestCase {

	public function testTheContextCarriesEveryServiceTheResolversRead(): void {
		$objects   = $this->createMock(ObjectOperationsInterface::class);
		$registers = $this->createMock(RegisterMapperInterface::class);
		$schemas   = $this->createMock(SchemaMapperInterface::class);
		$catalog   = $this->createMock(SchemaCatalogInterface::class);
		$catalog->expects($this->once())->method('registers')->willReturn($registers);
		$catalog->expects($this->once())->method('schemas')->willReturn($schemas);

		$permissions = $this->createMock(PermissionHandlerInterface::class);
		$propertyRbac = $this->createMock(PropertyRbacHandlerInterface::class);
		$audit = $this->createMock(AuditTrailMapperInterface::class);
		$request = $this->createMock(IRequest::class);
		$generator = $this->createMock(SchemaGenerator::class);

		$factory = new GraphQLContextFactory(
			data: new GraphQLDataServices(objectService: $objects, catalog: $catalog),
			access: new GraphQLAccessServices(permissionHandler: $permissions, propertyRbac: $propertyRbac, auditTrailMapper: $audit),
			request: $request,
		);

		$context = $factory->create(schemaGenerator: $generator, operationName: 'ListZaken');

		$this->assertSame(
			[
				'objectService',
				'permissionHandler',
				'propertyRbac',
				'auditTrailMapper',
				'registerMapper',
				'schemaMapper',
				'schemaGenerator',
				'operationName',
				'request',
				'errors',
			],
			array_keys($context)
		);
		$this->assertSame($objects, $context['objectService']);
		$this->assertSame($permissions, $context['permissionHandler']);
		$this->assertSame($propertyRbac, $context['propertyRbac']);
		$this->assertSame($audit, $context['auditTrailMapper']);
		$this->assertSame($registers, $context['registerMapper']);
		$this->assertSame($schemas, $context['schemaMapper']);
		$this->assertSame($generator, $context['schemaGenerator']);
		$this->assertSame('ListZaken', $context['operationName']);
		$this->assertSame($request, $context['request']);
		$this->assertSame([], $context['errors']);
	}//end testTheContextCarriesEveryServiceTheResolversRead()

	public function testAnAnonymousOperationKeepsANullName(): void {
		$catalog = $this->createMock(SchemaCatalogInterface::class);
		$catalog->method('registers')->willReturn($this->createMock(RegisterMapperInterface::class));
		$catalog->method('schemas')->willReturn($this->createMock(SchemaMapperInterface::class));

		$factory = new GraphQLContextFactory(
			data: new GraphQLDataServices(objectService: $this->createMock(ObjectOperationsInterface::class), catalog: $catalog),
			access: new GraphQLAccessServices(
				permissionHandler: $this->createMock(PermissionHandlerInterface::class),
				propertyRbac: $this->createMock(PropertyRbacHandlerInterface::class),
				auditTrailMapper: $this->createMock(AuditTrailMapperInterface::class),
			),
			request: $this->createMock(IRequest::class),
		);

		$first  = $factory->create(schemaGenerator: $this->createMock(SchemaGenerator::class), operationName: null);
		$second = $factory->create(schemaGenerator: $this->createMock(SchemaGenerator::class), operationName: null);

		$this->assertNull($first['operationName']);
		$this->assertSame([], $second['errors']);
		$this->assertNotSame($first['schemaGenerator'], $second['schemaGenerator']);
	}//end testAnAnonymousOperationKeepsANullName()
}//end class
