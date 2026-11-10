<?php

/**
 * Unit tests for the migration pack lookups and the built-in slug bookkeeping on create.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/specs/migration-mapping-packs/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service;

use OCA\OpenRegister\Db\MigrationPack;
use OCA\OpenRegister\Db\MigrationPackMapperInterface;
use OCA\OpenRegister\Service\MigrationPack\MappingEngine;
use OCA\OpenRegister\Service\MigrationPack\PackDefinitionValidator;
use OCA\OpenRegister\Service\MigrationPackService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Lookups ask the mapper for exactly the id or slug given; a built-in create clears only a slug that was recorded as deleted.
 *
 * @covers \OCA\OpenRegister\Service\MigrationPackService
 */
class MigrationPackServiceLookupsTest extends TestCase {

	/**
	 * The mapper, mocked.
	 *
	 * @var MigrationPackMapperInterface&MockObject
	 */
	private MigrationPackMapperInterface&MockObject $mapper;

	/**
	 * The app config, mocked.
	 *
	 * @var IAppConfig&MockObject
	 */
	private IAppConfig&MockObject $config;

	/**
	 * Build the shared mocks.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->mapper = $this->createMock(MigrationPackMapperInterface::class);
		$this->config = $this->createMock(IAppConfig::class);

	}//end setUp()

	/**
	 * The service under test.
	 *
	 * @return MigrationPackService
	 */
	private function service(): MigrationPackService {
		return new MigrationPackService(
			$this->mapper,
			$this->createMock(PackDefinitionValidator::class),
			$this->createMock(MappingEngine::class),
			$this->config
		);

	}//end service()

	/**
	 * Each lookup asks for exactly what it was given.
	 *
	 * @return void
	 */
	public function testLookupsAskTheMapperForWhatTheyWereGiven(): void {
		$byId   = new MigrationPack();
		$bySlug = new MigrationPack();
		$this->mapper->expects($this->once())->method('find')->with(42)->willReturn($byId);
		$this->mapper->expects($this->once())->method('findByPackSlug')->with('zaken-v1')->willReturn($bySlug);
		$this->mapper->expects($this->once())->method('findAll')->willReturn([$byId, $bySlug]);

		$service = $this->service();
		$this->assertSame($byId, $service->find(42));
		$this->assertSame($bySlug, $service->findByPackSlug('zaken-v1'));
		$this->assertSame([$byId, $bySlug], $service->findAll());

	}//end testLookupsAskTheMapperForWhatTheyWereGiven()

	/**
	 * Creating a built-in pack whose slug was never deleted leaves the deleted-slug list alone.
	 *
	 * @return void
	 */
	public function testABuiltinCreateLeavesAnUnrecordedSlugAlone(): void {
		$this->mapper->method('findByPackSlug')->willThrowException(new DoesNotExistException('none'));
		$this->mapper->expects($this->once())->method('insert')->willReturnArgument(0);
		$this->config->method('getValueArray')->willReturn(['other-pack']);
		$this->config->expects($this->never())->method('setValueArray');

		$pack = $this->service()->seedBuiltin(['id' => 'zaken-v1', 'name' => 'Zaken', 'sourceFormat' => 'csv', 'version' => '1.0.0', 'idStrategy' => ['type' => 'generate'], 'fieldMappings' => [['source' => 'Naam', 'target' => 'title']]]);

		$this->assertTrue($pack->getBuiltin());

	}//end testABuiltinCreateLeavesAnUnrecordedSlugAlone()

	/**
	 * Creating a built-in pack whose slug was recorded as deleted removes it from the list.
	 *
	 * @return void
	 */
	public function testABuiltinCreateClearsARecordedSlug(): void {
		$this->mapper->method('findByPackSlug')->willThrowException(new DoesNotExistException('none'));
		$this->mapper->method('insert')->willReturnArgument(0);
		$this->config->method('getValueArray')->willReturn(['other-pack', 'zaken-v1']);
		$this->config->expects($this->once())
			->method('setValueArray')
			->with('openregister', MigrationPackService::DELETED_BUILTIN_SLUGS_KEY, ['other-pack'], true);

		$this->service()->seedBuiltin(['id' => 'zaken-v1', 'name' => 'Zaken', 'sourceFormat' => 'csv', 'version' => '1.0.0', 'idStrategy' => ['type' => 'generate'], 'fieldMappings' => [['source' => 'Naam', 'target' => 'title']]]);

	}//end testABuiltinCreateClearsARecordedSlug()
}//end class
