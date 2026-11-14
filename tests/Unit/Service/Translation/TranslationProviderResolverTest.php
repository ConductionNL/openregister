<?php

/**
 * TranslationProviderResolver: the identity provider unless an administrator chose
 * Task Processing and confirmed that record text may be sent.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Translation
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/ai-translation-with-a-glossary/specs
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Translation;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Service\Translation\IdentityTranslationProvider;
use OCA\OpenRegister\Service\Translation\TaskProcessingTranslationProvider;
use OCA\OpenRegister\Service\Translation\TranslationProviderResolver;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \OCA\OpenRegister\Service\Translation\TranslationProviderResolver
 * @uses \OCA\OpenRegister\Service\Translation\IdentityTranslationProvider
 */
class TranslationProviderResolverTest extends TestCase {

	private ContainerInterface&MockObject $container;

	private LoggerInterface&MockObject $logger;

	private TaskProcessingTranslationProvider&MockObject $taskProcessing;

	protected function setUp(): void {
		$this->container      = $this->createMock(ContainerInterface::class);
		$this->logger         = $this->createMock(LoggerInterface::class);
		$this->taskProcessing = $this->createMock(TaskProcessingTranslationProvider::class);
	}

	/**
	 * A resolver over stored settings; a missing key answers the default asked for.
	 *
	 * @param array<string, mixed> $stored The stored settings.
	 */
	private function resolver(array $stored): TranslationProviderResolver {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			static function (string $app, string $key, string $default='') use ($stored): string {
				self::assertSame('openregister', $app);
				return (string) ($stored[$key] ?? $default);
			}
		);
		$config->method('getValueBool')->willReturnCallback(
			static function (string $app, string $key, bool $default=false) use ($stored): bool {
				self::assertSame('openregister', $app);
				return (bool) ($stored[$key] ?? $default);
			}
		);

		return new TranslationProviderResolver($config, $this->container, $this->logger);
	}

	public function testWithTheDefaultsTheIdentityProviderIsBoundAndNothingIsBuilt(): void {
		$this->container->expects($this->never())->method('get');

		$resolver = $this->resolver([]);

		$this->assertFalse($resolver->taskProcessingSelected());
		$this->assertInstanceOf(IdentityTranslationProvider::class, $resolver->resolve());
	}

	public function testChoosingTaskProcessingWithoutConfirmingKeepsTheIdentityProvider(): void {
		$this->container->expects($this->never())->method('get');

		$resolver = $this->resolver(['translation_provider' => 'taskprocessing']);

		$this->assertFalse($resolver->taskProcessingSelected());
		$this->assertSame('identity', $resolver->resolve()->getIdentifier());
	}

	public function testConfirmingWithoutChoosingTaskProcessingKeepsTheIdentityProvider(): void {
		$this->container->expects($this->never())->method('get');

		$resolver = $this->resolver(['translation_provider' => 'identity', 'translation_allow_external' => true]);

		$this->assertFalse($resolver->taskProcessingSelected());
		$this->assertInstanceOf(IdentityTranslationProvider::class, $resolver->resolve());
	}

	public function testChosenAndConfirmedBindsTheTaskProcessingProvider(): void {
		$this->container->expects($this->once())->method('get')
			->with(TaskProcessingTranslationProvider::class)
			->willReturn($this->taskProcessing);

		$resolver = $this->resolver(['translation_provider' => 'taskprocessing', 'translation_allow_external' => true]);

		$this->assertTrue($resolver->taskProcessingSelected());
		$this->assertSame($this->taskProcessing, $resolver->resolve());
	}

	public function testAProviderThatCannotBeBuiltFallsBackToTheIdentityProviderAndIsLogged(): void {
		$this->container->method('get')->willThrowException(new RuntimeException('no task processing'));
		$this->logger->expects($this->once())->method('warning')
			->with($this->stringContains('identity provider is bound'), ['exception' => 'no task processing']);

		$provider = $this->resolver(['translation_provider' => 'taskprocessing', 'translation_allow_external' => true])->resolve();

		$this->assertInstanceOf(IdentityTranslationProvider::class, $provider);
	}
}
