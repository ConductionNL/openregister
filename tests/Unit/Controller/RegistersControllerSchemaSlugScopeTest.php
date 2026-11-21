<?php

/**
 * A schema slug named under a register route resolves to that register's schema.
 *
 * @category  Test
 * @package   OCA\OpenRegister\Tests\Unit\Controller
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://www.OpenRegister.app
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Controller;

use OCA\OpenRegister\Controller\RegistersController;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Files\Service\UploadService;
use OCA\OpenRegister\Kernel\Contracts\GitHubClientInterface;
use OCA\OpenRegister\Kernel\Contracts\ObjectImporterInterface;
use OCA\OpenRegister\Service\ConfigurationService;
use OCA\OpenRegister\Service\ExportService;
use OCA\OpenRegister\Service\MigrationPackService;
use OCA\OpenRegister\Service\OasService;
use OCA\OpenRegister\Service\RegisterService;
use OCA\OpenRegister\Service\Registers\RegisterCacheHandler;
use OCA\OpenRegister\Service\Serializer\RegisterSerializer;
use OCA\OpenRegister\Tests\Unit\Controller\Support\RegistersControllerFactory;
use OCP\App\IAppManager;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\OpenRegister\Controller\RegistersController
 * @uses   \OCA\OpenRegister\Service\Schemas\SchemaRefInRegister
 */
final class RegistersControllerSchemaSlugScopeTest extends TestCase {

	/** @var SchemaMapper&MockObject */
	private SchemaMapper $schemas;

	/** @var list<mixed> */
	private array $globalIds = [];

	protected function setUp(): void {
		parent::setUp();
		$this->schemas = $this->createMock(SchemaMapper::class);
		$this->schemas->method('find')->willReturnCallback(
			function (mixed $id): Schema {
				$this->globalIds[] = $id;
				return self::schema(22);
			}
		);
	}

	private static function schema(int $id): Schema {
		$schema = new Schema();
		$schema->setId($id);
		$schema->setSlug('agent');

		return $schema;
	}

	private function controller(array $params): RegistersController {
		$register = new Register();
		$register->setId(4);
		$register->setSlug('hermiq');
		$register->setSchemas([36, 37]);
		$registers = $this->createMock(RegisterMapper::class);
		$registers->method('find')->willReturn($register);
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static fn (string $key, mixed $default = null): mixed => $params[$key] ?? $default
		);

		return RegistersControllerFactory::create(
			'openregister',
			$request,
			$this->createMock(RegisterService::class),
			$this->createMock(MagicMapper::class),
			$this->createMock(UploadService::class),
			$this->createMock(LoggerInterface::class),
			$this->createMock(IUserSession::class),
			$this->createMock(ConfigurationService::class),
			$this->createMock(AuditTrailMapper::class),
			$this->createMock(ExportService::class),
			$this->createMock(ObjectImporterInterface::class),
			$this->schemas,
			$registers,
			$this->createMock(GitHubClientInterface::class),
			$this->createMock(IAppManager::class),
			$this->createMock(OasService::class),
			$this->createMock(ContainerInterface::class),
			$this->createMock(IGroupManager::class),
			$this->createMock(RegisterCacheHandler::class),
			new RegisterSerializer($this->createMock(SchemaMapper::class), $this->createMock(LoggerInterface::class)),
			$this->createMock(MigrationPackService::class),
		);
	}

	public function testATemplateSlugIsLookedUpAmongTheRegistersSchemas(): void {
		$this->schemas->expects($this->once())->method('findBySlugInIds')
			->with('agent', [36, 37])->willReturn(self::schema(36));

		$this->controller(params: ['format' => 'csv'])->importTemplate('hermiq', 'agent');

		$this->assertNotContains('agent', $this->globalIds, 'The slug resolved globally, which can name another register\'s schema.');
	}

	public function testATemplateSlugTheRegisterLacksStillResolvesGlobally(): void {
		$this->schemas->expects($this->once())->method('findBySlugInIds')->willReturn(null);

		$this->controller(params: ['format' => 'csv'])->importTemplate('hermiq', 'agent');

		$this->assertSame(['agent'], $this->globalIds);
	}

	public function testANumericTemplateSchemaIdSkipsTheScopedLookup(): void {
		$this->schemas->expects($this->never())->method('findBySlugInIds');

		$this->controller(params: ['format' => 'csv'])->importTemplate('hermiq', '22');

		$this->assertSame(['22'], $this->globalIds);
	}
}
