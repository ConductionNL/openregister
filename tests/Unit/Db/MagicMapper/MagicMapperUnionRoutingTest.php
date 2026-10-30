<?php

/**
 * Which cross-table search route a query takes.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Db\MagicMapper
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @spec openspec/changes/query-referenced-schema-rows/specs/zoeken-filteren/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db\MagicMapper;

use Exception;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\DateTimeNormalizer;
use OCA\OpenRegister\Service\SettingsService;
use OCP\DB\IPreparedStatement;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * `MagicMapper::shouldUseUnionQuery()`: related-row filters need the sequential route.
 *
 * @covers \OCA\OpenRegister\Db\MagicMapper
 * @uses \OCA\OpenRegister\Db\MagicMapper\MagicRbacHandler
 */
class MagicMapperUnionRoutingTest extends TestCase {

	private IDBConnection&MockObject $db;

	private LoggerInterface&MockObject $logger;

	private SchemaMapper&MockObject $schemaMapper;

	private RegisterMapper&MockObject $registerMapper;

	private IUserSession&MockObject $userSession;

	private IGroupManager&MockObject $groupManager;

	protected function setUp(): void {
		parent::setUp();
		$this->db = $this->createMock(IDBConnection::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->schemaMapper = $this->createMock(SchemaMapper::class);
		$this->registerMapper = $this->createMock(RegisterMapper::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->groupManager = $this->createMock(IGroupManager::class);
	}//end setUp()

	/**
	 * Build a MagicMapper with the shared mock dependencies.
	 *
	 * @return MagicMapper
	 */
	private function makeMapper(): MagicMapper {
		$container = $this->makeContainer();

		return new MagicMapper(
			$this->db,
			$this->schemaMapper,
			$this->registerMapper,
			$this->createMock(IConfig::class),
			$this->createMock(IEventDispatcher::class),
			$this->userSession,
			$this->groupManager,
			$this->createMock(IUserManager::class),
			$this->createMock(IAppConfig::class),
			$this->logger,
			$this->createMock(SettingsService::class),
			$container
		);
	}//end makeMapper()

	/**
	 * Build a DI container that resolves the lazily-fetched collaborators
	 * MagicMapper pulls during construction (DateTimeNormalizer, ConditionMatcher,
	 * SchemaTypeConverter), matching the production container contract.
	 *
	 * @return ContainerInterface&MockObject
	 */
	private function makeContainer(): ContainerInterface {
		$dateTimeNormalizer = $this->createMock(DateTimeNormalizer::class);
		$conditionMatcher = $this->createMock(\OCA\OpenRegister\Service\ConditionMatcher::class);
		$schemaTypeConverter = $this->createMock(\OCA\OpenRegister\Service\Object\SchemaTypeConverter::class);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			function (string $id) use ($dateTimeNormalizer, $conditionMatcher, $schemaTypeConverter) {
				if ($id === DateTimeNormalizer::class) {
					return $dateTimeNormalizer;
				}
				if ($id === \OCA\OpenRegister\Service\ConditionMatcher::class) {
					return $conditionMatcher;
				}
				if ($id === \OCA\OpenRegister\Service\Object\SchemaTypeConverter::class) {
					return $schemaTypeConverter;
				}
				return null;
			}
		);
		return $container;
	}//end makeContainer()

	/**
	 * A plain cross-table search keeps the UNION route; `_related`, `_referenced` and aggregations leave it.
	 *
	 * The raw-SQL UNION builder refuses the related-row keys, which would be an
	 * uncaught exception on the controller routes; the sequential route builds
	 * them as EXISTS subqueries instead.
	 *
	 * @return void
	 */
	public function testARelatedRowFilterTakesTheSequentialRoute(): void {
		$route = new \ReflectionMethod(MagicMapper::class, 'shouldUseUnionQuery');
		$mapper = $this->makeMapper();
		$related = ['caseProperty' => ['case' => ['value' => 'x']]];

		$this->assertTrue($route->invoke($mapper, ['_limit' => 10]));
		$this->assertFalse($route->invoke($mapper, ['_related' => $related]));
		$this->assertFalse($route->invoke($mapper, ['_referenced' => $related]));
		$this->assertFalse($route->invoke($mapper, ['_aggregations' => ['x']]));
	}//end testARelatedRowFilterTakesTheSequentialRoute()
}//end class
