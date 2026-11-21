<?php

/**
 * Unit tests for {@see \OCA\OpenRegister\Service\Delegation\DelegationRefused}.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Delegation
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Delegation;

use OCA\OpenRegister\Service\Delegation\DelegationRefused;
use OCA\OpenRegister\Service\Delegation\DelegationVerdict;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The exception thrown when acting-as is refused carries the verdict that refused it.
 *
 * @covers \OCA\OpenRegister\Service\Delegation\DelegationRefused
 * @uses   \OCA\OpenRegister\Service\Delegation\DelegationVerdict
 */
class DelegationRefusedTest extends TestCase {

	/**
	 * The message names both parties and the verdict's detail; accessors return the inputs.
	 *
	 * @return void
	 */
	public function testCarriesPartiesAndVerdict(): void {
		$verdict = DelegationVerdict::refused(DelegationVerdict::REASON_DENIED, 'alice said no.');
		$refused = new DelegationRefused('bot', 'alice', $verdict);

		$this->assertInstanceOf(RuntimeException::class, $refused);
		$this->assertSame('Refusing to act as "alice" on behalf of "bot": alice said no.', $refused->getMessage());
		$this->assertSame('bot', $refused->getPrincipal());
		$this->assertSame('alice', $refused->getActingAs());
		$this->assertSame($verdict, $refused->getVerdict());

	}//end testCarriesPartiesAndVerdict()

	/**
	 * Consent may be requested only for reasons where asking is the next step.
	 *
	 * @return void
	 */
	public function testMayRequestConsentFollowsTheReason(): void {
		$expected = [
			DelegationVerdict::REASON_NONE         => true,
			DelegationVerdict::REASON_EXPIRED      => true,
			DelegationVerdict::REASON_OUT_OF_SCOPE => true,
			DelegationVerdict::REASON_DENIED       => false,
			DelegationVerdict::REASON_PENDING      => false,
			DelegationVerdict::REASON_REVOKED      => false,
			DelegationVerdict::REASON_UNNAMED      => false,
			DelegationVerdict::REASON_UNREADABLE   => false,
		];

		foreach ($expected as $reason => $may) {
			$refused = new DelegationRefused('p', 'u', DelegationVerdict::refused($reason, 'x'));
			$this->assertSame($may, $refused->mayRequestConsent(), $reason);
		}

	}//end testMayRequestConsentFollowsTheReason()
}//end class
