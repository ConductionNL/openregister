<?php

/**
 * Unit tests for DeploymentOrigin: where a deployment came from and the state it records.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\ConfigurationDeployment
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace Unit\Service\ConfigurationDeployment;

use OCA\OpenRegister\Db\ConfigurationDeployment;
use OCA\OpenRegister\Db\ConfigurationDraftSet;
use OCA\OpenRegister\Service\ConfigurationDeployment\DeploymentOrigin;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * A deployment of an approved set names the set, its author and approver and
 * is applied; a rollback names only the deployment it restores and its actor
 * and is rolled back.
 */
#[CoversClass(DeploymentOrigin::class)]
#[UsesClass(ConfigurationDraftSet::class)]
class DeploymentOriginTest extends TestCase {

	/**
	 * An approved set's deployment carries the set, its author and approver, and is applied.
	 *
	 * @return void
	 */
	public function testASetsDeploymentNamesTheSetItsAuthorAndApprover(): void {
		$set = new ConfigurationDraftSet();
		$set->setUuid('set-1');
		$set->setCreatedBy('alice');
		$set->setApprovedBy('bob');

		$origin = DeploymentOrigin::ofSet(set: $set);

		$this->assertSame(
			expected: ['set-1', 'alice', 'bob', null],
			actual: [$origin->setUuid, $origin->author, $origin->approver, $origin->restoresUuid]
		);
		$this->assertSame(expected: ConfigurationDeployment::STATE_APPLIED, actual: $origin->state());
		$this->assertSame(expected: 'applied', actual: $origin->state());
	}//end testASetsDeploymentNamesTheSetItsAuthorAndApprover()

	/**
	 * A set never approved keeps a null approver; a set without a uuid gives an empty one.
	 *
	 * @return void
	 */
	public function testAnUnapprovedSetKeepsANullApprover(): void {
		$origin = DeploymentOrigin::ofSet(set: new ConfigurationDraftSet());

		$this->assertSame(expected: ['', null, null], actual: [$origin->setUuid, $origin->author, $origin->approver]);
		$this->assertSame(expected: 'applied', actual: $origin->state());
	}//end testAnUnapprovedSetKeepsANullApprover()

	/**
	 * A rollback names the deployment it restores and who rolled back, no set, and is rolled back.
	 *
	 * @return void
	 */
	public function testARollbackNamesWhatItRestores(): void {
		$origin = DeploymentOrigin::rollbackOf(restoresUuid: 'dep-7', actor: 'carol');

		$this->assertSame(
			expected: [null, 'carol', null, 'dep-7'],
			actual: [$origin->setUuid, $origin->author, $origin->approver, $origin->restoresUuid]
		);
		$this->assertSame(expected: ConfigurationDeployment::STATE_ROLLED_BACK, actual: $origin->state());
		$this->assertSame(expected: 'rolled-back', actual: DeploymentOrigin::rollbackOf(restoresUuid: 'dep-7', actor: null)->state());
	}//end testARollbackNamesWhatItRestores()
}//end class
