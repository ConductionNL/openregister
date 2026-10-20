<?php

/**
 * Whether a connector's `call` takes a named argument, read from its signature.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Integration
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/integration-person-lookup/spec.md#requirement-a-call-may-be-recorded-without-its-bodies
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Integration;

use OCA\OpenRegister\Service\Integration\CallSignature;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Service\Integration\CallSignature
 */
class CallSignatureTest extends TestCase {

	public function testAParameterOfThatNameIsAccepted(): void {
		$service = new class () {
			public function call($source, bool $withholdBodies = false): void {
			}
		};

		$this->assertTrue((new CallSignature())->accepts(service: $service, method: 'call', name: 'withholdBodies'));
	}//end testAParameterOfThatNameIsAccepted()

	public function testAMethodThatCollectsNamedArgumentsAcceptsAnyName(): void {
		$service = new class () {
			public function call(...$arguments): void {
			}
		};

		$this->assertTrue((new CallSignature())->accepts(service: $service, method: 'call', name: 'withholdBodies'));
	}//end testAMethodThatCollectsNamedArgumentsAcceptsAnyName()

	public function testAnOlderSignatureAndAMissingMethodAreNotAccepted(): void {
		$older = new class () {
			public function call($source, string $endpoint = ''): void {
			}
		};

		$this->assertFalse((new CallSignature())->accepts(service: $older, method: 'call', name: 'withholdBodies'));
		$this->assertFalse((new CallSignature())->accepts(service: new \stdClass(), method: 'call', name: 'withholdBodies'));
	}//end testAnOlderSignatureAndAMissingMethodAreNotAccepted()
}//end class
