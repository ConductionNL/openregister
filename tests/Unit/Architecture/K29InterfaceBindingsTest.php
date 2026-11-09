<?php

/**
 * The K29 interfaces are bound and implemented by the class they hide.
 *
 * A missing binding is a runtime 500 that no other unit test sees, so this
 * test pins each binding and each implementation.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Architecture
 *
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Architecture;

use OCA\OpenRegister\Service\ConfigurationDeployment\ConfigurationDraftService;
use OCA\OpenRegister\Service\ConfigurationDeployment\ConfigurationDraftsInterface;
use OCA\OpenRegister\Service\ContractBindings;
use OCA\OpenRegister\Service\Schema\SchemaRevalidationService;
use OCA\OpenRegister\Service\Schema\SchemaRevalidatorInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * @coversNothing
 */
final class K29InterfaceBindingsTest extends TestCase {

	/**
	 * Each K29 interface with the class it is bound to.
	 *
	 * @return array<string, array{class-string, class-string}>
	 */
	public static function bindings(): array {
		return [
			'configuration drafts' => [ConfigurationDraftsInterface::class, ConfigurationDraftService::class],
			'schema revalidator' => [SchemaRevalidatorInterface::class, SchemaRevalidationService::class],
		];
	}//end bindings()

	/**
	 * The interface is bound to its class.
	 *
	 * @param class-string $contract The interface.
	 * @param class-string $service  The class it resolves to.
	 *
	 * @return void
	 */
	#[DataProvider('bindings')]
	public function testTheInterfaceIsBound(string $contract, string $service): void {
		$this->assertSame($service, ContractBindings::BINDINGS[$contract] ?? null);
	}//end testTheInterfaceIsBound()

	/**
	 * The bound class implements the interface.
	 *
	 * @param class-string $contract The interface.
	 * @param class-string $service  The class it resolves to.
	 *
	 * @return void
	 */
	#[DataProvider('bindings')]
	public function testTheClassImplementsTheInterface(string $contract, string $service): void {
		$this->assertTrue(is_subclass_of($service, $contract));
	}//end testTheClassImplementsTheInterface()
}//end class
