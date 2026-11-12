<?php

/**
 * LinkServiceRuntime wiring test
 *
 * Every link service the builtin factory table constructs takes the shared
 * LinkServiceRuntime bundle, and the table names exactly the constructor's
 * parameters, so building each one from a container succeeds.
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

use OCA\OpenRegister\Service\Integration\BuiltinIntegrationServices;
use OCA\OpenRegister\Service\LinkServiceRuntime;
use OCA\OpenRegister\Service\MapLinkService;
use OCA\OpenRegister\Service\OpenProjectLinkService;
use OCA\OpenRegister\Service\PhotoLinkService;
use OCA\OpenRegister\Service\PollLinkService;
use OCA\OpenRegister\Service\TalkLinkService;
use OCA\OpenRegister\Service\TimeTrackerLinkService;
use OCA\OpenRegister\Service\XwikiLinkService;
use OCP\App\IAppManager;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use ReflectionClass;

/**
 * The link services are wired with the runtime bundle.
 */
class LinkServiceRuntimeWiringTest extends TestCase {

	/**
	 * The link services built from the factory table.
	 *
	 * @return array<string, array{class-string}>
	 */
	public static function tableBuiltServices(): array {
		return [
			'map'          => [MapLinkService::class],
			'photo'        => [PhotoLinkService::class],
			'time tracker' => [TimeTrackerLinkService::class],
			'xwiki'        => [XwikiLinkService::class],
			'open project' => [OpenProjectLinkService::class],
			'talk'         => [TalkLinkService::class],
		];
	}//end tableBuiltServices()

	/**
	 * The factory table builds the service, handing it one runtime bundle.
	 *
	 * @param class-string $class The link service.
	 *
	 * @dataProvider tableBuiltServices
	 *
	 * @return void
	 */
	public function testTheFactoryTableBuildsTheService(string $class): void {
		$runtime   = new LinkServiceRuntime(
			$this->createMock(IAppManager::class),
			$this->createMock(IUserSession::class),
			$this->createMock(LoggerInterface::class)
		);
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			function (string $id) use ($runtime): object {
				if ($id === LinkServiceRuntime::class) {
					return $runtime;
				}

				return $this->createMock($id);
			}
		);

		$service = BuiltinIntegrationServices::build(container: $container, class: $class);

		$this->assertInstanceOf($class, $service);
		$property = (new ReflectionClass($class))->getProperty('runtime');
		$this->assertSame($runtime, $property->getValue($service));
		$this->assertInstanceOf(IAppManager::class, $runtime->appManager());
		$this->assertInstanceOf(IUserSession::class, $runtime->userSession());
		$this->assertInstanceOf(LoggerInterface::class, $runtime->logger());
	}//end testTheFactoryTableBuildsTheService()

	/**
	 * No link service takes the three bundled collaborators one by one any more.
	 *
	 * @return void
	 */
	public function testNoLinkServiceTakesTheBundledCollaboratorsSeparately(): void {
		$classes = array_merge(array_column(self::tableBuiltServices(), 0), [PollLinkService::class]);
		foreach ($classes as $class) {
			$constructor = (new ReflectionClass($class))->getConstructor();
			$types       = [];
			foreach ($constructor->getParameters() as $parameter) {
				$types[] = (string)$parameter->getType();
			}

			$this->assertContains(LinkServiceRuntime::class, $types, $class);
			$this->assertSame([], array_values(array_intersect($types, [IAppManager::class, IUserSession::class, LoggerInterface::class])), $class);
			$this->assertLessThanOrEqual(5, count($types), $class);
		}
	}//end testNoLinkServiceTakesTheBundledCollaboratorsSeparately()
}//end class
