<?php

/**
 * The Core port for the retention a save stamps is bound to the retention service.
 *
 * The object save path (Core) asks the container for RecordRetentionStampsInterface
 * instead of the Compliance RetentionServiceInterface. A missing alias would make the
 * save silently skip its archival metadata (SaveObject treats an unresolvable service
 * as absent), which no other unit test sees, so this test pins the binding, the
 * implementation and the four calls the port carries.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Architecture
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/functional-areas-depend-in-one-direction/specs/backend-modularity/spec.md#requirement-areas-reference-only-lower-areas
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Architecture;

use OCA\OpenRegister\Kernel\Contracts\Core\RecordRetentionStampsInterface;
use OCA\OpenRegister\Service\ComplianceServiceBindings;
use OCA\OpenRegister\Service\RetentionService;
use OCA\OpenRegister\Service\RetentionServiceInterface;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * @coversNothing
 */
final class RecordRetentionStampsBindingTest extends TestCase {

	/**
	 * Compliance binds the port to the same class as the full retention interface.
	 *
	 * @return void
	 */
	public function testComplianceBindsThePortToTheRetentionService(): void {
		$this->assertSame(RetentionService::class, ComplianceServiceBindings::BINDINGS[RecordRetentionStampsInterface::class] ?? null);
		$this->assertSame(
			ComplianceServiceBindings::BINDINGS[RetentionServiceInterface::class] ?? null,
			ComplianceServiceBindings::BINDINGS[RecordRetentionStampsInterface::class] ?? null
		);
	}//end testComplianceBindsThePortToTheRetentionService()

	/**
	 * The full retention interface extends the port, so every existing implementation and double still answers it.
	 *
	 * @return void
	 */
	public function testTheRetentionInterfaceExtendsThePort(): void {
		$this->assertTrue(is_subclass_of(RetentionServiceInterface::class, RecordRetentionStampsInterface::class));
		$this->assertTrue(is_subclass_of(RetentionService::class, RecordRetentionStampsInterface::class));
	}//end testTheRetentionInterfaceExtendsThePort()

	/**
	 * The port carries exactly the four calls a save makes, so it does not grow into a copy of the service.
	 *
	 * @return void
	 */
	public function testThePortCarriesTheFourSaveCalls(): void {
		$methods = array_map(
			static fn (ReflectionMethod $method): string => $method->getName(),
			(new ReflectionClass(RecordRetentionStampsInterface::class))->getMethods()
		);
		sort($methods);

		$this->assertSame(
			['applyArchivalMetadata', 'applyClassification', 'guardClassificationOverride', 'recalculateActionDate'],
			$methods
		);
	}//end testThePortCarriesTheFourSaveCalls()
}//end class
