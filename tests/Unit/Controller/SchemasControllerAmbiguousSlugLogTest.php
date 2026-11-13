<?php

/**
 * Unit tests for the ambiguity log of GET /api/schemas/{id} without `?register=`.
 *
 * Without a register the global lookup still answers, as before. When the slug
 * matches more than one schema the read leaves a debug line naming every
 * candidate id, so the next investigation starts from evidence; an unambiguous
 * slug, a numeric id and a scoped read stay silent, and a failing check never
 * breaks the read.
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
 *
 * @spec openspec/changes/register-scoped-schema-slug-resolution/specs/schema-slug-resolution/spec.md
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace Unit\Controller;

use OCA\OpenRegister\Controller\SchemasController;
use OCA\OpenRegister\Tests\Unit\Controller\Support\SchemasControllerFactory;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\OrganisationService;
use OCA\OpenRegister\Service\Schema\SchemaVersioningService;
use OCA\OpenRegister\Service\Schemas\FacetResultCacheClearer;
use OCA\OpenRegister\Service\Schemas\SchemaCacheHandler;
use OCA\OpenRegister\Service\SchemaService;
use OCA\OpenRegister\Files\Service\UploadService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IAppConfig;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\OpenRegister\Controller\SchemasController
 */
class SchemasControllerAmbiguousSlugLogTest extends TestCase {

	/**
	 * The request double.
	 *
	 * @var IRequest
	 */
	private IRequest $request;

	/**
	 * The schema mapper double.
	 *
	 * @var SchemaMapper
	 */
	private SchemaMapper $schemaMapper;

	/**
	 * The register mapper double.
	 *
	 * @var RegisterMapper
	 */
	private RegisterMapper $registerMapper;

	/**
	 * The logger the ambiguity line goes to.
	 *
	 * @var LoggerInterface
	 */
	private LoggerInterface $logger;

	/**
	 * The controller under test.
	 *
	 * @var SchemasController
	 */
	private SchemasController $controller;

	protected function setUp(): void {
		$this->request        = $this->createMock(IRequest::class);
		$this->schemaMapper   = $this->createMock(SchemaMapper::class);
		$this->registerMapper = $this->createMock(RegisterMapper::class);
		$this->logger         = $this->createMock(LoggerInterface::class);

		$userSession = $this->createMock(\OCP\IUserSession::class);
		$user        = $this->createMock(\OCP\IUser::class);
		$user->method('getUID')->willReturn('admin');
		$userSession->method('getUser')->willReturn($user);

		$groupManager = $this->createMock(\OCP\IGroupManager::class);
		$groupManager->method('getUserGroupIds')->willReturn(['admin']);
		$groupManager->method('isAdmin')->willReturn(true);

		$container = $this->createMock(\Psr\Container\ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			function ($id) use ($userSession, $groupManager) {
				if ($id === \OCP\IUserSession::class) {
					return $userSession;
				}

				if ($id === \OCP\IGroupManager::class) {
					return $groupManager;
				}

				return null;
			}
		);

		$this->controller = SchemasControllerFactory::create(
			'openregister',
			$this->request,
			$this->createMock(IAppConfig::class),
			$this->schemaMapper,
			$this->registerMapper,
			$this->createMock(MagicMapper::class),
			$this->createMock(UploadService::class),
			$this->createMock(AuditTrailMapper::class),
			$this->createMock(OrganisationService::class),
			$this->createMock(SchemaCacheHandler::class),
			$this->createMock(FacetResultCacheClearer::class),
			$this->createMock(SchemaService::class),
			$this->logger,
			$container,
			$this->createMock(SchemaVersioningService::class)
		);
	}//end setUp()

	private function withNoRegister(): void {
		$this->request->method('getParam')->willReturnCallback(
			static fn (string $key, $default = null) => $default
		);
	}//end withNoRegister()

	private function schema(int $id, string $slug = 'agent'): Schema {
		$schema = new Schema();
		$schema->setId($id);
		$schema->setSlug($slug);
		$schema->setTitle('Agent');

		return $schema;
	}//end schema()

	public function testAnAmbiguousSlugLogsEveryCandidateIdAndStillAnswers(): void {
		$this->withNoRegister();
		$this->schemaMapper->method('find')->willReturn($this->schema(22));
		$this->schemaMapper->method('findExtendedBy')->willReturn([]);
		$this->schemaMapper->expects($this->once())
			->method('findAll')
			->willReturn([$this->schema(22), $this->schema(11)]);
		$this->logger->expects($this->once())
			->method('debug')
			->with(
				$this->stringContains('slug "agent" is ambiguous across 2 schemas and no ?register= was supplied; returned id 22'),
				['candidateIds' => [22, 11]]
			);

		$response = $this->controller->show('agent');

		$this->assertSame(200, $response->getStatus());
		$this->assertSame(22, $response->getData()['id']);
	}//end testAnAmbiguousSlugLogsEveryCandidateIdAndStillAnswers()

	public function testAnUnambiguousSlugStaysSilent(): void {
		$this->withNoRegister();
		$this->schemaMapper->method('find')->willReturn($this->schema(11));
		$this->schemaMapper->method('findExtendedBy')->willReturn([]);
		$this->schemaMapper->method('findAll')->willReturn([$this->schema(11)]);
		$this->logger->expects($this->never())->method('debug');

		$this->assertSame(200, $this->controller->show('agent')->getStatus());
	}//end testAnUnambiguousSlugStaysSilent()

	public function testANumericIdIsNeverCheckedForAmbiguity(): void {
		$this->withNoRegister();
		$this->schemaMapper->method('find')->willReturn($this->schema(22));
		$this->schemaMapper->method('findExtendedBy')->willReturn([]);
		$this->schemaMapper->expects($this->never())->method('findAll');
		$this->logger->expects($this->never())->method('debug');

		$this->assertSame(200, $this->controller->show('22')->getStatus());
	}//end testANumericIdIsNeverCheckedForAmbiguity()

	public function testAFailingCheckIsLoggedAndTheReadStillAnswers(): void {
		$this->withNoRegister();
		$this->schemaMapper->method('find')->willReturn($this->schema(22));
		$this->schemaMapper->method('findExtendedBy')->willReturn([]);
		$this->schemaMapper->method('findAll')->willThrowException(new \Exception('db gone'));
		$this->logger->expects($this->once())
			->method('debug')
			->with('[SchemasController] ambiguity check failed: db gone');

		$this->assertSame(200, $this->controller->show('agent')->getStatus());
	}//end testAFailingCheckIsLoggedAndTheReadStillAnswers()
}//end class
