<?php

/**
 * Characterisation tests for SchemasController::show (semantic roles), ::index
 * (pagination and extend normalisation) and the upload dialect mapping.
 *
 * These pin the behaviour of the existing branches so the methods can be split
 * into named steps without changing what a caller receives.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace Unit\Controller;

use OCA\OpenRegister\Controller\SchemasController;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Exception\SchemaImportException;
use OCA\OpenRegister\Files\Service\UploadService;
use OCA\OpenRegister\Service\OrganisationService;
use OCA\OpenRegister\Service\Schema\SchemaVersioningService;
use OCA\OpenRegister\Service\SchemaImport\GgmUpload;
use OCA\OpenRegister\Service\SchemaImport\ImportedSchema;
use OCA\OpenRegister\Service\SchemaImport\ImportedSchemaDefinition;
use OCA\OpenRegister\Service\SchemaImport\ImportedSchemaHeading;
use OCA\OpenRegister\Service\SchemaImport\SchemaImportService;
use OCA\OpenRegister\Service\Schemas\FacetResultCacheClearer;
use OCA\OpenRegister\Service\Schemas\SchemaCacheHandler;
use OCA\OpenRegister\Service\Schemas\SemanticRoleHandler;
use OCA\OpenRegister\Service\SchemaService;
use OCA\OpenRegister\Tests\Unit\Controller\Support\SchemasControllerFactory;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IAppConfig;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Pins the branches of show, index and applyDialect.
 */
class SchemasControllerReadAndDialectCharacterisationTest extends TestCase {

	private IRequest&MockObject $request;

	private SchemaMapper&MockObject $schemaMapper;

	/**
	 * Request parameters answered by getParam(); unknown keys return the default.
	 *
	 * @var array<string, mixed>
	 */
	private array $params = [];

	/**
	 * The Accept-Language header.
	 */
	private string $acceptLanguage = '';

	protected function setUp(): void {
		parent::setUp();

		$this->request = $this->createMock(IRequest::class);
		$this->request->method('getParam')->willReturnCallback(
			fn (string $key, mixed $default = null) => (array_key_exists($key, $this->params) === true ? $this->params[$key] : $default)
		);
		$this->request->method('getParams')->willReturnCallback(fn () => $this->params);
		$this->request->method('getHeader')->willReturnCallback(fn () => $this->acceptLanguage);
		$this->schemaMapper = $this->createMock(SchemaMapper::class);
	}//end setUp()

