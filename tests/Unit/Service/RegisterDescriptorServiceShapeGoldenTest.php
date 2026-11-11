<?php

/**
 * RegisterDescriptorService shape golden tests
 *
 * Pins the exact inventory rows (keys, order, values) and the exact outcome
 * arrays of a re-import through the app's base document and through a single
 * descriptor file, imported or failed.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service;

use OCA\OpenRegister\Service\ShippedRegister;
use OCA\OpenRegister\AppHost\AppContainerLocator;
use OCA\OpenRegister\Db\MagicMapperInterface;
use OCA\OpenRegister\Db\SchemaCatalog;
use OCA\OpenRegister\AppHost\Service\RegisterDocumentLoader;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\AppRegisterImportRunner;
use OCA\OpenRegister\Service\ConfigurationService;
use OCA\OpenRegister\Service\CountedRegisterImport;
use OCA\OpenRegister\Service\RegisterDescriptorService;
use OCA\OpenRegister\Service\RegisterImporters;
use OCA\OpenRegister\Service\ShippedBaseline\ShippedAuthorizationDecision;
use OCP\App\IAppManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Golden tests for the RegisterDescriptorService result shapes.
 */
class RegisterDescriptorServiceShapeGoldenTest extends TestCase {

	/**
	 * Temporary app root.
	 *
	 * @var string
	 */
	private string $appRoot = '';

	/**
	 * App manager mock.
	 *
	 * @var IAppManager&MockObject
	 */
	private IAppManager&MockObject $appManager;

	/**
	 * Register mapper mock.
	 *
	 * @var RegisterMapper&MockObject
	 */
	private RegisterMapper&MockObject $registerMapper;

	/**
	 * Configuration service mock.
	 *
	 * @var ConfigurationService&MockObject
	 */
	private ConfigurationService&MockObject $configurationService;

	/**
	 * The service under test.
	 *
	 * @var RegisterDescriptorService
	 */
	private RegisterDescriptorService $service;

	/**
	 * Build the service over a temporary app root.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->appRoot = sys_get_temp_dir().'/or-descriptor-shape-'.bin2hex(random_bytes(6));
		mkdir($this->appRoot.'/lib/Settings', 0o777, true);
		$this->appManager           = $this->createMock(IAppManager::class);
		$this->registerMapper       = $this->createMock(RegisterMapper::class);
		$this->configurationService = $this->createMock(ConfigurationService::class);
		$this->appManager->method('getInstalledApps')->willReturn(['fixtureapp']);
		$this->appManager->method('getAppPath')->willReturn($this->appRoot);
		$this->service = new RegisterDescriptorService(
			$this->appManager,
			$this->registerMapper,
			new RegisterImporters(
				$this->configurationService,
				new CountedRegisterImport(
				catalog: new SchemaCatalog(registers: $this->registerMapper, schemas: $this->createMock(SchemaMapper::class), tables: $this->createMock(MagicMapperInterface::class)),
				decision: new ShippedAuthorizationDecision()
			),
				new AppRegisterImportRunner(
				appManager: $this->appManager,
				containers: $this->createMock(AppContainerLocator::class),
				configurationService: $this->configurationService,
				documents: new RegisterDocumentLoader(appManager: $this->appManager)
			)
			),
			$this->createMock(LoggerInterface::class)
		);
	}//end setUp()

	/**
	 * Remove the temporary app root.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		foreach ((glob($this->appRoot.'/lib/Settings/*') ?: []) as $file) {
			unlink($file);
		}

		rmdir($this->appRoot.'/lib/Settings');
		rmdir($this->appRoot.'/lib');
		rmdir($this->appRoot);
	}//end tearDown()

	/**
	 * Write a descriptor.
	 *
	 * @param string $file      File name
	 * @param array  $registers Registers by key
	 * @param array  $marker    The x-openregister block
	 *
	 * @return void
	 */
	private function descriptor(string $file, array $registers, array $marker=[]): void {
		$document = ['openapi' => '3.0.0', 'info' => ['title' => 'F', 'version' => '9.9.9'], 'components' => ['registers' => $registers]];
		if ($marker !== []) {
			$document['x-openregister'] = $marker;
		}

		file_put_contents($this->appRoot.'/lib/Settings/'.$file, json_encode($document));
	}//end descriptor()

	/**
	 * A register row the mapper returns.
	 *
	 * @param string $slug    Slug
	 * @param string $version Version
	 *
	 * @return Register
	 */
	private function register(string $slug, string $version): Register {
		$register = new Register();
		$register->setSlug($slug);
		$register->setVersion($version);
		return $register;
	}//end register()

