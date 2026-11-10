<?php

/**
 * Characterisation tests for the purpose refusal exception.
 *
 * Pins what a refusal carries and how it is published, so the constructor can
 * lose its unused chaining parameter without changing what a caller sees.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Audit
 *
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/specs/verwerkingsregister-api/spec.md
 */

declare(strict_types=1);

namespace Unit\Service\Audit;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Service\Audit\PurposeRefusedException;
use PHPUnit\Framework\TestCase;

/**
 * The refusal's getters, status, message and response body.
 */
class PurposeRefusedExceptionTest extends TestCase {
	public function testCarriesRulePurposeAndContextIntoTheBody(): void {
		$refusal = new PurposeRefusedException(
			rule: PurposeRefusedException::RULE_UNKNOWN,
			reason: 'Unknown purpose.',
			purpose: 'typo',
			context: ['available' => ['a', 'b']]
		);

		$this->assertSame(PurposeRefusedException::RULE_UNKNOWN, $refusal->getRule());
		$this->assertSame('typo', $refusal->getPurpose());
		$this->assertSame('Unknown purpose.', $refusal->getMessage());
		$this->assertSame(403, $refusal->getCode());
		$this->assertSame(403, $refusal->getStatusCode());
		$this->assertNull($refusal->getPrevious());
		$this->assertSame(
			[
				'error' => 'PURPOSE_REFUSED',
				'rule' => 'purpose-unknown',
				'message' => 'Unknown purpose.',
				'purpose' => 'typo',
				'available' => ['a', 'b'],
			],
			$refusal->toResponseBody()
		);
	}

	public function testDefaultsToNoPurposeAndNoContext(): void {
		$refusal = new PurposeRefusedException(rule: PurposeRefusedException::RULE_MISSING, reason: 'Name a purpose.');

		$this->assertNull($refusal->getPurpose());
		$this->assertSame(
			['error' => 'PURPOSE_REFUSED', 'rule' => 'purpose-missing', 'message' => 'Name a purpose.', 'purpose' => null],
			$refusal->toResponseBody()
		);
	}
}
