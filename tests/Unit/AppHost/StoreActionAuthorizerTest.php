<?php

/**
 * Tests for the store install action authorizer.
 *
 * 🔴 EVERY TEST HERE IS ABOUT REFUSING RATHER THAN NO-OPPING.
 *
 * This is a duck-typed lookup by convention, and the fleet has been bitten
 * repeatedly by exactly that shape: `isInstalled('docudesk')`,
 * `class_exists('OCA\DocuDesk\…')` — a runtime lookup pointed at a name
 * nothing answers to becomes a SILENT NO-OP rather than an error. A no-op here
 * is an install that skipped its authorization check and reported success, so
 * every way of failing to resolve is asserted to refuse.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\AppHost
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\AppHost;

use OCA\OpenRegister\AppHost\Store\StoreActionAuthorizer;
use OCP\IUser;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * A leaf ActionAuthService that answers.
 */
class FakeActionAuthService {
	/**
	 * @param bool $answer What can() returns.
	 */
	public function __construct(private readonly bool $answer) {
	}

	/**
	 * @param IUser  $user   The user.
	 * @param string $action The action.
	 *
	 * @return bool
	 */
	public function can(IUser $user, string $action): bool {
		return $this->answer;
	}
}

/**
 * A leaf service whose matrix throws.
 */
class ThrowingActionAuthService {
	/**
	 * @param IUser  $user   The user.
	 * @param string $action The action.
	 *
	 * @return bool
	 */
	public function can(IUser $user, string $action): bool {
		throw new RuntimeException('matrix unavailable');
	}
}

/**
 * A leaf service that exists but has no can().
 */
class ShapelessActionAuthService {
}

/**
 * @covers \OCA\OpenRegister\AppHost\Store\StoreActionAuthorizer
 */
class StoreActionAuthorizerTest extends TestCase {
	/**
	 * Build an authorizer whose container yields the given service.
	 *
	 * @param mixed $service What the container returns, or a Throwable to throw.
	 *
	 * @return StoreActionAuthorizer
	 */
	private function authorizer(mixed $service): StoreActionAuthorizer {
		$container = $this->createMock(ContainerInterface::class);
		if ($service instanceof \Throwable) {
			$container->method('get')->willThrowException($service);
		} else {
			$container->method('get')->willReturn($service);
		}

		return new StoreActionAuthorizer($container, $this->createMock(LoggerInterface::class));
	}

	/**
	 * The leaf matrix says yes.
	 *
	 * @return void
	 */
	public function testItPermitsWhenTheLeafMatrixSaysYes(): void {
		$authorizer = $this->authorizer(new FakeActionAuthService(true));

		$this->assertTrue(
			$authorizer->can('integriq', 'catalog.instantiate', $this->createMock(IUser::class))
		);
	}

	/**
	 * The leaf matrix says no.
	 *
	 * @return void
	 */
	public function testItRefusesWhenTheLeafMatrixSaysNo(): void {
		$authorizer = $this->authorizer(new FakeActionAuthService(false));

		$this->assertFalse(
			$authorizer->can('integriq', 'catalog.instantiate', $this->createMock(IUser::class))
		);
	}

	/**
	 * 🔴 An absent service refuses. It must never read as "no objection".
	 *
	 * @return void
	 */
	public function testAnUnresolvableServiceRefuses(): void {
		$authorizer = $this->authorizer(new RuntimeException('not found'));

		$this->assertFalse(
			$authorizer->can('integriq', 'catalog.instantiate', $this->createMock(IUser::class)),
			'An unresolvable authorizer must refuse, never silently permit.'
		);
	}

	/**
	 * 🔴 A service without can() refuses rather than being assumed permissive.
	 *
	 * This is the shape a RENAME produces: the class still resolves, the
	 * method is gone, and a lookup that only checked existence would sail past.
	 *
	 * @return void
	 */
	public function testAServiceWithoutCanRefuses(): void {
		$authorizer = $this->authorizer(new ShapelessActionAuthService());

		$this->assertFalse(
			$authorizer->can('integriq', 'catalog.instantiate', $this->createMock(IUser::class))
		);
	}

	/**
	 * 🔴 A throwing matrix is a refusal, not a pass.
	 *
	 * ADR-023's own requireAction() throws to DENY, so anything propagating
	 * out of can() must not be read as consent.
	 *
	 * @return void
	 */
	public function testAThrowingMatrixRefuses(): void {
		$authorizer = $this->authorizer(new ThrowingActionAuthService());

		$this->assertFalse(
			$authorizer->can('integriq', 'catalog.instantiate', $this->createMock(IUser::class))
		);
	}

	/**
	 * Every refusal is logged at ERROR with the reason.
	 *
	 * A silent refusal is nearly as bad as a silent pass: somebody has to be
	 * able to find out why the store stopped installing.
	 *
	 * @return void
	 */
	public function testARefusalIsLoggedWithItsReason(): void {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willThrowException(new RuntimeException('not found'));

		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())
			->method('error')
			->with($this->stringContains('catalog.instantiate'), $this->anything());

		$authorizer = new StoreActionAuthorizer($container, $logger);
		$authorizer->can('integriq', 'catalog.instantiate', $this->createMock(IUser::class));
	}
}
