<?php

/**
 * ReconcileDeclaredBackgroundJobs names itself for occ.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Repair
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Repair;

use OCA\OpenRegister\Repair\ReconcileDeclaredBackgroundJobs;
use OCP\BackgroundJob\IJobList;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * The step name; the reconciliation itself is covered by the golden test.
 */
class ReconcileDeclaredBackgroundJobsNameTest extends TestCase {

	public function testTheStepNameIsWhatOccPrints(): void {
		$step = new ReconcileDeclaredBackgroundJobs(
			$this->createMock(IJobList::class),
			$this->createMock(ContainerInterface::class),
			$this->createMock(LoggerInterface::class)
		);

		$this->assertSame(
			'Register OpenRegister background jobs declared in info.xml but missing from the job list',
			$step->getName()
		);
	}
}