	private function controller(
		?SchemaImportService $importService = null,
		?SemanticRoleHandler $roles = null
	): SchemasController {
		$user = $this->createMock(\OCP\IUser::class);
		$user->method('getUID')->willReturn('admin');
		$session = $this->createMock(\OCP\IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$groups = $this->createMock(\OCP\IGroupManager::class);
		$groups->method('isAdmin')->willReturn(true);
		$container = $this->createMock(\Psr\Container\ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			fn ($id) => match ($id) {
				\OCP\IUserSession::class => $session,
				\OCP\IGroupManager::class => $groups,
				default => null,
			}
		);

		return SchemasControllerFactory::create(
			'openregister',
			$this->request,
			$this->createMock(IAppConfig::class),
			$this->schemaMapper,
			$this->createMock(RegisterMapper::class),
			$this->createMock(MagicMapper::class),
			$this->createMock(UploadService::class),
			$this->createMock(AuditTrailMapper::class),
			$this->createMock(OrganisationService::class),
			$this->createMock(SchemaCacheHandler::class),
			$this->createMock(FacetResultCacheClearer::class),
			$this->createMock(SchemaService::class),
			$this->createMock(LoggerInterface::class),
			$container,
			$this->createMock(SchemaVersioningService::class),
			null,
			$importService,
			null,
			$roles
		);
	}//end controller()

	private function schema(int $id): Schema {
		$schema = new Schema();
		$prop = (new \ReflectionClass($schema))->getProperty('id');
		$prop->setAccessible(true);
		$prop->setValue($schema, $id);
		$schema->setTitle('S' . $id);
		$schema->setProperties(['naam' => ['type' => 'string']]);
		return $schema;
	}//end schema()

	private function applyDialect(SchemasController $controller, array $document): array|JSONResponse {
		$method = new \ReflectionMethod(SchemasController::class, 'applyDialect');
		$method->setAccessible(true);
		return $method->invoke($controller, $document);
	}//end applyDialect()

	private function imported(): ImportedSchema {
		return new ImportedSchema(
			new ImportedSchemaHeading('Persoon', 'd'),
			new ImportedSchemaDefinition(['naam' => []], []),
			['dialect' => 'ggm'],
			['x']
		);
	}//end imported()

	// ---- show --------------------------------------------------------------

	public function testShowWithoutRoleHandlerAddsNoRoleKeys(): void {
		$this->schemaMapper->method('find')->willReturn($this->schema(1));
		$this->schemaMapper->method('findExtendedBy')->willReturn(['u-2']);

		$data = $this->controller()->show(1)->getData();

		$this->assertSame(['u-2'], $data['@self']['extendedBy']);
		$this->assertArrayNotHasKey('roles', $data['@self']);
		$this->assertArrayNotHasKey('language', $data['@self']);
		$this->assertArrayNotHasKey('propertyMetadata', $data['@self']);
		$this->assertArrayNotHasKey('stats', $data);
	}//end testShowWithoutRoleHandlerAddsNoRoleKeys()

	public function testShowWithRoleHandlerAddsRolesHelpAndExplicitLanguage(): void {
		$this->params = ['language' => 'en'];
		$this->schemaMapper->method('find')->willReturn($this->schema(1));
		$this->schemaMapper->method('findExtendedBy')->willReturn([]);
		$roles = $this->createMock(SemanticRoleHandler::class);
		$roles->expects($this->once())->method('roles')
			->with(['naam' => ['type' => 'string']])->willReturn(['title' => 'naam']);
		$roles->expects($this->once())->method('helpTexts')
			->with(['naam' => ['type' => 'string']], 'en')->willReturn(['naam' => 'Name']);

		$data = $this->controller(null, $roles)->show(1)->getData();

		$this->assertSame(['title' => 'naam'], $data['@self']['roles']);
		$this->assertSame(['naam' => 'Name'], $data['@self']['help']);
		$this->assertSame('en', $data['@self']['language']);
	}//end testShowWithRoleHandlerAddsRolesHelpAndExplicitLanguage()

	public function testShowWithRoleHandlerTakesTheFirstAcceptLanguage(): void {
		$this->acceptLanguage = 'de-DE;q=0.9, en';
		$this->schemaMapper->method('find')->willReturn($this->schema(1));
		$this->schemaMapper->method('findExtendedBy')->willReturn([]);
		$roles = $this->createMock(SemanticRoleHandler::class);
		$roles->method('roles')->willReturn([]);
		$roles->method('helpTexts')->willReturn([]);

		$data = $this->controller(null, $roles)->show(1)->getData();

		$this->assertSame('de-DE', $data['@self']['language']);
	}//end testShowWithRoleHandlerTakesTheFirstAcceptLanguage()

	public function testShowWithAnEmptyAllOfAddsNoPropertyMetadata(): void {
		$schema = $this->schema(1);
		$schema->setAllOf([]);
		$this->schemaMapper->method('find')->willReturn($schema);
		$this->schemaMapper->method('findExtendedBy')->willReturn([]);
		$this->schemaMapper->expects($this->never())->method('getPropertySourceMetadata');

		$data = $this->controller()->show(1)->getData();

		$this->assertArrayNotHasKey('propertyMetadata', $data['@self']);
	}//end testShowWithAnEmptyAllOfAddsNoPropertyMetadata()

	// ---- index -------------------------------------------------------------

	public function testIndexPassesLimitAndOffsetAsGiven(): void {
		$this->params = ['_limit' => '5', '_offset' => '15', 'filters' => ['slug' => 'a']];
		$this->schemaMapper->expects($this->once())->method('findAll')
			->with(5, 15, ['slug' => 'a'], [], [], [], true, false)
			->willReturn([]);
		$this->schemaMapper->method('findAllExtendedBy')->willReturn([]);

		$this->assertSame(['results' => []], $this->controller()->index()->getData());
	}//end testIndexPassesLimitAndOffsetAsGiven()

	public function testIndexPageWithoutLimitKeepsTheOffset(): void {
		$this->params = ['_page' => '3', '_offset' => '7'];
		$this->schemaMapper->expects($this->once())->method('findAll')
			->with(null, 7, [], [], [], [], true, false)
			->willReturn([]);
		$this->schemaMapper->method('findAllExtendedBy')->willReturn([]);

		$this->controller()->index();
	}//end testIndexPageWithoutLimitKeepsTheOffset()

	public function testIndexPageWithLimitReplacesTheOffset(): void {
		$this->params = ['_page' => '3', '_limit' => '10', '_offset' => '7'];
		$this->schemaMapper->expects($this->once())->method('findAll')
			->with(10, 20, [], [], [], [], true, false)
			->willReturn([]);
		$this->schemaMapper->method('findAllExtendedBy')->willReturn([]);

		$this->controller()->index();
	}//end testIndexPageWithLimitReplacesTheOffset()

	public function testIndexStringExtendIsTreatedAsAList(): void {
		$this->params = ['_extend' => '@self.stats'];
		$this->schemaMapper->method('findAll')->willReturn([$this->schema(4)]);
		$this->schemaMapper->method('findAllExtendedBy')->willReturn([4 => ['u-9']]);
		$this->schemaMapper->method('getRegisterCountPerSchema')->willReturn([4 => 2]);

		$data = $this->controller()->index()->getData();

		$this->assertSame(['u-9'], $data['results'][0]['@self']['extendedBy']);
		$this->assertSame(2, $data['results'][0]['stats']['registers']);
	}//end testIndexStringExtendIsTreatedAsAList()

	public function testIndexWithoutStatsExtendAddsNoStats(): void {
		$this->params = ['_extend' => ['other']];
		$this->schemaMapper->method('findAll')->willReturn([$this->schema(4)]);
		$this->schemaMapper->method('findAllExtendedBy')->willReturn([]);

		$data = $this->controller()->index()->getData();

		$this->assertSame([], $data['results'][0]['@self']['extendedBy']);
		$this->assertArrayNotHasKey('stats', $data['results'][0]);
	}//end testIndexWithoutStatsExtendAddsNoStats()

	// ---- applyDialect ------------------------------------------------------

	public function testWithoutAnImportServiceTheDocumentIsUnchanged(): void {
		$document = ['type' => 'object'];

		$this->assertSame($document, $this->applyDialect($this->controller(), $document));
	}//end testWithoutAnImportServiceTheDocumentIsUnchanged()

	/**
	 * @dataProvider passThroughDialects
	 */
	public function testJsonSchemaAndOpenapiPassThrough(string $dialect): void {
		$import = $this->createMock(SchemaImportService::class);
		$import->method('resolveUploadDialect')->willReturn($dialect);
		$import->expects($this->never())->method('import');
		$document = ['type' => 'object'];

		$this->assertSame($document, $this->applyDialect($this->controller($import), $document));
	}//end testJsonSchemaAndOpenapiPassThrough()

	public static function passThroughDialects(): array {
		return ['json-schema' => ['json-schema'], 'openapi' => ['openapi']];
	}//end passThroughDialects()

	/**
	 * @dataProvider explicitDialectParams
	 */
	public function testTheExplicitDialectIsPassedOnlyWhenANonEmptyString(mixed $param, ?string $expected): void {
		$this->params = ['dialect' => $param];
		$import = $this->createMock(SchemaImportService::class);
		$import->expects($this->once())->method('resolveUploadDialect')
			->with(['a' => 1], $expected)->willReturn('json-schema');

		$this->applyDialect($this->controller($import), ['a' => 1]);
	}//end testTheExplicitDialectIsPassedOnlyWhenANonEmptyString()

	public static function explicitDialectParams(): array {
		return [
			'string' => ['ggm', 'ggm'],
			'empty string' => ['', null],
			'not a string' => [['ggm'], null],
			'absent' => [null, null],
		];
	}//end explicitDialectParams()

	public function testAnUnresolvableDialectAnswersWithTheExceptionStatus(): void {
		$import = $this->createMock(SchemaImportService::class);
		$import->method('resolveUploadDialect')->willThrowException(new SchemaImportException('Unknown dialect', 422));

		$result = $this->applyDialect($this->controller($import), []);

		$this->assertInstanceOf(JSONResponse::class, $result);
		$this->assertSame(422, $result->getStatus());
		$this->assertSame(['error' => 'Unknown dialect'], $result->getData());
	}//end testAnUnresolvableDialectAnswersWithTheExceptionStatus()

	public function testAStandardsDialectWithoutAReferenceIsABadRequest(): void {
		$import = $this->createMock(SchemaImportService::class);
		$import->method('resolveUploadDialect')->willReturn('schema.org');
		$import->expects($this->never())->method('import');

		$result = $this->applyDialect($this->controller($import), ['@type' => ['Person']]);

		$this->assertInstanceOf(JSONResponse::class, $result);
		$this->assertSame(400, $result->getStatus());
		$this->assertSame(
			['error' => 'A "reference" identifying the schema.org type/objecttype to import is required.'],
			$result->getData()
		);
	}//end testAStandardsDialectWithoutAReferenceIsABadRequest()

	public function testASchemaOrgDocumentTypeIsTheReference(): void {
		$import = $this->createMock(SchemaImportService::class);
		$import->method('resolveUploadDialect')->willReturn('schema.org');
		$import->expects($this->once())->method('import')
			->with('schema.org', 'Person', $this->anything())->willReturn($this->imported());

		$result = $this->applyDialect($this->controller($import), ['@type' => 'Person']);

		$this->assertSame($this->imported()->toSchemaArray(), $result);
	}//end testASchemaOrgDocumentTypeIsTheReference()

	public function testTheReferenceParameterWinsOverTheDocumentType(): void {
		$this->params = ['reference' => 'Organization'];
		$import = $this->createMock(SchemaImportService::class);
		$import->method('resolveUploadDialect')->willReturn('schema.org');
		$import->expects($this->once())->method('import')
			->with('schema.org', 'Organization', $this->anything())->willReturn($this->imported());

		$this->applyDialect($this->controller($import), ['@type' => 'Person']);
	}//end testTheReferenceParameterWinsOverTheDocumentType()

	public function testAGgmIntermediateIsImportedAsAnUpload(): void {
		$this->params = ['reference' => 'Persoon'];
		$document = ['objecttypen' => []];
		$import = $this->createMock(SchemaImportService::class);
		$import->method('resolveUploadDialect')->willReturn('ggm');
		$import->expects($this->never())->method('import');
		$import->expects($this->once())->method('importGgmUpload')
			->with(
				$this->callback(
					fn (GgmUpload $u) => $u->normalised === $document && $u->reference === 'Persoon' && $u->sourceLabel === 'upload'
				),
				$this->anything()
			)->willReturn($this->imported());

		$this->assertSame($this->imported()->toSchemaArray(), $this->applyDialect($this->controller($import), $document));
	}//end testAGgmIntermediateIsImportedAsAnUpload()

	public function testAGgmDocumentWithoutObjecttypenIsImportedByReference(): void {
		$this->params = ['reference' => 'Persoon'];
		$import = $this->createMock(SchemaImportService::class);
		$import->method('resolveUploadDialect')->willReturn('ggm');
		$import->expects($this->never())->method('importGgmUpload');
		$import->expects($this->once())->method('import')
			->with('ggm', 'Persoon', $this->anything())->willReturn($this->imported());

		$this->applyDialect($this->controller($import), ['other' => 1]);
	}//end testAGgmDocumentWithoutObjecttypenIsImportedByReference()

	public function testAFailedImportAnswersWithTheExceptionStatus(): void {
		$this->params = ['reference' => 'Persoon'];
		$import = $this->createMock(SchemaImportService::class);
		$import->method('resolveUploadDialect')->willReturn('ggm');
		$import->method('importGgmUpload')->willThrowException(new SchemaImportException('No such type', 404));

		$result = $this->applyDialect($this->controller($import), ['objecttypen' => []]);

		$this->assertInstanceOf(JSONResponse::class, $result);
		$this->assertSame(404, $result->getStatus());
		$this->assertSame(['error' => 'No such type'], $result->getData());
	}//end testAFailedImportAnswersWithTheExceptionStatus()

	public function testAFailedImportByReferenceAnswersWithTheExceptionStatus(): void {
		$this->params = ['reference' => 'Thing'];
		$import = $this->createMock(SchemaImportService::class);
		$import->method('resolveUploadDialect')->willReturn('schema.org');
		$import->method('import')->willThrowException(new SchemaImportException('Gone', 410));

		$result = $this->applyDialect($this->controller($import), []);

		$this->assertSame(410, $result->getStatus());
	}//end testAFailedImportByReferenceAnswersWithTheExceptionStatus()
}//end class
