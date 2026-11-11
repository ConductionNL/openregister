<?php

/**
 * ApprovalRollbackRun counts carried steps and collects the facts each one lost.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Command\Support
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Command\Support;

use OCA\OpenRegister\Command\Support\ApprovalRollbackRun;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * Behaviour of the rollback run tally.
 */
class ApprovalRollbackRunTest extends TestCase {

	public function testFreshRunHasWrittenNothingAndLostNothing(): void {
		$run = new ApprovalRollbackRun(true, new BufferedOutput());

		$this->assertSame(0, $run->written());
		$this->assertSame([], $run->notCarriable());
	}

	public function testEveryCarriedStepCountsEvenWithoutLostFacts(): void {
		$run = new ApprovalRollbackRun(false, new BufferedOutput());

		$run->recordCarried('step-a', []);
		$run->recordCarried('step-b', []);

		$this->assertSame(2, $run->written());
		$this->assertSame([], $run->notCarriable());
	}

	public function testLostFactsArePrefixedWithTheirStepInOrder(): void {
		$run = new ApprovalRollbackRun(false, new BufferedOutput());

		$run->recordCarried('approve', ['comment', 'delegate']);
		$run->recordCarried('reject', ['reason']);

		$this->assertSame(2, $run->written());
		$this->assertSame(['approve: comment', 'approve: delegate', 'reject: reason'], $run->notCarriable());
	}
}
