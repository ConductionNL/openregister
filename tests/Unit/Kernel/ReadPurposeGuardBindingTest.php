<?php

/**
 * ReadPurposeGuardBindingTest: the Core read purpose-guard port resolves to the Compliance purpose guard.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Kernel
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Kernel;

use OCA\OpenRegister\Kernel\Contracts\Core\ReadPurposeGuardInterface;
use OCA\OpenRegister\Kernel\Contracts\Core\ReadPurposeRefusalInterface;
use OCA\OpenRegister\Service\Audit\PurposeGuard;
use OCA\OpenRegister\Service\Audit\PurposeGuardInterface;
use OCA\OpenRegister\Service\Audit\PurposeRefusedException;
use OCA\OpenRegister\Service\ComplianceServiceBindings;
use OCA\OpenRegister\Service\ContractBindings;
use PHPUnit\Framework\TestCase;

/**
 * The object read paths ask the Core port; the purpose guard answers both names and throws the Core refusal.
 */
final class ReadPurposeGuardBindingTest extends TestCase {

	public function testTheReadPortAndTheGuardInterfaceResolveToTheSameGuard(): void {
		$this->assertSame(PurposeGuard::class, ComplianceServiceBindings::BINDINGS[ReadPurposeGuardInterface::class] ?? null);
		$this->assertSame(PurposeGuard::class, ContractBindings::BINDINGS[PurposeGuardInterface::class] ?? null);
	}

	public function testTheGuardInterfaceIsThePort(): void {
		$this->assertTrue(is_subclass_of(PurposeGuardInterface::class, ReadPurposeGuardInterface::class));
		$this->assertTrue(is_subclass_of(PurposeGuard::class, ReadPurposeGuardInterface::class));
		$this->assertSame(PurposeGuardInterface::ACTION_REFUSED, 'purpose.query-refused');
	}

	public function testTheGuardsRefusalIsTheCoreRefusalWithItsAnswerUnchanged(): void {
		$refusal = new PurposeRefusedException(PurposeRefusedException::RULE_MISSING, 'No purpose given', null, ['schema' => 7]);

		$this->assertInstanceOf(ReadPurposeRefusalInterface::class, $refusal);
		$this->assertSame(403, $refusal->getStatusCode());
		$this->assertSame(
			['error' => 'PURPOSE_REFUSED', 'rule' => 'purpose-missing', 'message' => 'No purpose given', 'purpose' => null, 'schema' => 7],
			$refusal->toResponseBody()
		);
	}
}//end class
