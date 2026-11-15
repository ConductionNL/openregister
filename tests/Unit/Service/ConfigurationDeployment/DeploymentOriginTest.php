<?php

/**
 * DeploymentOrigin: where a configuration deployment came from, and the state it records.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\ConfigurationDeployment
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/unit-test-coverage/tasks.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\ConfigurationDeployment;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Db\ConfigurationDeployment;
use OCA\OpenRegister\Db\ConfigurationDraftSet;
use OCA\OpenRegister\Service\ConfigurationDeployment\DeploymentOrigin;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Service\ConfigurationDeployment\DeploymentOrigin
 * @uses   \OCA\OpenRegister\Db\ConfigurationDraftSet
 */
class DeploymentOriginTest extends TestCase {

	public function testADraftSetDeploysAsAppliedWithItsAuthorAndApprover(): void {
		$set = new ConfigurationDraftSet();
		$set->setUuid('set-1');
		$set->setCreatedBy('alice');
		$set->setApprovedBy('bob');

		$origin = DeploymentOrigin::ofSet($set);

		$this->assertSame(['set-1', 'alice', 'bob', null], [$origin->setUuid, $origin->author, $origin->approver, $origin->restoresUuid]);
		$this->assertSame(ConfigurationDeployment::STATE_APPLIED, $origin->state());
	}

	public function testADraftSetWithoutUuidDeploysUnderAnEmptyUuid(): void {
		$origin = DeploymentOrigin::ofSet(new ConfigurationDraftSet());

		$this->assertSame('', $origin->setUuid);
		$this->assertNull($origin->author);
	}

	public function testARollbackRecordsTheRestoredDeploymentAndTheActorOnly(): void {
		$origin = DeploymentOrigin::rollbackOf('dep-7', 'carol');

		$this->assertSame([null, 'carol', null, 'dep-7'], [$origin->setUuid, $origin->author, $origin->approver, $origin->restoresUuid]);
		$this->assertSame(ConfigurationDeployment::STATE_ROLLED_BACK, $origin->state());
		$this->assertNull(DeploymentOrigin::rollbackOf('dep-7', null)->author);
	}
}
