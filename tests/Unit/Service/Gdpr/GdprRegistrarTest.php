<?php

/**
 * GdprRegistrar registers the DSAR signer and registries in a fixed order.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Gdpr;

use OCA\OpenRegister\Kernel\Contracts\Core\ReadPurposeGuardInterface;
use OCA\OpenRegister\Service\Audit\PurposeGuard;
use OCA\OpenRegister\Service\Gdpr\GdprRegistrar;
use OCA\OpenRegister\Tests\Unit\AppHost\RecordingRegistrationContext;
use OCP\AppFramework\IAppContainer;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

class GdprRegistrarTest extends TestCase {

	/**
	 * register() registers the DSAR signer and registries, in this order.
	 *
	 * @return void
	 */
	public function testRegistersTheServicesInOrder(): void {
		$context = new RecordingRegistrationContext();
		GdprRegistrar::register(context: $context);

		$this->assertSame(
			expected: [
				'OCA\OpenRegister\Service\Gdpr\Export\PadesSigner',
				'OCA\OpenRegister\Service\Gdpr\Evidence\EvidenceSourceRegistry',
				'OCA\OpenRegister\Service\Gdpr\Identity\NullIdentityVerifyProvider',
				'OCA\OpenRegister\Service\Gdpr\Identity\IdentityVerifyRegistry',
				'OCA\OpenRegister\Service\Gdpr\Regulator\NullRegulatorEscalateProvider',
				'OCA\OpenRegister\Service\Gdpr\Regulator\RegulatorEscalateRegistry',
			],
			actual: $context->services
		);
	}//end testRegistersTheServicesInOrder()

	/**
	 * Every factory builds an instance of the name it is registered under.
	 *
	 * The container hands each factory a test double for every service it asks
	 * for. A final default provider cannot be doubled, so the container builds
	 * it through its own registered factory, as the real container would.
	 *
	 * @return void
	 */
	public function testEveryFactoryBuildsItsService(): void {
		$context = new RecordingRegistrationContext();
		GdprRegistrar::register(context: $context);

		$doubles = [];
		$container = $this->createMock(originalClassName: IAppContainer::class);
		$container->method('get')->willReturnCallback(
			function (string $id) use (&$doubles, &$container, $context) {
				if (isset($doubles[$id]) === false) {
					$final = (new ReflectionClass(objectOrClass: $id))->isFinal();
					$doubles[$id] = $final === true ? $context->factories[$id]($container) : $this->createMock(originalClassName: $id);
				}

				return $doubles[$id];
			}
		);

		foreach ($context->factories as $name => $factory) {
			$this->assertInstanceOf(expected: $name, actual: $factory($container), message: $name);
		}
	}//end testEveryFactoryBuildsItsService()

	/**
	 * The Core read purpose-guard port is answered by the doelbinding guard, and that is the only alias.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/verwerkingsregister-api/spec.md
	 */
	public function testTheReadPurposeGuardPortIsAnsweredByThePurposeGuard(): void {
		$context = new RecordingRegistrationContext();
		GdprRegistrar::register(context: $context);

		$this->assertSame(
			expected: [['alias' => ReadPurposeGuardInterface::class, 'target' => PurposeGuard::class]],
			actual: $context->aliases
		);
		$this->assertTrue(is_a(PurposeGuard::class, ReadPurposeGuardInterface::class, true));
	}//end testTheReadPurposeGuardPortIsAnsweredByThePurposeGuard()
}//end class
