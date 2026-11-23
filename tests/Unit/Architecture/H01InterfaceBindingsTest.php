<?php

/**
 * The interfaces lane H01 added are bound, and their classes implement them.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Architecture
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Architecture;

use OCA\OpenRegister\Service\CoreServiceBindings;
use OCA\OpenRegister\Service\Flow\FlowWiring;
use OCA\OpenRegister\Service\Party\PartySearchInterface;
use OCA\OpenRegister\Service\Party\PartySearchService;
use OCA\OpenRegister\Service\Rbac\DenyEnforcementMode;
use OCA\OpenRegister\Service\Rbac\DenyEnforcementModeInterface;
use OCA\OpenRegister\Service\Rbac\ObjectScopeResolverInterface;
use OCA\OpenRegister\Service\Rbac\RbacFallbacks;
use OCP\IAppConfig;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Binding present, class implements.
 *
 * @spec openspec/specs/rbac-scopes/spec.md
 */
final class H01InterfaceBindingsTest extends TestCase {

	/**
	 * Interface => the class it must resolve to, and the map that binds it.
	 *
	 * @return array<string, array{0: class-string, 1: class-string, 2: class-string}>
	 */
	public static function bindings(): array {
		return [
			'deny enforcement' => [DenyEnforcementModeInterface::class, DenyEnforcementMode::class, CoreServiceBindings::class],
			'party search' => [PartySearchInterface::class, PartySearchService::class, FlowWiring::class],
		];
	}//end bindings()

	/**
	 * The binding is registered and the class implements the interface.
	 *
	 * @param class-string $interface The interface.
	 * @param class-string $class     The class it resolves to.
	 * @param class-string $map       The binding map that binds it.
	 *
	 * @return void
	 */
	#[DataProvider('bindings')]
	public function testTheBindingIsPresentAndImplemented(string $interface, string $class, string $map): void {
		$this->assertSame($class, (constant($map . '::BINDINGS')[$interface] ?? null));
		$this->assertTrue(is_subclass_of($class, $interface));
	}//end testTheBindingIsPresentAndImplemented()

	/**
	 * The fallbacks build the same classes the bindings resolve to.
	 *
	 * @return void
	 */
	public function testTheFallbacksBuildTheBoundClasses(): void {
		$this->assertSame(
			CoreServiceBindings::BINDINGS[ObjectScopeResolverInterface::class],
			get_class(RbacFallbacks::objectScope())
		);
		$this->assertInstanceOf(
			DenyEnforcementMode::class,
			RbacFallbacks::denyEnforcement($this->createMock(IAppConfig::class), new NullLogger())
		);
	}//end testTheFallbacksBuildTheBoundClasses()
}//end class
