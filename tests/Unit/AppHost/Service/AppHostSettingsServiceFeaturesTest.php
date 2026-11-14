<?php

/**
 * AppHost AppHostSettingsService — feature toggles and the register import outcomes.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\AppHost\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\AppHost\Service;

use OCA\OpenRegister\AppHost\Service\AppHostSettingsService;
use OCA\OpenRegister\AppHost\Service\AppHostSettingsServices;
use OCA\OpenRegister\AppHost\Service\FeatureToggleService;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The toggle reads fail closed when the toggle service cannot be resolved, the
 * declarations come from the register document's `features` block once, and
 * each loadConfiguration() outcome reports what happened.
 */
#[CoversClass(AppHostSettingsService::class)]
#[UsesClass(AppHostSettingsServices::class)]
class AppHostSettingsServiceFeaturesTest extends TestCase {

	/**
	 * The container mock.
	 *
	 * @var ContainerInterface&MockObject
	 */
	private ContainerInterface&MockObject $container;

	/**
	 * The logger mock.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * The app manager mock.
	 *
	 * @var IAppManager&MockObject
	 */
	private IAppManager&MockObject $appManager;

	/**
	 * How often the register document was resolved.
	 *
	 * @var int
	 */
	public int $resolved = 0;

	/**
	 * Set up the collaborators.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->container  = $this->createMock(ContainerInterface::class);
		$this->logger     = $this->createMock(LoggerInterface::class);
		$this->appManager = $this->createMock(IAppManager::class);
	}//end setUp()

	/**
	 * A service whose register document is the given resolution, or throws.
	 *
	 * @param array|\Throwable $resolution [data, version, complete] or the failure.
	 *
	 * @return AppHostSettingsService
	 */
	private function service(array|\Throwable $resolution = [null, null, true]): AppHostSettingsService {
		$services = new AppHostSettingsServices(
			appConfig: $this->createMock(IAppConfig::class),
			appManager: $this->appManager,
			container: $this->container,
			logger: $this->logger,
		);
		$test     = $this;

		return new class('myapp', $services, $this->createMock(IGroupManager::class), $this->createMock(IUserSession::class), $resolution, $test) extends AppHostSettingsService {
			/**
			 * Constructor.
			 *
			 * @param string                         $appId        The app id.
			 * @param AppHostSettingsServices        $services     The services.
			 * @param IGroupManager                  $groupManager The group manager.
			 * @param IUserSession                   $userSession  The session.
			 * @param array|\Throwable               $resolution   The document resolution.
			 * @param AppHostSettingsServiceFeaturesTest $test     The test, counting resolutions.
			 */
			public function __construct(
				string $appId,
				AppHostSettingsServices $services,
				IGroupManager $groupManager,
				IUserSession $userSession,
				private readonly array|\Throwable $resolution,
				private readonly AppHostSettingsServiceFeaturesTest $test,
			) {
				parent::__construct($appId, $services, $groupManager, $userSession);
			}

			/**
			 * The fixed resolution.
			 *
			 * @return array
			 */
			protected function resolveRegisterConfiguration(): array {
				$this->test->resolved++;
				if ($this->resolution instanceof \Throwable) {
					throw $this->resolution;
				}

				return $this->resolution;
			}
		};
	}//end service()

	public function testAnUnresolvableToggleServiceReadsEveryToggleOff(): void {
		$this->container->method('get')->with(FeatureToggleService::class)
			->willThrowException(new RuntimeException('no container entry'));
		$this->logger->expects($this->exactly(3))->method('warning')
			->with($this->stringContains('[AppHost:myapp] feature toggle service unavailable; every toggle reads off: no container entry'));

		$svc = $this->service();

		$this->assertSame([], $svc->getFeatures());
		$this->assertSame([], $svc->updateFeatures(['beta' => true]));
		$this->assertFalse($svc->isFeatureEnabled('beta'));
		$this->assertSame(0, $this->resolved);
	}//end testAnUnresolvableToggleServiceReadsEveryToggleOff()

	public function testSomethingOtherThanTheToggleServiceIsTreatedAsAbsent(): void {
		$this->container->method('get')->willReturn(new \stdClass());
		$this->logger->expects($this->never())->method('warning');

		$svc = $this->service();

		$this->assertSame([], $svc->getFeatures());
		$this->assertSame([], $svc->updateFeatures(['beta' => true]));
		$this->assertFalse($svc->isFeatureEnabled('beta'));
	}//end testSomethingOtherThanTheToggleServiceIsTreatedAsAbsent()

