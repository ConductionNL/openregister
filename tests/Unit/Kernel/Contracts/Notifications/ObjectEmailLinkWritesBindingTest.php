<?php

/**
 * Unit tests for the binding of ObjectEmailLinkWritesInterface.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Kernel\Contracts\Notifications
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/specs/generic-integrations/spec.md
 */

// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Kernel\Contracts\Notifications;

use OCA\OpenRegister\Kernel\Contracts\Notifications\ObjectEmailLinkWritesInterface;
use OCA\OpenRegister\Service\EmailLinkService;
use OCA\OpenRegister\Service\EmailLinkServiceInterface;
use OCA\OpenRegister\Service\Integration\IntegrationWiring;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Notifications owns the contract; Integrations implements it and binds it in its own wiring.
 *
 * @coversNothing
 */
final class ObjectEmailLinkWritesBindingTest extends TestCase {

	/**
	 * The email link service contract widens to the Notifications contract, so every caller keeps working.
	 *
	 * @return void
	 */
	public function testTheEmailLinkServiceContractExtendsIt(): void {
		$this->assertTrue(is_subclass_of(EmailLinkServiceInterface::class, ObjectEmailLinkWritesInterface::class));
		$this->assertTrue(is_subclass_of(EmailLinkService::class, ObjectEmailLinkWritesInterface::class));
	}//end testTheEmailLinkServiceContractExtendsIt()

	/**
	 * Integrations binds the contract to its email link service.
	 *
	 * @return void
	 */
	public function testIntegrationsBindsItToTheEmailLinkService(): void {
		$this->assertSame(EmailLinkService::class, IntegrationWiring::BINDINGS[ObjectEmailLinkWritesInterface::class] ?? null);
	}//end testIntegrationsBindsItToTheEmailLinkService()

	/**
	 * The contract holds exactly what the object email endpoints ask: the account check and the unlink.
	 *
	 * @return void
	 */
	public function testItHoldsTheAccountCheckAndTheUnlink(): void {
		$methods = array_map(
			static fn (\ReflectionMethod $method): string => $method->getName(),
			(new ReflectionClass(ObjectEmailLinkWritesInterface::class))->getMethods()
		);
		sort($methods);

		$this->assertSame(['assertAccountOwnership', 'unlinkEmail'], $methods);
	}//end testItHoldsTheAccountCheckAndTheUnlink()
}//end class
