<?php

/**
 * RegisterConfigResolver lets a resolver failure that is not a missing configuration through unchanged.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\AppHost\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\AppHost\Service;

use OCA\OpenRegister\Service\ConfigLookup;
use OCA\OpenRegister\AppHost\Exception\ConfigurationMissingException;
use OCA\OpenRegister\AppHost\Service\RegisterConfigResolver;
use OCA\OpenRegister\Service\Resolver\Exception\MissingConfigException;
use OCP\App\IAppManager;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Only OR's MissingConfigException is translated; any other failure is rethrown as it is.
 */
class RegisterConfigResolverBehaviourTest extends TestCase {

	/**
	 * A resolver over an OR resolver whose schema lookup throws the given exception.
	 */
	private function resolverThrowing(Throwable $failure): RegisterConfigResolver {
		$orResolver = new class($failure) {
			public function __construct(
				private Throwable $failure,
			) {
			}//end __construct()

			public function lookupId(ConfigLookup $lookup): string {
				throw $this->failure;
			}//end lookupId()
		};

		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('isInstalled')->with('openregister')->willReturn(true);
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->with('OCA\OpenRegister\Service\RegisterResolverService')->willReturn($orResolver);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->never())->method('error');

		return new RegisterConfigResolver(appId: 'myapp', appManager: $appManager, container: $container, logger: $logger);
	}//end resolverThrowing()

	public function testAnUnrelatedResolverFailureIsRethrownUntranslated(): void {
		$failure = new RuntimeException('database gone');

		try {
			$this->resolverThrowing(failure: $failure)->resolveSchemaId(configKey: 'pet_schema');
			$this->fail('Expected the resolver failure to propagate');
		} catch (RuntimeException $e) {
			$this->assertSame($failure, $e);
		}
	}//end testAnUnrelatedResolverFailureIsRethrownUntranslated()

	public function testAMissingConfigFailureIsTranslated(): void {
		$failure = new MissingConfigException(appId: 'myapp', configKey: 'pet_schema');

		try {
			$this->resolverThrowing(failure: $failure)->resolveSchemaId(configKey: 'pet_schema');
			$this->fail('Expected ConfigurationMissingException');
		} catch (ConfigurationMissingException $e) {
			$this->assertSame('pet_schema', $e->getConfigKey());
			$this->assertSame($failure, $e->getPrevious());
		}
	}//end testAMissingConfigFailureIsTranslated()
}//end class
