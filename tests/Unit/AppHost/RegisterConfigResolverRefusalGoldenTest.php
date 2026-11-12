<?php

declare(strict_types=1);

/**
 * Golden of the fail-closed refusals of RegisterConfigResolver: the exception,
 * its message and cause, and the error logged, for a missing OpenRegister and
 * for a RegisterResolverService the container cannot give.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\AppHost
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 */

namespace OCA\OpenRegister\Tests\Unit\AppHost;

use OCA\OpenRegister\AppHost\Exception\FoundationUnavailableException;
use OCA\OpenRegister\AppHost\Service\RegisterConfigResolver;
use OCP\App\IAppManager;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\AbstractLogger;
use RuntimeException;
use Stringable;

/**
 * Pins the refused outcomes of the register config resolver.
 */
class RegisterConfigResolverRefusalGoldenTest extends TestCase {

	/**
	 * The errors logged.
	 *
	 * @var array<int, string>
	 */
	private array $logged = [];

	public function testAMissingOpenRegisterIsRefusedBeforeTheContainerIsAsked(): void {
		$outcome = $this->refusal(installed: false);

		$this->assertSame(
			[
				'exception' => FoundationUnavailableException::class,
				'message' => (new FoundationUnavailableException(appId: 'myapp'))->getMessage(),
				'previous' => null,
				'logged' => ['error [AppHost:myapp] OpenRegister not available — register config resolution refused (fail-closed) -'],
			],
			$outcome
		);
	}//end testAMissingOpenRegisterIsRefusedBeforeTheContainerIsAsked()

	public function testAnUnresolvableResolverIsRefusedWithItsCause(): void {
		$outcome = $this->refusal(installed: true);

		$expected = new FoundationUnavailableException(
			appId: 'myapp',
			detail: 'RegisterResolverService could not be resolved from the container.',
			previous: new RuntimeException('no binding')
		);
		$this->assertSame(
			[
				'exception' => FoundationUnavailableException::class,
				'message' => $expected->getMessage(),
				'previous' => 'no binding',
				'logged' => ['error [AppHost:myapp] RegisterResolverService unresolvable — register config resolution refused (fail-closed) no binding'],
			],
			$outcome
		);
	}//end testAnUnresolvableResolverIsRefusedWithItsCause()

	/**
	 * Resolve a register id and describe the refusal.
	 *
	 * @param bool $installed Whether OpenRegister is installed.
	 *
	 * @return array<string, mixed>
	 */
	private function refusal(bool $installed): array {
		$this->logged = [];
		$apps         = $this->createMock(IAppManager::class);
		$apps->method('isInstalled')->willReturn($installed);
		$container = $this->createMock(ContainerInterface::class);
		if ($installed === false) {
			$container->expects($this->never())->method('get');
		} else {
			$container->method('get')->willThrowException(new RuntimeException('no binding'));
		}

		$logged = &$this->logged;
		$logger = new class($logged) extends AbstractLogger {
			/**
			 * @param array<int, string> $logged The shared log.
			 */
			public function __construct(private array &$logged) {
			}

			public function log($level, string|Stringable $message, array $context = []): void {
				$cause          = isset($context['exception']) === true ? $context['exception']->getMessage() : '-';
				$this->logged[] = $level . ' ' . $message . ' ' . $cause;
			}
		};

		$resolver = new RegisterConfigResolver(appId: 'myapp', appManager: $apps, container: $container, logger: $logger);
		try {
			$resolver->resolveRegisterId();
			return ['exception' => null];
		} catch (FoundationUnavailableException $e) {
			return [
				'exception' => $e::class,
				'message' => $e->getMessage(),
				'previous' => $e->getPrevious()?->getMessage(),
				'logged' => $this->logged,
			];
		}
	}//end refusal()
}//end class
