<?php

/**
 * Unit tests for recording an app-shipped register descriptor as its baseline.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Configuration
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/local-changes-to-app-shipped-configuration/specs/schema-import/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Configuration;

use OCA\OpenRegister\Db\ConfigurationValue;
use OCA\OpenRegister\Db\ConfigurationValueMapper;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Db\ConfigurationMapper;
use OCA\OpenRegister\Db\MappingMapper;
use OCA\OpenRegister\Db\AuditTrailMapperInterface;
use OCA\OpenRegister\Service\ConfigurationDeployment\ConfigurationLayer;
use OCA\OpenRegister\Service\ConfigurationDeployment\ConfigurationValueStore;
use OCA\OpenRegister\Service\Configuration\ImportHandler;
use OCA\OpenRegister\Service\Configuration\SchemaImportOptions;
use OCA\OpenRegister\Service\Configuration\SchemaImportSteps;
use OCA\OpenRegister\Service\Configuration\ShippedRegisterBaseline;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\ShippedBaseline\DivergenceComparator;
use OCA\OpenRegister\Service\ShippedBaseline\GuardedDescriptorMerge;
use OCA\OpenRegister\Service\ShippedBaseline\ShippedBaselineStore;
use OCA\OpenRegister\Service\ShippedBaseline\ShippedConfigurationGuard;
use OCA\OpenRegister\Service\ShippedBaseline\ShippedDefinition;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IAppConfig;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * A register an app imports keeps the parts it shipped, named by app and release.
 */
final class ShippedRegisterBaselineTest extends TestCase {

	/**
	 * The guard double that receives the baselines.
	 *
	 * @var ShippedConfigurationGuard&MockObject
	 */
	private ShippedConfigurationGuard&MockObject $guard;

	/**
	 * Every baseline the guard was asked to record, slug and definition.
	 *
	 * @var array<int, array{slug: string, shipped: ShippedDefinition}>
	 */
	private array $recorded = [];

	/**
	 * Set up the recording guard.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->guard = $this->createMock(ShippedConfigurationGuard::class);
		$this->guard->method('recordShippedRegister')->willReturnCallback(
			function (string $slug, ShippedDefinition $shipped): bool {
				$this->recorded[] = ['slug' => $slug, 'shipped' => $shipped];
				return true;
			}
		);

	}//end setUp()

	/**
	 * The shipped parts are kept with the app and its release, under the lower-cased slug.
	 *
	 * @return void
	 */
	public function testTheShippedPartsAreRecordedWithTheAppAndRelease(): void {
		$baseline = new ShippedRegisterBaseline(guard: $this->guard);

		$recorded = $baseline->record(
			data: [
				'slug' => 'Publications',
				'title' => 'Publicaties',
				'version' => '2.0.0',
				'configuration' => ['x-openregister-archival' => ['term' => 'P10Y']],
				'authorization' => ['read' => ['public']],
				'schemas' => [1, 2],
			],
			options: new SchemaImportOptions(appId: 'opencatalogi', version: '2.0.0')
		);

		self::assertTrue($recorded);
		self::assertCount(1, $this->recorded);
		self::assertSame('publications', $this->recorded[0]['slug']);
		self::assertSame(
			[
				'title' => 'Publicaties',
				'authorization' => ['read' => ['public']],
				'configuration' => ['x-openregister-archival' => ['term' => 'P10Y']],
			],
			$this->recorded[0]['shipped']->definition
		);
		self::assertSame('opencatalogi', $this->recorded[0]['shipped']->app);
		self::assertSame('2.0.0', $this->recorded[0]['shipped']->appVersion);

	}//end testTheShippedPartsAreRecordedWithTheAppAndRelease()

	/**
	 * Nothing is recorded without an app id, a slug, a guard, or any shipped part.
	 *
	 * @return void
	 */
	public function testNothingIsRecordedWithoutAnAppASlugAGuardOrAPart(): void {
		$baseline = new ShippedRegisterBaseline(guard: $this->guard);
		$app = new SchemaImportOptions(appId: 'opencatalogi', version: '1.0.0');

		self::assertFalse($baseline->record(data: ['slug' => 'pub', 'title' => 'Pub'], options: new SchemaImportOptions()));
		self::assertFalse($baseline->record(data: ['title' => 'Pub'], options: $app));
		self::assertFalse($baseline->record(data: ['slug' => 'pub', 'version' => '1.0.0', 'schemas' => []], options: $app));
		self::assertFalse((new ShippedRegisterBaseline(guard: null))->record(data: ['slug' => 'pub', 'title' => 'Pub'], options: $app));
		self::assertSame([], $this->recorded);

	}//end testNothingIsRecordedWithoutAnAppASlugAGuardOrAPart()

	/**
	 * A release without a version is recorded with an empty version.
	 *
	 * @return void
	 */
	public function testAMissingVersionIsRecordedEmpty(): void {
		(new ShippedRegisterBaseline(guard: $this->guard))->record(
			data: ['slug' => 'pub', 'description' => 'Publicaties'],
			options: new SchemaImportOptions(appId: 'opencatalogi')
		);

		self::assertSame('', $this->recorded[0]['shipped']->appVersion);
		self::assertSame(['description' => 'Publicaties'], $this->recorded[0]['shipped']->definition);

	}//end testAMissingVersionIsRecordedEmpty()

