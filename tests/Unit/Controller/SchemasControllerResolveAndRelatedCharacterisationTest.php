<?php

/**
 * Characterisation tests for SchemasController::resolveByImplements and the
 * outgoing references of ::related.
 *
 * Pins every branch of the semantic-type discovery endpoint (unresolved,
 * failing, resolved with and without a register) and the outgoing-reference
 * walk, so both can be split into named steps without changing the payload.
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

namespace Unit\Controller;

use OCA\OpenRegister\Controller\SchemasController;
use OCA\OpenRegister\Controller\Support\SchemasControllerServices;
use OCA\OpenRegister\Controller\Support\SchemaSemantics;
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
use OCA\OpenRegister\Service\SemanticTypeResolverInterface;
use OCA\OpenRegister\Tests\Unit\Controller\Support\SchemasControllerFactory;
use OCP\IAppConfig;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Pins resolveByImplements and the outgoing half of related.
 */
class SchemasControllerResolveAndRelatedCharacterisationTest extends TestCase {

	/**
	 * Request parameters.
	 *
	 * @var array<string, mixed>
	 */
	private array $params = [];

	private SchemaMapper&MockObject $schemaMapper;

	private LoggerInterface&MockObject $logger;

	protected function setUp(): void {
		$this->schemaMapper = $this->createMock(SchemaMapper::class);
		$this->logger = $this->createMock(LoggerInterface::class);
	}//end setUp()