	public function testTheDeclarationsComeFromTheFeaturesBlockAndAreReadOnce(): void {
		$declared = ['beta' => ['key' => 'beta', 'default' => false], 'map' => ['key' => 'map', 'default' => true]];
		$list     = array_values($declared);

		$toggles = $this->createMock(FeatureToggleService::class);
		$toggles->expects($this->once())->method('merged')->with('myapp', $list)
			->willReturn(['beta' => false, 'map' => true]);
		$toggles->expects($this->once())->method('update')->with('myapp', $list, ['beta' => true])
			->willReturn(['beta' => true, 'map' => true]);
		$toggles->expects($this->once())->method('isEnabled')->with('myapp', 'map', $list)
			->willReturn(true);
		$this->container->method('get')->with(FeatureToggleService::class)->willReturn($toggles);

		$svc = $this->service([[FeatureToggleService::DECLARATION_KEY => $declared], '1.0.0', true]);

		$this->assertSame(['beta' => false, 'map' => true], $svc->getFeatures());
		$this->assertSame(['beta' => true, 'map' => true], $svc->updateFeatures(['beta' => true]));
		$this->assertTrue($svc->isFeatureEnabled('map'));
		$this->assertSame(1, $this->resolved);
	}//end testTheDeclarationsComeFromTheFeaturesBlockAndAreReadOnce()

	public function testAnUnreadableDocumentDeclaresNoToggles(): void {
		$toggles = $this->createMock(FeatureToggleService::class);
		$toggles->expects($this->once())->method('isEnabled')->with('myapp', 'beta', [])->willReturn(false);
		$this->container->method('get')->willReturn($toggles);
		$this->logger->expects($this->once())->method('warning')
			->with('[AppHost:myapp] feature declarations unreadable, no toggles offered: broken json');

		$this->assertFalse($this->service(new RuntimeException('broken json'))->isFeatureEnabled('beta'));
	}//end testAnUnreadableDocumentDeclaresNoToggles()

	public function testLoadConfigurationWithoutOpenRegisterSkipsTheImport(): void {
		$this->appManager->method('isInstalled')->with('openregister')->willReturn(false);
		$this->logger->expects($this->once())->method('warning')
			->with('[AppHost:myapp] OpenRegister not available, skipping register initialization');

		$result = $this->service()->loadConfiguration();

		$this->assertSame(['success' => false, 'message' => 'OpenRegister is not installed or enabled.'], $result);
		$this->assertSame(0, $this->resolved);
	}//end testLoadConfigurationWithoutOpenRegisterSkipsTheImport()

	public function testLoadConfigurationWithoutARegisterDocumentSaysSo(): void {
		$this->appManager->method('isInstalled')->willReturn(true);
		$message = 'Configuration file myapp_register.json not found under the myapp app path.';
		$this->logger->expects($this->once())->method('warning')->with('[AppHost:myapp] ' . $message);

		$this->assertSame(['success' => false, 'message' => $message], $this->service()->loadConfiguration());
	}//end testLoadConfigurationWithoutARegisterDocumentSaysSo()

	public function testAFailingImportIsLoggedAndReported(): void {
		$this->appManager->method('isInstalled')->willReturn(true);
		$this->logger->expects($this->once())->method('error')
			->with('[AppHost:myapp] configuration import failed', ['exception' => 'disk full']);

		$result = $this->service(new RuntimeException('disk full'))->loadConfiguration(force: true);

		$this->assertSame(['success' => false, 'message' => 'disk full'], $result);
	}//end testAFailingImportIsLoggedAndReported()

	public function testAnEmptyImportResultIsNotASuccess(): void {
		$this->appManager->method('isInstalled')->willReturn(true);
		$configuration = new class {
			/**
			 * The arguments of the last import.
			 *
			 * @var array
			 */
			public array $args = [];

			/**
			 * Record the import and return nothing.
			 *
			 * @param string $appId   The app.
			 * @param array  $data    The document.
			 * @param string $version The version.
			 * @param bool   $force   Whether forced.
			 *
			 * @return array
			 */
			public function importFromApp(string $appId, array $data, string $version, bool $force): array {
				$this->args = [$appId, $data, $version, $force];
				return [];
			}
		};
		$this->container->method('get')->with('OCA\OpenRegister\Service\ConfigurationServiceInterface')
			->willReturn($configuration);

		$result = $this->service([['info' => ['title' => 'x']], '2.0.0', true])->loadConfiguration(force: true);

		$this->assertSame(['success' => false, 'message' => 'Import returned an empty result.'], $result);
		$this->assertSame(['myapp', ['info' => ['title' => 'x']], '2.0.0', true], $configuration->args);
	}//end testAnEmptyImportResultIsNotASuccess()
}//end class
