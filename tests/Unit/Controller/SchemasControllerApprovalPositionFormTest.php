<?php

/**
 * The schema API refuses an approval position form no approver could fill in.
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
 * @spec openspec/changes/approval-position-task-form/specs/flow-approval-consolidation/spec.md#requirement-an-approval-position-may-declare-the-form-its-task-presents
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <dev@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace Unit\Controller;

use OCA\OpenRegister\Controller\SchemasController;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Files\Service\UploadService;
use OCA\OpenRegister\Service\JsonLd\JsonLdContextService;
use OCA\OpenRegister\Service\Lifecycle\TransitionEngine;
use OCA\OpenRegister\Service\OrganisationService;
use OCA\OpenRegister\Service\Schema\SchemaVersioningService;
use OCA\OpenRegister\Service\Schemas\FacetResultCacheClearer;
use OCA\OpenRegister\Service\Schemas\SchemaCacheHandler;
use OCA\OpenRegister\Service\SchemaService;
use OCA\OpenRegister\Service\Task\ApprovalPositionFormGuard;
use OCA\OpenRegister\Service\Task\TaskFormReader;
use OCA\OpenRegister\Service\Task\TaskFormSources;
use OCA\OpenRegister\Tests\Unit\Controller\Support\SchemasControllerFactory;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Asserted from the caller: create() reaches the guard and answers 422.
 *
 * @spec openspec/changes/approval-position-task-form/specs/flow-approval-consolidation/spec.md#requirement-an-approval-position-may-declare-the-form-its-task-presents
 */
class SchemasControllerApprovalPositionFormTest extends TestCase {

	private IRequest $request;

	private SchemaMapper $schemaMapper;

	private SchemasController $controller;

	/** @var array<int, string> */
	private array $resolved = [];

	protected function setUp(): void {
		$this->request      = $this->createMock(IRequest::class);
		$this->schemaMapper = $this->createMock(SchemaMapper::class);

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(
			static fn (string $text, array $parameters = []): string => ($parameters === [] ? $text : vsprintf($text, $parameters))
		);
		$reader = new TaskFormReader(
			sources: new TaskFormSources(schemas: $this->createMock(SchemaMapper::class), engine: $this->createMock(TransitionEngine::class)),
			apps: $this->createMock(IAppManager::class),
			l10n: $l10n
		);
		$guard = new ApprovalPositionFormGuard(forms: $reader, l10n: $l10n);

		$user = $this->createMock(\OCP\IUser::class);
		$user->method('getUID')->willReturn('admin');
		$userSession = $this->createMock(\OCP\IUserSession::class);
		$userSession->method('getUser')->willReturn($user);
		$groupManager = $this->createMock(\OCP\IGroupManager::class);
		$groupManager->method('getUserGroupIds')->willReturn(['admin']);
		$groupManager->method('isAdmin')->willReturn(true);
		$services = [
			ApprovalPositionFormGuard::class => $guard,
			\OCP\IUserSession::class => $userSession,
			\OCP\IGroupManager::class => $groupManager,
		];

		$container = $this->createMock(\Psr\Container\ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			function ($id) use ($services) {
				$this->resolved[] = (string)$id;
				return ($services[$id] ?? null);
			}
		);

		$urlGenerator = $this->createMock(IURLGenerator::class);
		$this->controller = SchemasControllerFactory::create(
			'openregister',
			$this->request,
			$this->createMock(IAppConfig::class),
			$this->schemaMapper,
			$this->createMock(\OCA\OpenRegister\Db\RegisterMapper::class),
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
			new JsonLdContextService($urlGenerator)
		);
	}

	/**
	 * A schema body whose one approval position asks for this field.
	 *
	 * @param string $field The field the form asks for.
	 *
	 * @return array<string, mixed>
	 */
	private static function body(string $field): array {
		return [
			'title' => 'Subsidie',
			'slug' => 'subsidie',
			'properties' => [
				'goedgekeurdBedrag' => ['type' => 'number'],
				'besluitnummer' => ['type' => 'string', 'readOnly' => true],
			],
			'configuration' => [
				'x-openregister-approval-chains' => [
					'budget' => [
						'transition' => 'approve',
						'approvers' => [['role' => 'budgethouder', 'form' => ['kind' => 'fields', 'fields' => [['name' => $field, 'required' => true]]]]],
					],
				],
			],
		];
	}//end body()

	public function testAReadOnlyFieldIsRefusedWhenTheSchemaIsSaved(): void {
		$this->request->method('getParams')->willReturn(self::body(field: 'besluitnummer'));
		$this->schemaMapper->expects($this->never())->method('createFromArray');

		$response = $this->controller->create();

		$this->assertSame(422, $response->getStatus());
		$this->assertSame(
			[
				'Approval chain "budget", position 1 (budgethouder): Field "besluitnummer" of schema "subsidie" cannot be asked for: '
					. 'the schema marks it read-only, so a submitted value would be refused.',
			],
			$response->getData()['errors']
		);
		$this->assertSame($response->getData()['errors'][0], $response->getData()['error']);
	}//end testAReadOnlyFieldIsRefusedWhenTheSchemaIsSaved()

	public function testARenderableFieldIsSaved(): void {
		$this->request->method('getParams')->willReturn(self::body(field: 'goedgekeurdBedrag'));
		$schema = new Schema();
		$schema->setId(1);
		$this->schemaMapper->expects($this->once())->method('createFromArray')->willReturn($schema);

		$this->assertSame(201, $this->controller->create()->getStatus());
		$this->assertContains(ApprovalPositionFormGuard::class, $this->resolved);
	}//end testARenderableFieldIsSaved()

	public function testABodyWithoutPositionFormsNeverReachesTheGuard(): void {
		$body = self::body(field: 'besluitnummer');
		unset($body['configuration']['x-openregister-approval-chains']['budget']['approvers'][0]['form']);
		$this->request->method('getParams')->willReturn($body);
		$schema = new Schema();
		$schema->setId(1);
		$this->schemaMapper->method('createFromArray')->willReturn($schema);

		$this->assertSame(201, $this->controller->create()->getStatus());
		$this->assertNotContains(ApprovalPositionFormGuard::class, $this->resolved);
	}//end testABodyWithoutPositionFormsNeverReachesTheGuard()
}//end class