	/**
	 * The guard writes a register baseline at the register's subject address, under the register key.
	 *
	 * @return void
	 */
	public function testTheGuardWritesAtTheRegisterAddress(): void {
		$created = [];
		$mapper = $this->createMock(ConfigurationValueMapper::class);
		$mapper->method('findAtAddress')->willReturn(null);
		$mapper->expects(self::once())->method('createFromArray')->willReturnCallback(
			function (array $row) use (&$created): ConfigurationValue {
				$created = $row;
				return new ConfigurationValue();
			}
		);
		$store = new ShippedBaselineStore(
			new ConfigurationValueStore($mapper, $this->createMock(IAppConfig::class), 'openregister'),
			$this->createMock(LoggerInterface::class)
		);
		$guard = new ShippedConfigurationGuard(
			baselines: $store,
			merge: $this->createMock(GuardedDescriptorMerge::class),
			comparator: $this->createMock(DivergenceComparator::class),
			audit: $this->createMock(AuditTrailMapperInterface::class),
			session: $this->createMock(IUserSession::class),
			logger: $this->createMock(LoggerInterface::class)
		);

		self::assertTrue($guard->recordShippedRegister(slug: 'pub', shipped: new ShippedDefinition(['title' => 'Pub'], 'opencatalogi', '1.0.0')));
		self::assertSame(ConfigurationLayer::SUBJECT, $created['layer']);
		self::assertSame('register:pub', $created['layerRef']);
		self::assertSame(ShippedBaselineStore::KEY_REGISTER, $created['configKey']);
		self::assertSame(['title' => 'Pub'], $created['configValue']['value']['definition']);
		self::assertSame('register:pub', $store->registerSubject(slug: 'pub'));

	}//end testTheGuardWritesAtTheRegisterAddress()

	/**
	 * An import records the baseline when it creates a register and when it
	 * applies an update, and not when the version gate skips the update.
	 *
	 * @return void
	 */
	public function testAnImportRecordsOnCreateAndAppliedUpdateOnly(): void {
		$store = [];
		$registers = $this->createMock(RegisterMapper::class);
		$registers->method('find')->willReturnCallback(
			function (string|int $id) use (&$store): Register {
				if (isset($store[(string)$id]) === true) {
					return $store[(string)$id];
				}

				throw new DoesNotExistException('register not found: ' . $id);
			}
		);
		$registers->method('createFromArray')->willReturnCallback(
			function (array $data) use (&$store): Register {
				$register = new Register();
				$register->setSlug($data['slug']);
				$register->setVersion($data['version']);
				$register->setSchemas([]);
				$register->setId(7);
				$store[$data['slug']] = $register;
				return $register;
			}
		);
		$registers->method('updateFromArray')->willReturnCallback(
			function (int $id, array $data) use (&$store): Register {
				$register = $store[$data['slug']];
				$register->setVersion($data['version']);
				return $register;
			}
		);
		$registers->method('update')->willReturnArgument(0);

		$handler = new ImportHandler(
			catalog: new \OCA\OpenRegister\Db\SchemaCatalog(
				registers: $registers,
				schemas: $this->createMock(SchemaMapper::class),
				tables: $this->createMock(MagicMapper::class)
			),
			records: new \OCA\OpenRegister\Service\Configuration\ConfigurationImportRecords(
				configurations: $this->createMock(ConfigurationMapper::class),
				mappings: $this->createMock(MappingMapper::class),
				appConfig: $this->createMock(IAppConfig::class)
			),
			schemaSteps: new SchemaImportSteps(shippedGuard: $this->guard),
			logger: $this->createMock(LoggerInterface::class),
			appDataPath: '/tmp',
			objectService: $this->createMock(ObjectService::class)
		);

		$handler->importRegister(
			data: ['slug' => 'pub', 'title' => 'Pub', 'version' => '1.0.0'],
			options: new SchemaImportOptions(appId: 'opencatalogi', version: '1.0.0')
		);
		$handler->importRegister(
			data: ['slug' => 'pub', 'title' => 'Pub v2', 'version' => '2.0.0'],
			options: new SchemaImportOptions(appId: 'opencatalogi', version: '2.0.0')
		);
		$handler->importRegister(
			data: ['slug' => 'pub', 'title' => 'Pub old', 'version' => '1.5.0'],
			options: new SchemaImportOptions(appId: 'opencatalogi', version: '1.5.0')
		);

		self::assertSame(['Pub', 'Pub v2'], array_map(static fn (array $row): string => $row['shipped']->definition['title'], $this->recorded));
		self::assertSame(['1.0.0', '2.0.0'], array_map(static fn (array $row): string => $row['shipped']->appVersion, $this->recorded));

	}//end testAnImportRecordsOnCreateAndAppliedUpdateOnly()
}//end class
