<?php

/**
 * SchemasController and a schema's classification and catalogue block: the save refusal, the list filter, the anonymous view.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/modelling-type-catalogue-metadata/specs/schema-catalogue-metadata/spec.md
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
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
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * An unknown classification is a 400 naming the field; the list filters only on a vocabulary value; an anonymous caller never sees the contact.
 */
final class SchemasControllerCatalogueTest extends TestCase {

	private IRequest&MockObject $request;

	private SchemaMapper&MockObject $schemas;

	private ?IUser $user = null;

	/**
	 * Request parameters.
	 *
	 * @var array<string, mixed>
	 */
	private array $params = [];

	protected function setUp(): void {
		$this->request = $this->createMock(IRequest::class);
		$this->request->method('getParams')->willReturnCallback(fn (): array => $this->params);
		$this->request->method('getParam')->willReturnCallback(fn (string $key, mixed $default = null): mixed => ($this->params[$key] ?? $default));
		$this->schemas = $this->createMock(SchemaMapper::class);
		$this->schemas->method('findAllExtendedBy')->willReturn([]);
		$this->schemas->method('findExtendedBy')->willReturn([]);
		$this->user = $this->createMock(IUser::class);
		$this->user->method('getUID')->willReturn('admin');
	}

	private function controller(): SchemasController {
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturnCallback(fn (): ?IUser => $this->user);
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('isAdmin')->willReturnCallback(fn (): bool => $this->user !== null);
		$groups->method('getUserGroupIds')->willReturn(['admin']);
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static fn (string $id): ?object => match ($id) {
				IUserSession::class => $session,
				IGroupManager::class => $groups,
				default => null,
			}
		);

		return SchemasControllerFactory::create(
			'openregister',
			$this->request,
			$this->createMock(IAppConfig::class),
			$this->schemas,
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
			$this->createMock(SchemaVersioningService::class)
		);
	}

	private static function described(): Schema {
		$schema = new Schema();
		$schema->hydrate(
			[
				'id' => 7,
				'title' => 'Meldingen',
				'authorization' => ['read' => ['public']],
				'classification' => 'internal',
				'catalogue' => ['sourceSystem' => 'Meldingen app', 'contact' => ['name' => 'Team data', 'email' => 'data@voorbeeld.nl']],
			]
		);

		return $schema;
	}

	public function testCreatingWithAnUnknownClassificationIsA400NamingIt(): void {
		$this->params = ['title' => 'Meldingen', 'classification' => 'secret'];
		$this->schemas->expects($this->never())->method('createFromArray');

		$response = $this->controller()->create();

		$this->assertSame(400, $response->getStatus());
		$this->assertSame('classification', $response->getData()['field']);
	}

	public function testCreatingWithAMalformedContactIsA400NamingIt(): void {
		$this->params = ['title' => 'Meldingen', 'catalogue' => ['contact' => ['email' => 'nope'], 'updateFrequency' => 'daily']];
		$this->schemas->expects($this->never())->method('createFromArray');

		$response = $this->controller()->create();

		$this->assertSame(400, $response->getStatus());
		$this->assertSame('catalogue.contact.email', $response->getData()['field']);
	}

	public function testTheListFiltersOnAKnownClassification(): void {
		$this->params = ['classification' => 'internal'];
		$this->schemas->expects($this->once())->method('findAll')
			->with($this->anything(), $this->anything(), ['classification' => 'internal'])
			->willReturn([self::described()]);

		$response = $this->controller()->index();

		$this->assertSame(200, $response->getStatus());
		$this->assertSame('internal', $response->getData()['results'][0]['classification']);
	}

	public function testTheFiltersFormIsCheckedToo(): void {
		$this->params = ['filters' => ['classification' => 'secret', 'title' => 'x']];
		$this->schemas->expects($this->never())->method('findAll');

		$response = $this->controller()->index();

		$this->assertSame(400, $response->getStatus());
		$this->assertSame('classification', $response->getData()['field']);
	}

	public function testOtherFiltersPassAsBefore(): void {
		$this->params = ['filters' => ['title' => 'x']];
		$this->schemas->expects($this->once())->method('findAll')
			->with($this->anything(), $this->anything(), ['title' => 'x'])
			->willReturn([]);

		$this->assertSame(200, $this->controller()->index()->getStatus());
	}

	public function testASignedInReaderSeesTheContact(): void {
		$this->schemas->method('findAll')->willReturn([self::described()]);

		$schema = $this->controller()->index()->getData()['results'][0];

		$this->assertSame('data@voorbeeld.nl', $schema['catalogue']['contact']['email']);
	}

	public function testAnAnonymousReaderSeesNoContactInTheListOrTheDetail(): void {
		$this->user = null;
		$this->schemas->method('findAll')->willReturn([self::described()]);
		$this->schemas->method('find')->willReturn(self::described());
		$controller = $this->controller();

		$listed = $controller->index()->getData()['results'][0];
		$shown  = $controller->show(7)->getData();

		foreach ([$listed, $shown] as $schema) {
			$this->assertSame('internal', $schema['classification']);
			$this->assertSame('Meldingen app', $schema['catalogue']['sourceSystem']);
			$this->assertArrayNotHasKey('contact', $schema['catalogue']);
		}
	}
}
