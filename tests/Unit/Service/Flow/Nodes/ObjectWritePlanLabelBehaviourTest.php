<?php

/**
 * ObjectWritePlan: a register or schema without a slug is labelled by its id.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Flow\Nodes
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Flow\Nodes;

use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\Flow\Nodes\ObjectWritePlan;
use OCP\IUser;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Service\Flow\Nodes\ObjectWritePlan
 * @uses \OCA\OpenRegister\Db\Register
 * @uses \OCA\OpenRegister\Db\Schema
 */
class ObjectWritePlanLabelBehaviourTest extends TestCase {

	public function testLabelsFallBackToTheIdWithoutASlug(): void {
		$register = new Register();
		$register->setId(12);
		$schema = new Schema();
		$schema->setId(34);
		$plan = new ObjectWritePlan(config: [], owner: $this->createMock(IUser::class), register: $register, schema: $schema);

		self::assertSame('12', $plan->registerLabel());
		self::assertSame('34', $plan->schemaLabel());

		$register->setSlug('cases');
		$schema->setSlug('case');
		self::assertSame('cases', $plan->registerLabel());
		self::assertSame('case', $plan->schemaLabel());
	}//end testLabelsFallBackToTheIdWithoutASlug()
}//end class
