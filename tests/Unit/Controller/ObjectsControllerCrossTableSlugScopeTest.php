<?php

/**
 * A cross-table search names each register's own schema for a slug two registers share.
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
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

namespace OCA\OpenRegister\Tests\Unit\Controller;

use OCA\OpenRegister\Controller\ObjectsController;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Deletion\DeletionWindowService;
use OCA\OpenRegister\Service\ExportService;
use OCA\OpenRegister\Service\ImportService;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\WebhookService;
use OCA\OpenRegister\Tests\Unit\Controller\Support\ObjectsControllerFactory;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use ReflectionMethod;

/**
 * @covers \OCA\OpenRegister\Controller\ObjectsController
 * @uses   \OCA\OpenRegister\Db\Register
 * @uses   \OCA\OpenRegister\Db\Schema
 * @uses \OCA\OpenRegister\Controller\Support\ControllerSession
 * @uses \OCA\OpenRegister\Controller\Support\GeoServices
 * @uses \OCA\OpenRegister\Controller\Support\LinkedDataServices
 * @uses \OCA\OpenRegister\Controller\Support\ObjectGovernanceServices
 * @uses \OCA\OpenRegister\Controller\Support\ObjectStore
 * @uses \OCA\OpenRegister\Controller\Support\ObjectTransfer
 * @uses \OCA\OpenRegister\Controller\Support\ObjectsControllerServices
 * @uses \OCA\OpenRegister\Controller\Support\ObjectsExtensions
 */
final class ObjectsControllerCrossTableSlugScopeTest extends TestCase {

	/** @var SchemaMapper&MockObject */
	private SchemaMapper $schemas;

	protected function setUp(): void {
		parent::setUp();
		$this->schemas = $this->createMock(SchemaMapper::class);
		$this->schemas->method('findBySlugInIds')->willReturnCallback(
			static function (string $slug, array $schemaIds): ?Schema {
				if ($slug !== 'agent' || in_array(36, $schemaIds, true) === false) {
					return null;
				}

				return self::schema(36);
			}
		);
	}

	private static function schema(int $id): Schema {
		$schema = new Schema();
		$schema->setId($id);
		$schema->setSlug('agent');

		return $schema;
	}

	private static function register(array $schemaIds): Register {
		$register = new Register();
		$register->setSchemas($schemaIds);

		return $register;
	}

	/**
	 * The schemas the controller pairs with one register.
	 *
	 * @param Register              $register The register.
	 * @param array<string, Schema> $resolved The schemas resolved up front.
	 *
	 * @return array<string, Schema> The schemas by reference.
	 */
	private function schemasOf(Register $register, array $resolved): array {
		$controller = ObjectsControllerFactory::create(
			'openregister',
			$this->createMock(IRequest::class),
			$this->createMock(IAppConfig::class),
			$this->createMock(IAppManager::class),
			$this->createMock(ContainerInterface::class),
			$this->createMock(RegisterMapper::class),
			$this->schemas,
			$this->createMock(AuditTrailMapper::class),
			$this->createMock(ObjectService::class),
			$this->createMock(IUserSession::class),
			$this->createMock(IGroupManager::class),
			$this->createMock(ExportService::class),
			$this->createMock(ImportService::class),
			$this->createMock(WebhookService::class),
			$this->createMock(LoggerInterface::class),
			windowService: $this->createMock(DeletionWindowService::class)
		);

		return (new ReflectionMethod(ObjectsController::class, 'crossTableSchemasOf'))->invoke($controller, $register, $resolved);
	}

	public function testASharedSlugNamesTheRegistersOwnSchema(): void {
		// The up-front global lookup answered openbuild's `agent` (22).
		$found = $this->schemasOf(register: self::register([36]), resolved: ['agent' => self::schema(22)]);

		$this->assertSame(36, $found['agent']->getId());
	}

	public function testARegisterWithoutTheSlugKeepsTheGlobalAnswer(): void {
		$found = $this->schemasOf(register: self::register([22]), resolved: ['agent' => self::schema(22)]);

		$this->assertSame(22, $found['agent']->getId());
	}

	public function testANumericReferenceIsNeverLookedUpAgain(): void {
		$this->schemas->expects($this->never())->method('findBySlugInIds');

		$found = $this->schemasOf(register: self::register([36]), resolved: ['22' => self::schema(22)]);

		$this->assertSame(22, $found['22']->getId());
	}
}
