<?php

/**
 * RegisterStatistics schema count tests.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Controller\Support
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller\Support;

use OCA\OpenRegister\Controller\Support\ObjectTransfer;
use OCA\OpenRegister\Controller\Support\RegisterIntegrations;
use OCA\OpenRegister\Controller\Support\RegisterMappers;
use OCA\OpenRegister\Controller\Support\RegisterServices;
use OCA\OpenRegister\Controller\Support\RegisterStatistics;
use OCA\OpenRegister\Controller\Support\RegistersControllerServices;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Db\SchemaCatalog;
use OCA\OpenRegister\Kernel\Contracts\GitHubClientInterface;
use OCA\OpenRegister\Kernel\Contracts\ObjectExporterInterface;
use OCA\OpenRegister\Kernel\Contracts\ObjectImporterInterface;
use OCA\OpenRegister\Service\ConfigurationService;
use OCA\OpenRegister\Service\MigrationPackService;
use OCA\OpenRegister\Service\OasService;
use OCA\OpenRegister\Service\RegisterService;
use OCA\OpenRegister\Service\Registers\RegisterCacheHandler;
use OCA\OpenRegister\Service\Serializer\RegisterSerializer;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Every register's schemas are fetched in one go; each register is then counted
 * over those of its schemas that still exist, in the register's own order.
 */
class RegisterStatisticsSchemaCountsTest extends TestCase {

	/**
	 * The schema mapper double.
	 *
	 * @var SchemaMapper&MockObject
	 */
	private SchemaMapper&MockObject $schemas;

	/**
	 * The register service double.
	 *
	 * @var RegisterService&MockObject
	 */
	private RegisterService&MockObject $registerService;

	/**
	 * Build the statistics over doubles.
	 *
	 * @return RegisterStatistics
	 */
	private function statistics(): RegisterStatistics {
		$this->schemas         = $this->createMock(SchemaMapper::class);
		$this->registerService = $this->createMock(RegisterService::class);

		return new RegisterStatistics(
			new RegistersControllerServices(
				mappers: new RegisterMappers(
					catalog: new SchemaCatalog(
						registers: $this->createMock(RegisterMapper::class),
						schemas: $this->schemas,
						tables: $this->createMock(MagicMapper::class),
					),
					auditTrailMapper: $this->createMock(AuditTrailMapper::class),
				),
				registers: new RegisterServices(
					registerService: $this->registerService,
					registerCacheHandler: $this->createMock(RegisterCacheHandler::class),
					registerSerializer: new RegisterSerializer($this->createMock(SchemaMapper::class), $this->createMock(LoggerInterface::class)),
					migrationPackService: $this->createMock(MigrationPackService::class),
				),
				integrations: new RegisterIntegrations(
					configurationService: $this->createMock(ConfigurationService::class),
					githubService: $this->createMock(GitHubClientInterface::class),
					oasService: $this->createMock(OasService::class),
					container: $this->createMock(ContainerInterface::class),
				),
				transfer: new ObjectTransfer(
					exportService: $this->createMock(ObjectExporterInterface::class),
					importService: $this->createMock(ObjectImporterInterface::class),
				),
			)
		);
	}//end statistics()

	/**
	 * A register with the given id and schema ids.
	 *
	 * @param int        $id      The id.
	 * @param array|null $schemas The schema ids.
	 *
	 * @return Register
	 */
	private function register(int $id, ?array $schemas): Register {
		$register = new Register();
		$register->setId($id);
		$register->setSchemas($schemas);
		return $register;
	}//end register()

	/**
	 * Each register is counted over its still-existing schemas only.
	 *
	 * @return void
	 */
	public function testEachRegisterIsCountedOverItsKnownSchemas(): void {
		$statistics = $this->statistics();
		$s4         = new Schema();
		$s4->setId(4);
		$s7         = new Schema();
		$s7->setId(7);
		$this->schemas->expects($this->once())
			->method('findMultipleOptimized')
			->with([7, 4, 99])
			->willReturn([4 => $s4, 7 => $s7]);
		$asked = [];
		$this->registerService->method('getSchemaObjectCounts')->willReturnCallback(
			static function (int $registerId, array $schemas) use (&$asked): array {
				$asked[$registerId] = array_map(static fn (Schema $s): int => $s->getId(), $schemas);
				return ['register' => $registerId, 'schemas' => count($schemas)];
			}
		);

		$counts = $statistics->schemaCounts([$this->register(1, ['7', 4, 99]), $this->register(2, [4]), $this->register(3, null)]);

		$this->assertSame([1 => [7, 4], 2 => [4], 3 => []], $asked);
		$this->assertSame(
			[
				1 => ['register' => 1, 'schemas' => 2],
				2 => ['register' => 2, 'schemas' => 1],
				3 => ['register' => 3, 'schemas' => 0],
			],
			$counts
		);
	}//end testEachRegisterIsCountedOverItsKnownSchemas()
}//end class
