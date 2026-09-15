<?php

/**
 * Contract test for the list-presentation endpoint on SchemasController.
 *
 * The generic list surface asks this once per schema and renders whatever comes
 * back, so the two answers worth pinning are a schema that declared its columns
 * and a schema id that resolves to nothing.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Controller
 *
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/objects-as-the-hinge-between-cases/specs/linked-entity-types/spec.md
 */

declare(strict_types=1);

namespace Unit\Controller;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Controller\SchemasController;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Hinge\ListPresentationResolver;
use OCA\OpenRegister\Service\OrganisationService;
use OCA\OpenRegister\Service\Schema\SchemaVersioningService;
use OCA\OpenRegister\Service\Schemas\FacetCacheHandler;
use OCA\OpenRegister\Service\Schemas\SchemaCacheHandler;
use OCA\OpenRegister\Service\SchemaService;
use OCA\OpenRegister\Service\UploadService;
use OCP\IAppConfig;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

final class SchemasControllerListPresentationTest extends TestCase {

	/**
	 * The controller under test.
	 *
	 * @var SchemasController
	 */
	private SchemasController $controller;

	/**
	 * Schema lookup, the only collaborator the endpoint reads through.
	 *
	 * @var SchemaMapper&MockObject
	 */
	private SchemaMapper&MockObject $schemaMapper;

	protected function setUp(): void {
		parent::setUp();

		$this->schemaMapper = $this->createMock(SchemaMapper::class);

		$this->controller = new SchemasController(
			'openregister',
			$this->createMock(IRequest::class),
			$this->createMock(IAppConfig::class),
			$this->schemaMapper,
			$this->createMock(RegisterMapper::class),
			$this->createMock(MagicMapper::class),
			$this->createMock(UploadService::class),
			$this->createMock(AuditTrailMapper::class),
			$this->createMock(OrganisationService::class),
			$this->createMock(SchemaCacheHandler::class),
			$this->createMock(FacetCacheHandler::class),
			$this->createMock(SchemaService::class),
			$this->createMock(LoggerInterface::class),
			$this->createMock(ContainerInterface::class),
			$this->createMock(SchemaVersioningService::class)
		);
	}

	public function testADeclaredListSurfaceComesBackWithTheSchemaItBelongsTo(): void {
		$schema = new Schema();
		$schema->setId(14);
		$schema->setSlug('melding');
		$schema->setTitle('Melding');
		$schema->setProperties([
			'onderwerp' => ['type' => 'string', 'title' => 'Onderwerp'],
			'status' => ['type' => 'string'],
		]);
		$schema->setConfiguration([
			Schema::LIST_ANNOTATION => [
				'columns' => ['onderwerp', ['property' => 'status', 'label' => 'Stand van zaken']],
				'searchFields' => ['onderwerp'],
			],
		]);

		$this->schemaMapper->method('find')->willReturn($schema);

		$response = $this->controller->listPresentation(14, new ListPresentationResolver());
		$body = $response->getData();

		$this->assertSame(200, $response->getStatus());
		$this->assertTrue($body['declared']);
		$this->assertSame(['onderwerp', 'status'], array_column($body['columns'], 'property'));
		$this->assertSame(['onderwerp'], $body['searchFields']);
		$this->assertSame(['id' => '14', 'slug' => 'melding', 'title' => 'Melding'], $body['schema']);
	}

	public function testASchemaDeclaringNoneKeepsTodaysColumnsAndSaysSo(): void {
		$schema = new Schema();
		$schema->setId(15);
		$schema->setSlug('adres');
		$schema->setTitle('Adres');
		$schema->setProperties(['straat' => ['type' => 'string']]);

		$this->schemaMapper->method('find')->willReturn($schema);

		$body = $this->controller->listPresentation(15, new ListPresentationResolver())->getData();

		$this->assertFalse($body['declared']);
		$this->assertSame(ListPresentationResolver::DEFAULT_COLUMNS, $body['columns']);
	}

	public function testASchemaThatIsNotThereIsRefusedRatherThanAnswered(): void {
		$this->schemaMapper->method('find')->willThrowException(new \RuntimeException('gone'));

		$response = $this->controller->listPresentation('nope', new ListPresentationResolver());

		$this->assertSame(404, $response->getStatus());
		$this->assertSame(['error' => 'Schema not found'], $response->getData());
	}
}
