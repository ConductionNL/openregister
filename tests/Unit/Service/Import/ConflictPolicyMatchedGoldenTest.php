<?php

/**
 * Golden coverage for what ConflictPolicy decides for a row that matches one object.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Import
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Import;

use OCA\OpenRegister\Service\Import\ConflictPolicy;
use OCA\OpenRegister\Db\ImportPreviewRow;
use PHPUnit\Framework\TestCase;

/**
 * Locks the decision per policy when the match key hits exactly one object.
 */
final class ConflictPolicyMatchedGoldenTest extends TestCase {

	/**
	 * Create-only and refuse-on-conflict refuse naming the policy and the object; the rest update it.
	 *
	 * @return void
	 */
	public function testEachPolicyDecidesAMatchedRow(): void {
		foreach ([ConflictPolicy::CREATE_ONLY, ConflictPolicy::REFUSE_ON_CONFLICT] as $policy) {
			$this->assertSame(
				[
					'decision' => ImportPreviewRow::DECISION_REFUSE,
					'reason' => 'Policy '.$policy.', and the match key hits existing object u-1.',
					'targetUuid' => null,
				],
				ConflictPolicy::decide($policy, ['u-1'])
			);
		}

		$this->assertSame(
			['decision' => ImportPreviewRow::DECISION_UPDATE, 'reason' => null, 'targetUuid' => 'u-1'],
			ConflictPolicy::decide(ConflictPolicy::UPSERT, ['u-1'])
		);
	}//end testEachPolicyDecidesAMatchedRow()
}//end class
