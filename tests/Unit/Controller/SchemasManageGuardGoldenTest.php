<?php

/**
 * The 404 and 403 the schema-mutating routes answer before they touch a
 * schema: an unknown id, and a caller who may not manage the schema.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Controller
 *
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 */

declare(strict_types=1);

// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Controller;

use OCA\OpenRegister\Controller\SchemasController;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Files\Service\UploadService;
use OCA\OpenRegister\Service\OrganisationService;
use OCA\OpenRegister\Service\Schema\SchemaVersioningService;
use OCA\OpenRegister\Service\Schemas\FacetResultCacheClearer;
use OCA\OpenRegister\Service\Schemas\SchemaCacheHandler;
use OCA\OpenRegister\Service\SchemaService;
use OCA\OpenRegister\Tests\Unit\Controller\Support\SchemasControllerFactory;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IAppConfig;
use OCP\IRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @coversNothing
 */
final class SchemasManageGuardGoldenTest extends TestCase {
	/**
	 * The request parameters.
	 *
	 * @var array<string, mixed>
	 */
	private array $params = [];

	/**
	 * The schema mapper.
	 *
	 * @var SchemaMapper&MockObject
	 */
	private SchemaMapper&MockObject $schemaMapper;

	/**
	 * The schema service, which a refused request must never reach.
	 *
	 * @var SchemaService&MockObject
	 */
	private SchemaService&MockObject $schemaService;

	/**
	 * The controller; its container cannot resolve the session, so nobody may manage a schema.
	 *
	 * @return SchemasController
	 */
	private function controller(): SchemasController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParams')->willReturnCallback(fn (): array => $this->params);
		$request->method('getParam')->willReturnCallback(fn (string $key, $default = null) => ($this->params[$key] ?? $default));
		$this->schemaMapper = $this->createMock(SchemaMapper::class);
		$this->schemaMapper->expects($this->never())->method('update');
		$this->schemaMapper->expects($this->never())->method('updateFromArray');
		$this->schemaService = $this->createMock(SchemaService::class);
		$this->schemaService->expects($this->never())->method($this->anything());
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willThrowException(new RuntimeException('no session'));

		return SchemasControllerFactory::create(
			'openregister',
			$request,
			$this->createMock(IAppConfig::class),
			$this->schemaMapper,
			$this->createMock(RegisterMapper::class),
			$this->createMock(MagicMapper::class),
			$this->createMock(UploadService::class),
			$this->createMock(AuditTrailMapper::class),
			$this->createMock(OrganisationService::class),
			$this->createMock(SchemaCacheHandler::class),
			$this->createMock(FacetResultCacheClearer::class),
			$this->schemaService,
			$this->createMock(LoggerInterface::class),
			$container,
			$this->createMock(SchemaVersioningService::class)
		);
	}//end controller()

	/**
	 * The guarded routes, with the request each sends.
	 *
	 * @return array<string, array{string, array<string, mixed>}>
	 */
	public static function routes(): array {
		$edit = ['title' => 'Renamed', 'properties' => ['name' => ['type' => 'string']]];

		return [
			'update' => ['update', $edit],
			'update as draft' => ['update', $edit + ['draft' => 'true']],
			'discard draft' => ['discardDraft', []],
			'explore' => ['explore', []],
			'update from exploration' => ['updateFromExploration', ['properties' => ['name' => ['type' => 'string']]]],
		];
	}//end routes()

	/**
	 * An unknown schema id answers 404.
	 *
	 * @param string               $route  The route method.
	 * @param array<string, mixed> $params The request parameters.
	 *
	 * @return void
	 */
	#[DataProvider('routes')]
	public function testAnUnknownSchemaIs404(string $route, array $params): void {
		$this->params = $params;
		$controller = $this->controller();
		$this->schemaMapper->expects($this->once())->method('find')->with(7)->willThrowException(new DoesNotExistException('gone'));

		$response = $controller->$route(7);

		$this->assertSame([404, ['error' => 'Schema not found']], [$response->getStatus(), $response->getData()]);
	}//end testAnUnknownSchemaIs404()

	/**
	 * A caller who may not manage the schema answers 403 and changes nothing.
	 *
	 * @param string               $route  The route method.
	 * @param array<string, mixed> $params The request parameters.
	 *
	 * @return void
	 */
	#[DataProvider('routes')]
	public function testANonManagerIs403(string $route, array $params): void {
		$this->params = $params;
		$controller = $this->controller();
		$schema = new Schema();
		$schema->setId(7);
		$this->schemaMapper->expects($this->once())->method('find')->with(7)->willReturn($schema);

		$response = $controller->$route(7);

		$this->assertSame(
			[403, ['error' => 'User does not have permission to manage this schema']],
			[$response->getStatus(), $response->getData()]
		);
	}//end testANonManagerIs403()
}//end class
