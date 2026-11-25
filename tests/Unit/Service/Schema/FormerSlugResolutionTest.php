<?php

/**
 * A renamed schema keeps answering to its former slug: in its register, on re-import, and with a signal on the way.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @spec openspec/changes/modelling-rename-without-loss/specs/schema-migration/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Schema;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Controller\Support\FormerSlugSignal;
use OCA\OpenRegister\Controller\Trait\ResolvesRegisterAndSchemaTrait;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaCatalog;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Db\ConfigurationMapper;
use OCA\OpenRegister\Db\MappingMapper;
use OCA\OpenRegister\Exception\SchemaNotInRegisterException;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCA\OpenRegister\Kernel\Contracts\RegisterSchemaRef;
use OCA\OpenRegister\Service\Configuration\ConfigurationImportRecords;
use OCA\OpenRegister\Service\Configuration\ImportedSchemaLookup;
use OCA\OpenRegister\Service\Configuration\ImportHandler;
use OCA\OpenRegister\Service\Configuration\SchemaImportOptions;
use OCA\OpenRegister\Service\Configuration\SchemaImportSteps;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\RegisterScopedSchemaResolver;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use ReflectionMethod;

/**
 * @covers \OCA\OpenRegister\Controller\Support\FormerSlugSignal
 * @covers \OCA\OpenRegister\Service\RegisterScopedSchemaResolver
 * @covers \OCA\OpenRegister\Service\Configuration\ImportHandler
 */
class FormerSlugResolutionTest extends TestCase {
	private static function renamed(): Schema {
		$schema = new Schema();
		$schema->setId(7);
		$schema->setSlug('client');
		$schema->setFormerSlugs(['customer', 'klant']);

		return $schema;
	}//end renamed()

	private static function register(): Register {
		$register = new Register();
		$register->setId(18);
		$register->setSlug('crm');
		$register->setSchemas([7, 9]);

		return $register;
	}//end register()

	public function testAFormerSlugResolvesInsideTheRegisterAfterTheCurrentSlugsMiss(): void {
		$schemas = $this->createMock(SchemaMapper::class);
		$schemas->method('findInIds')->willReturn(null);
		$schemas->expects(self::once())->method('findByFormerSlugInIds')->with('customer', [7, 9])->willReturn(self::renamed());

		$resolver = new RegisterScopedSchemaResolver($this->createMock(RegisterMapper::class), $schemas);

		self::assertSame(7, $resolver->resolveSchemaWithin(self::register(), 'customer')->getId());
	}//end testAFormerSlugResolvesInsideTheRegisterAfterTheCurrentSlugsMiss()

	public function testACurrentSlugWinsAndTheFormerLookupIsNeverAsked(): void {
		$current = new Schema();
		$current->setId(9);
		$current->setSlug('customer');
		$schemas = $this->createMock(SchemaMapper::class);
		$schemas->method('findInIds')->willReturn($current);
		$schemas->expects(self::never())->method('findByFormerSlugInIds');

		$resolver = new RegisterScopedSchemaResolver($this->createMock(RegisterMapper::class), $schemas);

		self::assertSame(9, $resolver->resolveSchemaWithin(self::register(), 'customer')->getId());
	}//end testACurrentSlugWinsAndTheFormerLookupIsNeverAsked()

	public function testAnIdIsNeverLookedUpAsAFormerSlugAndAnUnknownSlugIsStillRefused(): void {
		$schemas = $this->createMock(SchemaMapper::class);
		$schemas->method('findInIds')->willReturn(null);
		$schemas->method('find')->willThrowException(new \OCP\AppFramework\Db\DoesNotExistException('gone'));
		$schemas->method('findByFormerSlugInIds')->willReturnCallback(
			static function (string $slug): ?Schema {
				self::assertNotSame('123', $slug, 'an id is not a slug');
				return null;
			}
		);
		$resolver = new RegisterScopedSchemaResolver($this->createMock(RegisterMapper::class), $schemas);

		try {
			$resolver->resolveSchemaWithin(self::register(), '123');
			self::fail('an absent id is refused');
		} catch (SchemaNotInRegisterException $e) {
			self::assertStringContainsString('123', $e->getMessage());
		}

		$this->expectException(SchemaNotInRegisterException::class);
		$resolver->resolveSchemaWithin(self::register(), 'nobody');
	}//end testAnIdIsNeverLookedUpAsAFormerSlugAndAnUnknownSlugIsStillRefused()

