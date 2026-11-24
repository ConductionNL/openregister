<?php

/**
 * Tests for TranslationBindingDescription.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Translation
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Translation;

use OCA\OpenRegister\Kernel\Contracts\Integrations\TranslationBindingInterface;
use OCA\OpenRegister\Service\ContractBindings;
use OCA\OpenRegister\Service\Translation\IdentityTranslationProvider;
use OCA\OpenRegister\Service\Translation\TranslationBindingDescription;
use OCA\OpenRegister\Service\Translation\TranslationProviderInterface;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use RuntimeException;

/**
 * @covers \OCA\OpenRegister\Service\Translation\TranslationBindingDescription
 * @uses   \OCA\OpenRegister\Service\Translation\IdentityTranslationProvider
 */
final class TranslationBindingDescriptionTest extends TestCase {

	/**
	 * A description over a container that answers TranslationProviderInterface with $provider.
	 *
	 * @param TranslationProviderInterface|RuntimeException $provider The bound provider, or what resolving it throws.
	 *
	 * @return TranslationBindingDescription
	 */
	private function over(TranslationProviderInterface|RuntimeException $provider): TranslationBindingDescription {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static function (string $id) use ($provider): object {
				self::assertSame(TranslationProviderInterface::class, $id);
				if ($provider instanceof RuntimeException) {
					throw $provider;
				}

				return $provider;
			}
		);

		return new TranslationBindingDescription(container: $container);
	}//end over()

	/**
	 * The identity provider reads simulated.
	 *
	 * @return void
	 */
	public function testTheIdentityProviderReadsSimulated(): void {
		[$status, $message] = $this->over(provider: new IdentityTranslationProvider())->describe();

		$this->assertSame('simulated', $status);
		$this->assertStringContainsString('identity provider is bound', $message);
	}//end testTheIdentityProviderReadsSimulated()

	/**
	 * Another provider reads configured, named by its identifier.
	 *
	 * @return void
	 */
	public function testAnotherProviderReadsConfiguredByItsIdentifier(): void {
		$provider = $this->createMock(TranslationProviderInterface::class);
		$provider->method('getIdentifier')->willReturn('deepl');

		$this->assertSame(
			['configured', 'Translation provider "deepl" is bound. Not tested.'],
			$this->over(provider: $provider)->describe()
		);
	}//end testAnotherProviderReadsConfiguredByItsIdentifier()

	/**
	 * A provider that cannot be resolved throws out of describe(), so the report says so.
	 *
	 * @return void
	 */
	public function testAnUnresolvableProviderThrows(): void {
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('no binding');

		$this->over(provider: new RuntimeException('no binding'))->describe();
	}//end testAnUnresolvableProviderThrows()

	/**
	 * The port is bound to this class.
	 *
	 * @return void
	 */
	public function testThePortIsBound(): void {
		$this->assertSame(TranslationBindingDescription::class, ContractBindings::BINDINGS[TranslationBindingInterface::class]);
	}//end testThePortIsBound()
}//end class
