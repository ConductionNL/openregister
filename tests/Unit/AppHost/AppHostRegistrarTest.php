<?php

/**
 * AppHostRegistrar hands every AppHost contract binding to the registration context.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\AppHost
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\AppHost;

use OCA\OpenRegister\AppHost\AppContainerLocator;
use OCA\OpenRegister\AppHost\AppHostRegistrar;
use OCA\OpenRegister\AppHost\Scheduling\ScheduleFlowStore;
use OCA\OpenRegister\AppHost\Scheduling\ScheduleFlowStoreInterface;
use OCA\OpenRegister\AppHost\Scheduling\ScheduleManifestLoader;
use OCA\OpenRegister\AppHost\Scheduling\ScheduleManifestLoaderInterface;
use OCA\OpenRegister\AppHost\Scheduling\ScheduleRefusalReportInterface;
use OCA\OpenRegister\AppHost\Scheduling\ScheduleRefusals;
use OCA\OpenRegister\AppHost\Service\RegisterDocumentLoader;
use OCA\OpenRegister\AppHost\Service\RegisterDocumentLoaderInterface;
use OCA\OpenRegister\Kernel\Contracts\AppContainerFinderInterface;
use OCA\OpenRegister\Twig\MappingTwigInstaller;
use OCA\OpenRegister\Twig\MappingTwigInstallerInterface;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use PHPUnit\Framework\TestCase;

/**
 * A leaf app's register() call must alias each contract to its service, and nothing else.
 */
class AppHostRegistrarTest extends TestCase {

	public function testRegisterAliasesEveryContractToItsServiceInOrder(): void {
		$calls = [];
		$context = $this->createMock(IRegistrationContext::class);
		$context->expects($this->exactly(6))
			->method('registerServiceAlias')
			->willReturnCallback(
				static function (string $alias, string $target) use (&$calls): void {
					$calls[] = [$alias, $target];
				}
			);
		$context->expects($this->never())->method('registerService');

		AppHostRegistrar::register(context: $context);

		$this->assertSame(
			[
				[ScheduleFlowStoreInterface::class, ScheduleFlowStore::class],
				[ScheduleManifestLoaderInterface::class, ScheduleManifestLoader::class],
				[ScheduleRefusalReportInterface::class, ScheduleRefusals::class],
				[RegisterDocumentLoaderInterface::class, RegisterDocumentLoader::class],
				[AppContainerFinderInterface::class, AppContainerLocator::class],
				[MappingTwigInstallerInterface::class, MappingTwigInstaller::class],
			],
			$calls
		);
	}//end testRegisterAliasesEveryContractToItsServiceInOrder()
}//end class