	private function controller(?SemanticTypeResolverInterface $resolver = null): SchemasController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			fn (string $key, mixed $default = null) => (array_key_exists($key, $this->params) === true ? $this->params[$key] : $default)
		);
		$args = SchemasControllerFactory::args(
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
			$this->createMock(SchemaService::class),
			$this->logger,
			$this->createMock(\Psr\Container\ContainerInterface::class),
			$this->createMock(SchemaVersioningService::class)
		);
		$services = $args[2];
		$args[2] = new SchemasControllerServices(
			mappers: $services->mappers,
			schemas: $services->schemas,
			semantics: new SchemaSemantics(semanticTypeResolver: $resolver),
			platform: $services->platform
		);
		return new SchemasController(...$args);
	}//end controller()

	private function schema(int $id, string $slug): Schema {
		$schema = new Schema();
		$prop = (new \ReflectionClass($schema))->getProperty('id');
		$prop->setAccessible(true);
		$prop->setValue($schema, $id);
		$schema->setSlug($slug);
		return $schema;
	}//end schema()

	private function register(?string $application): Register {
		$register = new Register();
		$prop = (new \ReflectionClass($register))->getProperty('id');
		$prop->setAccessible(true);
		$prop->setValue($register, 9);
		$register->setSlug('reg');
		$register->setApplication($application);
		return $register;
	}//end register()

	// ---- resolveByImplements --------------------------------------------

	public function testWithoutAUriNothingIsResolved(): void {
		$resolver = $this->createMock(SemanticTypeResolverInterface::class);
		$resolver->expects($this->never())->method('resolveSchemaByImplements');

		$this->assertSame(['resolved' => false], $this->controller($resolver)->resolveByImplements()->getData());
	}//end testWithoutAUriNothingIsResolved()

	public function testWithoutAResolverNothingIsResolved(): void {
		$this->params = ['uri' => 'https://schema.org/Organization'];

		$this->assertSame(['resolved' => false], $this->controller()->resolveByImplements()->getData());
	}//end testWithoutAResolverNothingIsResolved()

	public function testAFailingResolutionIsLoggedAndDegrades(): void {
		$this->params = ['uri' => 'https://schema.org/Organization'];
		$resolver = $this->createMock(SemanticTypeResolverInterface::class);
		$resolver->method('resolveSchemaByImplements')->willThrowException(new RuntimeException('boom'));
		$this->logger->expects($this->once())->method('warning')->with(
			'[SchemasController] resolveByImplements failed: boom',
			$this->callback(fn (array $c) => $c['uri'] === 'https://schema.org/Organization')
		);

		$response = $this->controller($resolver)->resolveByImplements();

		$this->assertSame(200, $response->getStatus());
		$this->assertSame(['resolved' => false], $response->getData());
	}//end testAFailingResolutionIsLoggedAndDegrades()

	public function testNoProviderIsUnresolved(): void {
		$this->params = ['uri' => 'https://schema.org/Organization'];
		$resolver = $this->createMock(SemanticTypeResolverInterface::class);
		$resolver->method('resolveSchemaByImplements')->willReturn(null);
		$resolver->expects($this->never())->method('findRegisterForSchema');

		$this->assertSame(['resolved' => false], $this->controller($resolver)->resolveByImplements()->getData());
	}//end testNoProviderIsUnresolved()

	/**
	 * @dataProvider registerHints
	 */
	public function testTheRegisterHintIsPassedOnlyWhenNumeric(mixed $hint, ?int $expected): void {
		$this->params = ['uri' => 'https://schema.org/Organization', 'register' => $hint];
		$resolver = $this->createMock(SemanticTypeResolverInterface::class);
		$resolver->expects($this->once())->method('resolveSchemaByImplements')
			->with('https://schema.org/Organization', $expected)->willReturn(null);

		$this->controller($resolver)->resolveByImplements();
	}//end testTheRegisterHintIsPassedOnlyWhenNumeric()

	public static function registerHints(): array {
		return [
			'numeric string' => ['12', 12],
			'integer' => [7, 7],
			'not numeric' => ['abc', null],
			'absent' => [null, null],
		];
	}//end registerHints()

	public function testAResolvedSchemaWithAnAppOwnedRegister(): void {
		$this->params = ['uri' => 'https://schema.org/Organization'];
		$schema = $this->schema(4, 'organisatie');
		$resolver = $this->createMock(SemanticTypeResolverInterface::class);
		$resolver->method('resolveSchemaByImplements')->willReturn($schema);
		$resolver->method('findRegisterForSchema')->with($schema)->willReturn($this->register('crm'));

		$this->assertSame(
			['resolved' => true, 'schema' => 4, 'schemaSlug' => 'organisatie', 'register' => 9, 'registerSlug' => 'reg', 'appId' => 'crm'],
			$this->controller($resolver)->resolveByImplements()->getData()
		);
	}//end testAResolvedSchemaWithAnAppOwnedRegister()

	public function testAResolvedSchemaWithoutARegister(): void {
		$this->params = ['uri' => 'https://schema.org/Organization'];
		$resolver = $this->createMock(SemanticTypeResolverInterface::class);
		$resolver->method('resolveSchemaByImplements')->willReturn($this->schema(4, 'organisatie'));
		$resolver->method('findRegisterForSchema')->willReturn(null);

		$this->assertSame(
			['resolved' => true, 'schema' => 4, 'schemaSlug' => 'organisatie', 'register' => null, 'registerSlug' => null],
			$this->controller($resolver)->resolveByImplements()->getData()
		);
	}//end testAResolvedSchemaWithoutARegister()

	// ---- related: outgoing references ------------------------------------

	public function testOutgoingReferencesSkipSelfAndKeepEachReferencedSchemaOnce(): void {
		$target = $this->schema(1, 'zaak');
		$target->setProperties(['type' => ['$ref' => 'zaaktype']]);
		$referenced = $this->schema(2, 'zaaktype');
		$other = $this->schema(3, 'besluit');
		$this->schemaMapper->method('getRelated')->willReturn([$other]);
		$this->schemaMapper->method('find')->willReturn($target);
		$this->schemaMapper->method('findAll')->willReturn([$target, $referenced, $other, $referenced]);
		$asked = [];
		$this->schemaMapper->method('hasReferenceToSchema')->willReturnCallback(
			function (array $properties, Schema $schema) use (&$asked): bool {
				$asked[] = $schema->getId();
				return $schema->getId() === 2;
			}
		);

		$data = $this->controller()->related(1)->getData();

		$this->assertSame([2, 3, 2], $asked);
		$this->assertSame([2], array_column($data['outgoing'], 'id'));
		$this->assertSame([3], array_column($data['incoming'], 'id'));
		$this->assertSame(2, $data['total']);
	}//end testOutgoingReferencesSkipSelfAndKeepEachReferencedSchemaOnce()
}//end class
