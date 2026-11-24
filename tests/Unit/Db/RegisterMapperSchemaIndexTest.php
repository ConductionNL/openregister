<?php

/**
 * RegisterMapper reads the registers of a schema from one read per request.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Db
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db;

use OCA\OpenRegister\Db\OrganisationMapper;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\RegisterMapperServices;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Db\Tenancy\TenancyServices;
use OCA\OpenRegister\Tests\Support\ResultRowReaderTrait;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IAppConfig;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * The schema to register index is read once and answers every schema from it.
 */
class RegisterMapperSchemaIndexTest extends TestCase {
	use ResultRowReaderTrait;

	/**
	 * How often the registers table was read.
	 *
	 * @var int
	 */
	private int $reads = 0;

	/**
	 * The mapper over three registers: 3 lists [5, 7], 1 lists ["7", 9], 2 lists [7, 7].
	 *
	 * @return RegisterMapper
	 */
	private function mapper(): RegisterMapper {
		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturnCallback(function (): IQueryBuilder {
			$qb = $this->createMock(IQueryBuilder::class);
			$qb->method('select')->willReturn($qb);
			$qb->method('from')->willReturn($qb);
			$qb->method('executeQuery')->willReturnCallback(function (): IResult {
				$this->reads++;
				$rows = [
					['id' => 3, 'schemas' => '[5,7]'],
					['id' => 1, 'schemas' => '["7",9]'],
					['id' => 2, 'schemas' => '[7,7]'],
				];
				$result = $this->createMock(IResult::class);
				$this->stubRowReader($result, static function () use (&$rows) {
					return (array_shift($rows) ?? false);
				});
				$result->method('closeCursor')->willReturn(true);
				return $result;
			});
			return $qb;
		});

		return new RegisterMapper(
			db: $db,
			tenancy: new TenancyServices($this->createMock(OrganisationMapper::class), $this->createMock(IUserSession::class), $this->createMock(IGroupManager::class)),
			appConfig: $this->createMock(IAppConfig::class),
			services: new RegisterMapperServices(
				schemaMapper: $this->createMock(SchemaMapper::class),
				container: $this->createMock(ContainerInterface::class),
				eventDispatcher: $this->createMock(IEventDispatcher::class),
				logger: $this->createMock(LoggerInterface::class)
			)
		);
	}//end mapper()

	/**
	 * Every schema is answered from one read, in ascending register order, each register once.
	 *
	 * @return void
	 */
	public function testEverySchemaIsAnsweredFromOneRead(): void {
		$mapper = $this->mapper();

		$this->assertSame([1, 2, 3], $mapper->getAllRegisterIdsWithSchema(schemaId: 7));
		$this->assertSame([3], $mapper->getAllRegisterIdsWithSchema(schemaId: 5));
		$this->assertSame(1, $mapper->getFirstRegisterWithSchema(schemaId: 9));
		$this->assertSame([], $mapper->getAllRegisterIdsWithSchema(schemaId: 4));
		$this->assertNull($mapper->getFirstRegisterWithSchema(schemaId: 4));

		$this->assertSame(1, $this->reads);
	}//end testEverySchemaIsAnsweredFromOneRead()
}//end class
