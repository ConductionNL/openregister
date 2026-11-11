<?php

/**
 * Golden test pinning the rules ObjectPermissionsResolver reads from entries written under a verb.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Rbac;

use OCA\OpenRegister\Service\Rbac\ObjectPermissionsResolver;
use PHPUnit\Framework\TestCase;

/**
 * Entries that name a principal hold the verb (conditionally when they carry a match); entries that name none grant nothing.
 */
class ObjectPermissionsVerbRuleGoldenTest extends TestCase {

	/**
	 * The full holder set for one verb block, pinned as computed.
	 *
	 * @return void
	 */
	public function testVerbEntriesBecomeRules(): void {
		$set = (new ObjectPermissionsResolver())->holders(
			blocks: ['schema' => ['read' => ['alice', ['group' => 'intake', 'match' => ['status' => 'open']], ['nothing' => 'here'], '']]]
		);

		$alice = ['principal' => 'alice', 'action' => 'read', 'level' => 'schema', 'role' => null, 'rule' => 'alice', 'conditional' => false, 'declared' => true];
		$intakeEntry = ['group' => 'intake', 'match' => ['status' => 'open']];
		$intake = ['principal' => 'intake', 'action' => 'read', 'level' => 'schema', 'role' => null, 'rule' => $intakeEntry, 'conditional' => true, 'declared' => true];

		$this->assertSame(
			[
				'holders' => [
					['principal' => 'alice', 'verbs' => ['read'], 'rules' => [$alice]],
					['principal' => 'intake', 'verbs' => ['read'], 'rules' => [$intake]],
				],
				'denied' => [],
			],
			$set
		);
	}//end testVerbEntriesBecomeRules()
}//end class
