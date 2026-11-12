<?php

/**
 * RechainAuditTrailCommand refuses to rewrite the audit chain when it cannot ask for consent.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Command
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Command;

use OCA\OpenRegister\Command\RechainAuditTrailCommand;
use OCA\OpenRegister\Service\AuditRechainInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\FormatterHelper;
use Symfony\Component\Console\Helper\HelperSet;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Behaviour when the `question` helper is not a QuestionHelper.
 */
class RechainAuditTrailCommandNoPromptTest extends TestCase {

	private function tester(AuditRechainInterface $hashes): CommandTester {
		$command = new RechainAuditTrailCommand($hashes);
		// A helper set whose `question` slot holds something that cannot prompt.
		$command->setHelperSet(new HelperSet(['question' => new FormatterHelper()]));
		return new CommandTester($command);
	}

	public function testWithoutAPromptAndWithoutForceNothingIsRewritten(): void {
		$hashes = $this->createMock(AuditRechainInterface::class);
		$hashes->method('verifyChain')->willReturn(['valid' => false, 'entriesVerified' => 3, 'brokenAt' => 2, 'skippedNullHashes' => 0]);
		$hashes->expects($this->never())->method('rechainAll');
		$tester = $this->tester($hashes);

		$code = $tester->execute([]);

		$this->assertSame(Command::FAILURE, $code);
		$this->assertStringContainsString('No question helper available to confirm with.', $tester->getDisplay());
		$this->assertStringContainsString('Re-run with --force', $tester->getDisplay());
	}

	public function testForceStillRechainsWithoutAPrompt(): void {
		$hashes = $this->createMock(AuditRechainInterface::class);
		$hashes->method('verifyChain')->willReturn(['valid' => true, 'entriesVerified' => 3, 'brokenAt' => null, 'skippedNullHashes' => 0]);
		$hashes->expects($this->once())->method('rechainAll')->willReturn(['rechained' => 3, 'tombstonesPreserved' => 1]);
		$tester = $this->tester($hashes);

		$code = $tester->execute(['--force' => true]);

		$this->assertSame(Command::SUCCESS, $code);
		$this->assertStringContainsString('Re-chained 3 row(s); left 1 retention tombstone(s) untouched.', $tester->getDisplay());
		$this->assertStringNotContainsString('No question helper', $tester->getDisplay());
	}
}
