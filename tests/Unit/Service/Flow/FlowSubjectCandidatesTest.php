<?php

/**
 * FlowSubjectCandidates hands back a claim only when exactly one candidate object matches.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Flow
 * @author   Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Flow;

use OCA\OpenRegister\Service\Flow\FlowSubjectCandidates;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Service\Flow\FlowSubjectCandidates
 * @covers \OCA\OpenRegister\Service\Flow\FlowSubjectClaim
 */
class FlowSubjectCandidatesTest extends TestCase {
	/**
	 * A single candidate becomes a claim carrying role, uuid, register and schema.
	 *
	 * @return void
	 */
	public function testSoleCandidateBecomesClaim(): void {
		$claim = (new FlowSubjectCandidates(role: 'applicant', uuids: ['k' => 'uuid-1'], register: 'r', schema: 's'))->sole();
		$this->assertNotNull($claim);
		$this->assertSame(['applicant', 'uuid-1', 'r', 's'], [$claim->role, $claim->uuid, $claim->register, $claim->schema]);
	}

	/**
	 * Register and schema default to empty on the claim.
	 *
	 * @return void
	 */
	public function testSoleCandidateDefaultsRegisterAndSchema(): void {
		$claim = (new FlowSubjectCandidates(role: 'case', uuids: ['uuid-9']))->sole();
		$this->assertSame(['uuid-9', '', ''], [$claim->uuid, $claim->register, $claim->schema]);
	}

	/**
	 * No candidates means no claim.
	 *
	 * @return void
	 */
	public function testNoCandidateGivesNoClaim(): void {
		$this->assertNull((new FlowSubjectCandidates(role: 'case', uuids: []))->sole());
	}

	/**
	 * Two candidates are ambiguous and give no claim.
	 *
	 * @return void
	 */
	public function testAmbiguousCandidatesGiveNoClaim(): void {
		$this->assertNull((new FlowSubjectCandidates(role: 'case', uuids: ['a', 'b']))->sole());
	}
}
