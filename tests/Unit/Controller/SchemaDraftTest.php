<?php

/**
 * A schema edit held as a draft until it is published (modelling-schema-draft).
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

namespace Unit\Controller;

use OCA\OpenRegister\Controller\SchemasController;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaChangelogMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Db\SchemaRunEntryMapper;
use OCA\OpenRegister\Db\SchemaRunMapper;
use OCA\OpenRegister\Service\OrganisationService;
use OCA\OpenRegister\Service\Schema\SchemaDiffService;
use OCA\OpenRegister\Service\Schema\SchemaVersioningService;
use OCA\OpenRegister\Service\Schemas\FacetCacheHandler;
use OCA\OpenRegister\Service\Schemas\SchemaCacheHandler;
use OCA\OpenRegister\Service\SchemaService;
use OCA\OpenRegister\Service\UploadService;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use Opis\JsonSchema\Validator;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * The versioning service, the diff service and the Schema entity are the real
 * classes; only persistence is a double, and it hands back the same entity the
 * way the mapper does. Records are judged by Opis against the definition the
 * schema holds, which is what validation reads: a draft that leaked into
 * `properties` or `required` would refuse the record here.
 */
class SchemaDraftTest extends TestCase {

	/** @var IRequest&MockObject */
	private IRequest $request;

	/** @var SchemaMapper&MockObject */
	private SchemaMapper $schemaMapper;

	/** @var SchemaChangelogMapper&MockObject */
	private SchemaChangelogMapper $changelogMapper;

	private SchemasController $controller;

	private Schema $schema;

	/** @var array<string, mixed> */
	private array $params = [];

	protected function setUp(): void {
		$this->schema = new Schema();
		$this->schema->setId(7);
		$this->schema->setTitle('Contact');
		$this->schema->setVersion('1.0.0');
		$this->schema->setProperties(
			[
				'name' => ['type' => 'string'],
				'email' => ['type' => 'string'],
			]
		);
		$this->schema->setRequired(['name']);

		$this->request = $this->createMock(IRequest::class);
		$this->request->method('getParams')->willReturnCallback(fn () => $this->params);
		$this->request->method('getParam')->willReturnCallback(
			fn (string $key, $default = null) => ($this->params[$key] ?? $default)
		);

		$this->schemaMapper = $this->createMock(SchemaMapper::class);
		$this->schemaMapper->method('find')->willReturnCallback(fn () => $this->schema);
		$this->schemaMapper->method('update')->willReturnCallback(fn ($entity) => $entity);
		$this->schemaMapper->method('updateFromArray')->willReturnCallback(
			function (int $id, array $object): Schema {
				$this->schema->hydrate(object: $object);
				return $this->schema;
			}
		);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('admin');
		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn($user);
		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('isAdmin')->willReturn(true);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			fn ($id) => match ($id) {
				IUserSession::class => $userSession,
				IGroupManager::class => $groupManager,
				default => null,
			}
		);

		$this->changelogMapper = $this->createMock(SchemaChangelogMapper::class);

		$versioning = new SchemaVersioningService(
			new SchemaDiffService(),
			$this->changelogMapper,
			$this->createMock(SchemaRunMapper::class),
			$this->createMock(SchemaRunEntryMapper::class),
			$userSession,
			$this->createMock(LoggerInterface::class)
		);

		$this->controller = new SchemasController(
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
			$this->createMock(FacetCacheHandler::class),
			$this->createMock(SchemaService::class),
			$this->createMock(LoggerInterface::class),
			$container,
			$versioning
		);
	}//end setUp()

	/**
	 * Whether a record passes the definition the schema holds right now.
	 *
	 * @param array<string, mixed> $record The record.
	 *
	 * @return bool
	 */
	private function accepts(array $record): bool {
		$definition = [
			'type' => 'object',
			'properties' => $this->schema->getProperties(),
			'required' => $this->schema->getRequired(),
		];
		$result = (new Validator())->validate(
			json_decode(json_encode($record)),
			json_decode(json_encode($definition))
		);
		return $result->isValid();
	}//end accepts()

	/**
	 * The edit that makes `email` required, as the editor sends it.
	 *
	 * @return array<string, mixed>
	 */
	private function edit(): array {
		return [
			'title' => 'Contact',
			'properties' => [
				'name' => ['type' => 'string'],
				'email' => ['type' => 'string'],
			],
			'required' => ['name', 'email'],
		];
	}//end edit()

	public function testADraftDoesNotRefuseLiveRecords(): void {
		$this->params = array_merge($this->edit(), ['draft' => 'true']);
		$this->changelogMapper->expects($this->never())->method('createFromArray');

		$response = $this->controller->update(id: 7);

		$this->assertSame(200, $response->getStatus());
		$this->assertSame(['name'], $this->schema->getRequired());
		$this->assertSame('1.0.0', $this->schema->getVersion());
		$this->assertSame(['name', 'email'], $this->schema->getDraft()['required']);
		$this->assertTrue($this->accepts(['name' => 'Ada']), 'a draft must not refuse a live record');
		$this->assertSame(['name', 'email'], $response->getData()->jsonSerialize()['draft']['required']);
	}//end testADraftDoesNotRefuseLiveRecords()

	public function testPublishingAppliesTheDraftOnceWithOneChangelogEntry(): void {
		$this->params = array_merge($this->edit(), ['draft' => 'true']);
		$this->controller->update(id: 7);

		$this->changelogMapper->expects($this->once())->method('createFromArray');
		$this->params = ['acknowledgeBreaking' => 'true'];

		$response = $this->controller->publishDraft(id: 7);

		$this->assertSame(200, $response->getStatus());
		$this->assertSame(['name', 'email'], $this->schema->getRequired());
		$this->assertSame('2.0.0', $this->schema->getVersion());
		$this->assertNull($this->schema->getDraft());
		$this->assertFalse($this->accepts(['name' => 'Ada']), 'after publish the new rule applies');
	}//end testPublishingAppliesTheDraftOnceWithOneChangelogEntry()

	public function testPublishingABreakingDraftUnacknowledgedIsRefusedAndKeepsIt(): void {
		$this->params = array_merge($this->edit(), ['draft' => 'true']);
		$this->controller->update(id: 7);

		$this->params = [];
		$response = $this->controller->publishDraft(id: 7);

		$this->assertSame(409, $response->getStatus());
		$this->assertSame(['name'], $this->schema->getRequired());
		$this->assertNotNull($this->schema->getDraft());
	}//end testPublishingABreakingDraftUnacknowledgedIsRefusedAndKeepsIt()

	public function testDiscardRemovesTheDraftAndLeavesTheSchema(): void {
		$this->params = array_merge($this->edit(), ['draft' => 'true']);
		$this->controller->update(id: 7);

		$response = $this->controller->discardDraft(id: 7);

		$this->assertSame(200, $response->getStatus());
		$this->assertNull($this->schema->getDraft());
		$this->assertSame(['name'], $this->schema->getRequired());
		$this->assertSame('1.0.0', $this->schema->getVersion());
	}//end testDiscardRemovesTheDraftAndLeavesTheSchema()

	public function testPublishingWithoutADraftIsAConflict(): void {
		$response = $this->controller->publishDraft(id: 7);

		$this->assertSame(409, $response->getStatus());
	}//end testPublishingWithoutADraftIsAConflict()

	public function testAnImportedDescriptorCannotCarryADraft(): void {
		$schema = new Schema();
		$schema->hydrate(object: ['title' => 'X', 'draft' => ['required' => ['a']]]);

		$this->assertNull($schema->getDraft());
	}//end testAnImportedDescriptorCannotCarryADraft()
}//end class
