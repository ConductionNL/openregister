<?php

/**
 * GET /api/schemas/{id}/indexes lists the indexes on the schema's magic tables.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/modelling-property-index-switch/specs/zoeken-filteren/spec.md#requirement-a-property-can-ask-for-an-index-without-being-a-facet
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <dev@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace Unit\Controller;

use OCA\OpenRegister\Controller\SchemasController;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\Register;
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
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The index list per register, a 404 for an unknown schema, a 500 on a catalogue failure.
 */
class SchemasControllerIndexesTest extends TestCase {

	private SchemaMapper&MockObject $schemaMapper;

	private RegisterMapper&MockObject $registerMapper;

	private MagicMapper&MockObject $magicMapper;

	private SchemasController $controller;

	protected function setUp(): void {
		$this->schemaMapper   = $this->createMock(SchemaMapper::class);
		$this->registerMapper = $this->createMock(RegisterMapper::class);
		$this->magicMapper    = $this->createMock(MagicMapper::class);

		$this->controller = SchemasControllerFactory::create(
			'openregister',
			$this->createMock(IRequest::class),
			$this->createMock(IAppConfig::class),
			$this->schemaMapper,
			$this->registerMapper,
			$this->magicMapper,
			$this->createMock(UploadService::class),
			$this->createMock(AuditTrailMapper::class),
			$this->createMock(OrganisationService::class),
			$this->createMock(SchemaCacheHandler::class),
			$this->createMock(FacetResultCacheClearer::class),
			$this->createMock(SchemaService::class),
			$this->createMock(LoggerInterface::class),
			$this->createMock(\Psr\Container\ContainerInterface::class),
			$this->createMock(SchemaVersioningService::class)
		);
	}

	/**
	 * The zaken schema, found as a catalogue read.
	 *
	 * @return Schema
	 */
	private function zaken(): Schema {
		$schema = new Schema();
		$schema->setId(12);
		$schema->setSlug('zaken');
		$this->schemaMapper->method('find')->with(12, [], true, false)->willReturn($schema);

		return $schema;
	}//end zaken()

	public function testTheIndexesOfEveryRegisterThatCarriesTheSchemaAreListed(): void {
		$schema = $this->zaken();
		$this->registerMapper->method('getAllRegisterIdsWithSchema')->with(12)->willReturn([3, 5]);
		$this->registerMapper->method('find')->willReturnCallback(
			static function (int|string $id, bool $_rbac = true, bool $_multitenancy = true): Register {
				self::assertFalse($_rbac);
				self::assertFalse($_multitenancy);
				$register = new Register();
				$register->setId((int)$id);
				return $register;
			}
		);
		$sort = ['name' => 'r3_registratiedatum_sort_idx', 'property' => 'registratiedatum', 'column' => 'registratiedatum', 'kind' => 'btree', 'flag' => 'indexed'];
		$this->magicMapper->method('tableIndexes')->willReturnCallback(
			static function (Register $register, Schema $given) use ($schema, $sort): array {
				self::assertSame($schema, $given);
				if ($register->getId() === 3) {
					return [$sort];
				}

				return [];
			}
		);

		$response = $this->controller->indexes(12);

		self::assertSame(200, $response->getStatus());
		self::assertSame(['results' => [['register' => 3, 'indexes' => [$sort]], ['register' => 5, 'indexes' => []]]], $response->getData());
	}//end testTheIndexesOfEveryRegisterThatCarriesTheSchemaAreListed()

	public function testAnUnknownSchemaIsNotFound(): void {
		$this->schemaMapper->method('find')->willThrowException(new DoesNotExistException('gone'));

		$response = $this->controller->indexes(99);

		self::assertSame(404, $response->getStatus());
		self::assertSame(['error' => 'Schema not found'], $response->getData());
	}//end testAnUnknownSchemaIsNotFound()

	public function testAnUnreadableCatalogueIsAnError(): void {
		$this->zaken();
		$this->registerMapper->method('getAllRegisterIdsWithSchema')->willReturn([3]);
		$this->registerMapper->method('find')->willReturn(new Register());
		$this->magicMapper->method('tableIndexes')->willThrowException(new RuntimeException('catalogue down'));

		self::assertGreaterThanOrEqual(400, $this->controller->indexes(12)->getStatus());
	}//end testAnUnreadableCatalogueIsAnError()
}//end class
