<?php

/**
 * Behaviour test for DestructionListApproval and CredentialUpdateTarget.
 *
 * Asserts what an exclusion records (and that one without a uuid records
 * nothing), which entries dropEntries removes, and when a second approval is
 * the same person as the first (four-eyes rule).
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Test
 * @package   OCA\OpenRegister\Tests\Unit\Controller\Support
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://www.OpenRegister.app
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Controller\Support;

use OCA\OpenRegister\Controller\Support\CredentialUpdateTarget;
use OCA\OpenRegister\Controller\Support\DestructionListApproval;
use PHPUnit\Framework\TestCase;

class DestructionListApprovalBehaviourTest extends TestCase {

	private function approval(string $userId, array $listData = []): DestructionListApproval {
		$approval = new DestructionListApproval();
		$approval->userId = $userId;
		$approval->listData = $listData;
		return $approval;
	}

	public function testAnExclusionIsRecordedAgainstTheApprover(): void {
		$approval = $this->approval('archivist');
		$this->assertSame('o1', $approval->exclude(['uuid' => 'o1', 'reason' => 'Still in use']));
		$this->assertSame('o2', $approval->exclude(['uuid' => 'o2']));

		$this->assertSame(
			[
				['uuid' => 'o1', 'reason' => 'Still in use', 'excludedBy' => 'archivist'],
				['uuid' => 'o2', 'reason' => 'No reason provided', 'excludedBy' => 'archivist'],
			],
			$approval->listData['excluded']
		);
	}

	public function testAnExclusionWithoutAUuidRecordsNothing(): void {
		$approval = $this->approval('archivist');
		$this->assertNull($approval->exclude(['reason' => 'whatever']));
		$this->assertArrayNotHasKey('excluded', $approval->listData);
	}

	public function testDropEntriesRemovesOnlyTheNamedObjectsAndReindexes(): void {
		$approval = $this->approval('a', ['objects' => [['uuid' => 'o1'], ['uuid' => 'o2'], ['uuid' => 'o3'], ['title' => 'no uuid']]]);
		$approval->dropEntries(['o1', 'o3']);
		$this->assertSame([['uuid' => 'o2'], ['title' => 'no uuid']], $approval->listData['objects']);
	}

	public function testDropEntriesOnAListWithoutObjectsLeavesItEmpty(): void {
		$approval = $this->approval('a');
		$approval->dropEntries(['o1']);
		$this->assertSame([], $approval->listData['objects']);
	}

	public function testASingleApprovalNeverRepeatsTheFirstApprover(): void {
		$this->assertFalse($this->approval('alice', ['approvals' => [['userId' => 'alice']]])->repeatsFirstApprover());
		$this->assertFalse($this->approval('alice')->repeatsFirstApprover());
	}

	public function testTheSecondApprovalBySameUserIsARepeat(): void {
		$approvals = ['approvals' => [['userId' => 'alice'], ['userId' => 'alice']]];
		$this->assertTrue($this->approval('alice', $approvals)->repeatsFirstApprover());
	}

	public function testTheSecondApprovalByAnotherUserIsNoRepeat(): void {
		$approvals = ['approvals' => [['userId' => 'alice'], ['userId' => 'bob']]];
		$this->assertFalse($this->approval('bob', $approvals)->repeatsFirstApprover());
		$this->assertFalse($this->approval('bob', ['approvals' => [[], []]])->repeatsFirstApprover());
	}

	public function testACredentialUpdateTargetKeepsWhatItWasGiven(): void {
		$target = new CredentialUpdateTarget('7', 'uuid-7', 'register', '2026-01-01T00:00:00+00:00');
		$this->assertSame(['7', 'uuid-7', 'register', '2026-01-01T00:00:00+00:00'], [$target->id, $target->uuid, $target->scope, $target->rotated]);
	}
}
