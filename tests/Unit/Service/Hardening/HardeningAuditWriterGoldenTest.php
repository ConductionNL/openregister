<?php

/**
 * Golden test for HardeningAuditWriter.
 *
 * Pins what reaches the audit trail for an allowed and for a refused hardening
 * fact, and that a failure to write is logged and swallowed.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Hardening
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Hardening;

use OCA\OpenRegister\Db\AuditTrail\HardeningChange;
use OCA\OpenRegister\Db\AuditTrail;
use OCA\OpenRegister\Db\AuditTrailMapperInterface;
use OCA\OpenRegister\Service\Hardening\HardeningAuditWriter;
use OCA\OpenRegister\Service\Hardening\HardeningFact;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

final class HardeningAuditWriterGoldenTest extends TestCase {

	/**
	 * The arguments each recordHardeningChange call received, as columns.
	 *
	 * @var list<array<string, mixed>>
	 */
	private array $entries = [];

	private function writer(?LoggerInterface $logger = null, bool $fail = false): HardeningAuditWriter {
		$mapper = $this->createMock(AuditTrailMapperInterface::class);
		$mapper->method('recordHardeningChange')->willReturnCallback(
			function (HardeningChange $change, bool $accepted) use ($fail) {
				[$control, $before, $after, $refusal] = [$change->control, $change->before, $change->after, $change->refusal];
				if ($fail === true) {
					throw new RuntimeException('disk full');
				}

				$this->entries[] = ['control' => $control, 'before' => $before, 'after' => $after, 'accepted' => $accepted, 'refusal' => $refusal];
				return new AuditTrail();
			}
		);

		return new HardeningAuditWriter($mapper, ($logger ?? $this->createMock(LoggerInterface::class)));
	}

	public function testAnAllowedFactIsWrittenAsAcceptedWithNoRefusal(): void {
		$this->writer()->accepted(change: new HardeningFact(fact: 'elevation.granted', before: '', after: ['user' => 'u']));

		$this->assertSame(
			[['control' => 'elevation.granted', 'before' => '', 'after' => ['user' => 'u'], 'accepted' => true, 'refusal' => '']],
			$this->entries
		);
	}

	public function testARefusedFactIsWrittenAsNotAcceptedWithItsRefusal(): void {
		$this->writer()->refused(change: new HardeningFact(fact: 'statement.accepted', before: 'v1', after: 'v2'), refusal: 'Not in force.');

		$this->assertSame(
			[['control' => 'statement.accepted', 'before' => 'v1', 'after' => 'v2', 'accepted' => false, 'refusal' => 'Not in force.']],
			$this->entries
		);
	}

	public function testAFailureToWriteIsLoggedAndSwallowed(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('error')->with($this->stringContains('elevation.lapsed'));

		$this->writer(logger: $logger, fail: true)->refused(change: new HardeningFact(fact: 'elevation.lapsed', before: '', after: 'u'), refusal: 'lapsed');

		$this->assertSame([], $this->entries);
	}
}//end class
