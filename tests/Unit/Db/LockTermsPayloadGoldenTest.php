<?php

/**
 * Characterisation of LockTerms::payloadFor(): a lock is a user lock unless a run uuid is
 * given, in which case it is a run lock carrying the trimmed uuid.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Db
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db;

use DateTime;
use OCA\OpenRegister\Db\LockTerms;
use OCA\OpenRegister\Db\ObjectEntity;
use PHPUnit\Framework\TestCase;

/**
 * Golden lock payloads.
 *
 * @covers \OCA\OpenRegister\Db\LockTerms
 */
final class LockTermsPayloadGoldenTest extends TestCase {

	/**
	 * The terms and the payload each gives.
	 *
	 * @return array<string, array{0: LockTerms, 1: string, 2: string|null, 3: string|null, 4: int|null}>
	 */
	public static function cases(): array {
		return [
			'defaults' => [new LockTerms(), ObjectEntity::LOCK_KIND_USER, null, null, 3600],
			'process and duration' => [new LockTerms(process: 'import', duration: 60), ObjectEntity::LOCK_KIND_USER, null, 'import', 60],
			'run' => [new LockTerms(runUuid: ' r-1 '), ObjectEntity::LOCK_KIND_RUN, 'r-1', null, 3600],
			'blank run stays a user lock, untrimmed' => [new LockTerms(runUuid: '  '), ObjectEntity::LOCK_KIND_USER, '  ', null, 3600],
			'empty run' => [new LockTerms(runUuid: ''), ObjectEntity::LOCK_KIND_USER, '', null, 3600],
			'null duration' => [new LockTerms(duration: null), ObjectEntity::LOCK_KIND_USER, null, null, null],
		];
	}//end cases()

	/**
	 * The payload names the kind, run, user, process, time and duration, in this order.
	 *
	 * @param LockTerms   $terms    The terms.
	 * @param string      $kind     The lock kind.
	 * @param string|null $runUuid  The run uuid.
	 * @param string|null $process  The process.
	 * @param int|null    $duration The duration.
	 *
	 * @return void
	 *
	 * @dataProvider cases
	 */
	public function testPayload(LockTerms $terms, string $kind, ?string $runUuid, ?string $process, ?int $duration): void {
		$this->assertSame(
			['kind' => $kind, 'runUuid' => $runUuid, 'user' => 'anna', 'process' => $process, 'created' => '2026-02-03T04:05:06+00:00', 'duration' => $duration],
			$terms->payloadFor('anna', new DateTime('2026-02-03T04:05:06+00:00'))
		);
	}//end testPayload()
}//end class