	public function testAFormerSlugCarriesDeprecationAndASuccessorLink(): void {
		self::assertSame(
			['Deprecation' => 'true', 'Link' => '</api/objects/crm/client>; rel="successor-version"'],
			FormerSlugSignal::headers(register: 'crm', schema: 'customer', resolved: self::renamed())
		);
	}//end testAFormerSlugCarriesDeprecationAndASuccessorLink()

	public function testTheCurrentSlugAnIdAndAnUnresolvedSchemaCarryNoSignal(): void {
		self::assertSame([], FormerSlugSignal::headers(register: 'crm', schema: 'client', resolved: self::renamed()));
		self::assertSame([], FormerSlugSignal::headers(register: 'crm', schema: '7', resolved: self::renamed()));
		self::assertSame([], FormerSlugSignal::headers(register: 'crm', schema: 'customer', resolved: null));
	}//end testTheCurrentSlugAnIdAndAnUnresolvedSchemaCarryNoSignal()

	public function testTheSignalIsAddedToTheResponse(): void {
		$response = FormerSlugSignal::apply(
			response: new JSONResponse(data: []),
			headers: FormerSlugSignal::headers(register: '18', schema: 'klant', resolved: self::renamed())
		);

		self::assertSame('true', $response->getHeaders()['Deprecation']);
		self::assertSame('</api/objects/18/client>; rel="successor-version"', $response->getHeaders()['Link']);
	}//end testTheSignalIsAddedToTheResponse()

	public function testThePathResolutionRecordsTheSignalForTheController(): void {
		$service = $this->createMock(ObjectOperationsInterface::class);
		$service->method('setRegister')->willReturn($service);
		$service->method('setSchema')->willReturn($service);
		$service->method('getCurrentSchemaEntity')->willReturn(self::renamed());

		$consumer = new class {
			use ResolvesRegisterAndSchemaTrait;

			public function resolve(string $schema, ObjectOperationsInterface $service): array {
				$this->resolveRegisterSchemaRef(ref: new RegisterSchemaRef(register: 'crm', schema: $schema), objectService: $service);

				return $this->formerSlugHeaders;
			}//end resolve()
		};

		self::assertSame('true', $consumer->resolve(schema: 'customer', service: $service)['Deprecation']);
		self::assertSame([], $consumer->resolve(schema: 'client', service: $service), 'a later current-slug request clears it');
	}//end testThePathResolutionRecordsTheSignalForTheController()

	public function testAReImportWithTheOldSlugFindsTheRenamedSchemaInTheRegister(): void {
		$schemas = $this->createMock(SchemaMapper::class);
		$schemas->method('findBySlugInIds')->willReturn(null);
		$schemas->expects(self::once())->method('findByFormerSlugInIds')->with('customer', [7, 9])->willReturn(self::renamed());
		$schemas->expects(self::never())->method('createFromArray');

		$found = $this->resolveForImport(schemas: $schemas, slug: 'customer');

		self::assertSame(7, $found?->getId());
	}//end testAReImportWithTheOldSlugFindsTheRenamedSchemaInTheRegister()

	public function testAReImportWithACurrentSlugNeverAsksForFormerSlugs(): void {
		$current = new Schema();
		$current->setId(9);
		$current->setSlug('customer');
		$schemas = $this->createMock(SchemaMapper::class);
		$schemas->method('findBySlugInIds')->willReturn($current);
		$schemas->expects(self::never())->method('findByFormerSlugInIds');

		self::assertSame(9, $this->resolveForImport(schemas: $schemas, slug: 'customer')?->getId());
	}//end testAReImportWithACurrentSlugNeverAsksForFormerSlugs()

	private function resolveForImport(SchemaMapper $schemas, string $slug): ?Schema {
		$handler = new ImportHandler(
			catalog: new SchemaCatalog(registers: $this->createMock(RegisterMapper::class), schemas: $schemas, tables: $this->createMock(MagicMapper::class)),
			records: new ConfigurationImportRecords(
				configurations: $this->createMock(ConfigurationMapper::class),
				mappings: $this->createMock(MappingMapper::class),
				appConfig: $this->createMock(IAppConfig::class)
			),
			schemaSteps: new SchemaImportSteps(),
			logger: new NullLogger(),
			appDataPath: '/tmp',
			objectService: $this->createMock(ObjectService::class)
		);
		$method = new ReflectionMethod(ImportHandler::class, 'resolveExistingSchemaForImport');

		return $method->invoke(
			$handler,
			new ImportedSchemaLookup(data: ['slug' => $slug], registerSchemaIds: [7, 9], options: new SchemaImportOptions(appId: 'crm-app'))
		);
	}//end resolveForImport()
}//end class