	/**
	 * Inventory rows, exactly.
	 *
	 * @return void
	 */
	public function testInventoryRows(): void {
		$this->descriptor('a_register.json', ['Flows' => ['title' => 'Flows', 'version' => '1.3.0'], 'plain' => []], ['app' => 'n8n']);
		$this->descriptor('b_register.json', ['old' => ['version' => '2.0.0'], 'same' => ['title' => 'Same', 'version' => '1.0.0']], ['app' => '']);
		$this->descriptor('c_mock_register.json', ['mock' => ['version' => '1.0.0']], ['type' => 'mock']);
		$this->registerMapper->method('findAll')->willReturn([$this->register('old', '1.9.0'), $this->register('same', '1.0.0'), $this->register('plain', '0.0.2')]);

		$this->assertSame(
			[
				['appId' => 'fixtureapp', 'slug' => 'old', 'title' => 'old', 'state' => 'behind', 'installedVersion' => '1.9.0', 'shippedVersion' => '2.0.0', 'descriptor' => 'b_register.json'],
				['appId' => 'fixtureapp', 'slug' => 'same', 'title' => 'Same', 'state' => 'current', 'installedVersion' => '1.0.0', 'shippedVersion' => '1.0.0', 'descriptor' => 'b_register.json'],
				['appId' => 'n8n', 'slug' => 'Flows', 'title' => 'Flows', 'state' => 'absent', 'installedVersion' => null, 'shippedVersion' => '1.3.0', 'descriptor' => 'a_register.json'],
				['appId' => 'n8n', 'slug' => 'plain', 'title' => 'plain', 'state' => 'current', 'installedVersion' => '0.0.2', 'shippedVersion' => '0.0.1', 'descriptor' => 'a_register.json'],
			],
			$this->service->inventory()
		);
	}//end testInventoryRows()

	/**
	 * A re-import through one descriptor file: imported, then failed.
	 *
	 * @return void
	 */
	public function testReimportThroughADescriptorFile(): void {
		$this->descriptor('flows_register.json', ['flows' => ['title' => 'Flows', 'version' => '1.3.0']]);
		$this->registerMapper->method('findAll')->willReturnOnConsecutiveCalls(
			[],
			[$this->register('flows', '1.3.0')],
			[],
			[]
		);
		$this->configurationService->method('importFromApp')->willReturnOnConsecutiveCalls(
			['registers' => ['flows'], 'importJobId' => 'job-7'],
			$this->throwException(new RuntimeException('the table is missing'))
		);

		$imported = $this->service->reimportShipped(register: new ShippedRegister(appId: 'fixtureapp', slug: 'FLOWS'), via: 'tester');
		$failed   = $this->service->reimportShipped(register: new ShippedRegister(appId: 'fixtureapp', slug: 'flows'), via: 'tester');
		$via      = 'OpenRegister (lib/Settings/flows_register.json)';
		$this->assertSame(
			[
				'outcome'     => 'imported',
				'reason'      => null,
				'path'        => 'descriptor',
				'via'         => $via,
				'version'     => '9.9.9',
				'fragments'   => 0,
				'counts'      => ['registers' => ['created' => 1, 'updated' => 0, 'unchanged' => 0], 'schemas' => ['created' => 0, 'updated' => 0, 'unchanged' => 0], 'authorizationReplaced' => 0],
				'replaced'    => [],
				'importJobId' => 'job-7',
				'importAppId' => 'fixtureapp',
			],
			$imported
		);
		$this->assertSame(
			[
				'outcome'   => 'failed',
				'reason'    => 'The import through '.$via.' failed: the table is missing',
				'path'      => 'descriptor',
				'via'       => $via,
				'version'   => '9.9.9',
				'fragments' => 0,
				'counts'    => ['registers' => ['created' => 0, 'updated' => 0, 'unchanged' => 0], 'schemas' => ['created' => 0, 'updated' => 0, 'unchanged' => 0], 'authorizationReplaced' => 0],
				'replaced'  => [],
			],
			$failed
		);
	}//end testReimportThroughADescriptorFile()

	/**
	 * A re-import through the app's base document.
	 *
	 * @return void
	 */
	public function testReimportThroughTheBaseDocument(): void {
		$this->descriptor('fixtureapp_register.json', ['flows' => ['title' => 'Flows', 'version' => '1.3.0']]);
		$this->registerMapper->method('findAll')->willReturnOnConsecutiveCalls([$this->register('flows', '1.2.0')], [$this->register('flows', '1.3.0')]);
		$this->configurationService->method('importFromApp')->willReturn(['registers' => ['flows']]);

		$result = $this->service->reimportShipped(register: new ShippedRegister(appId: 'fixtureapp', slug: 'flows'), via: 'tester');
		$this->assertSame(
			[
				'outcome'   => 'imported',
				'reason'    => null,
				'path'      => 'openregister',
				'via'       => 'OpenRegister (lib/Settings/fixtureapp_register.json + register.d)',
				'version'   => '9.9.9',
				'fragments' => 0,
				'counts'    => ['registers' => ['created' => 0, 'updated' => 1, 'unchanged' => 0], 'schemas' => ['created' => 0, 'updated' => 0, 'unchanged' => 0], 'authorizationReplaced' => 0],
				'replaced'  => [],
			],
			$result
		);
	}//end testReimportThroughTheBaseDocument()
}//end class
