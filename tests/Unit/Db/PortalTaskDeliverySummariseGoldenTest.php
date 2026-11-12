<?php

/**
 * Golden record of PortalTaskDelivery::summarise() over the delivery-row combinations it folds.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Db
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db;

use DateTime;
use OCA\OpenRegister\Db\PortalTaskDelivery;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Db\PortalTaskDelivery
 *
 * @spec openspec/changes/flow-portal-task/specs/flow-portal-task/spec.md#requirement-delivery-rides-the-portal-contribution-surface-and-nothing-else
 */
class PortalTaskDeliverySummariseGoldenTest extends TestCase {
	private const T1 = '2026-03-01T10:00:00+00:00';
	private const T2 = '2026-03-02T10:00:00+00:00';
	private const D1 = '2026-03-01T10:05:00+00:00';
	private const D2 = '2026-03-02T10:07:00+00:00';

	/**
	 * A delivery row.
	 *
	 * @param array{0: string, 1: string, 2?: string|null, 3?: string|null, 4?: string, 5?: string|null} $spec
	 *        channel, state, requestedAt, deliveredAt, kind, error.
	 *
	 * @return PortalTaskDelivery The row.
	 */
	private static function row(array $spec): PortalTaskDelivery {
		$row = new PortalTaskDelivery();
		$row->setChannel($spec[0]);
		$row->setState($spec[1]);
		if (($spec[2] ?? null) !== null) {
			$row->setRequestedAt(new DateTime($spec[2]));
		}

		if (($spec[3] ?? null) !== null) {
			$row->setDeliveredAt(new DateTime($spec[3]));
		}

		$row->setKind($spec[4] ?? 'ask');
		$row->setError($spec[5] ?? null);
		return $row;
	}//end row()

	/**
	 * Row specs and the summary they produce.
	 *
	 * @return array<string, array{0: list<array<int, mixed>>, 1: array<string, mixed>}>
	 */
	public static function cases(): array {
		$inbox = 'portal-inbox';
		$mail = 'mail';
		return [
			'no rows' => [[], ['state' => 'not-recorded', 'channels' => [], 'requestedAt' => null, 'deliveredAt' => null]],
			'inbox requested' => [
				[[$inbox, 'requested', self::T1]],
				['state' => 'requested', 'channels' => [$inbox => ['state' => 'requested', 'kind' => 'ask', 'error' => null, 'deliveredAt' => null]], 'requestedAt' => self::T1, 'deliveredAt' => null],
			],
			'inbox delivered, mail pending' => [
				[[$mail, 'requested', self::T1], [$inbox, 'delivered', self::T1, self::D1]],
				[
					'state' => 'delivered',
					'channels' => [
						$mail => ['state' => 'requested', 'kind' => 'ask', 'error' => null, 'deliveredAt' => null],
						$inbox => ['state' => 'delivered', 'kind' => 'ask', 'error' => null, 'deliveredAt' => self::D1],
					],
					'requestedAt' => self::T1,
					'deliveredAt' => self::D1,
				],
			],
			'only mail delivered stays requested' => [
				[[$mail, 'delivered', self::T1, self::D1]],
				['state' => 'requested', 'channels' => [$mail => ['state' => 'delivered', 'kind' => 'ask', 'error' => null, 'deliveredAt' => self::D1]], 'requestedAt' => self::T1, 'deliveredAt' => null],
			],
			'inbox delivered then mail failed' => [
				[[$inbox, 'delivered', self::T1, self::D1], [$mail, 'failed', self::T1, null, 'ask', 'smtp down']],
				[
					'state' => 'failed',
					'channels' => [
						$inbox => ['state' => 'delivered', 'kind' => 'ask', 'error' => null, 'deliveredAt' => self::D1],
						$mail => ['state' => 'failed', 'kind' => 'ask', 'error' => 'smtp down', 'deliveredAt' => null],
					],
					'requestedAt' => self::T1,
					'deliveredAt' => self::D1,
				],
			],
			'mail failed then inbox delivered' => [
				[[$mail, 'failed', self::T1, null, 'ask', 'smtp down'], [$inbox, 'delivered', self::T1, self::D1]],
				[
					'state' => 'failed',
					'channels' => [
						$mail => ['state' => 'failed', 'kind' => 'ask', 'error' => 'smtp down', 'deliveredAt' => null],
						$inbox => ['state' => 'delivered', 'kind' => 'ask', 'error' => null, 'deliveredAt' => self::D1],
					],
					'requestedAt' => self::T1,
					'deliveredAt' => null,
				],
			],
			're-ask: only the newest round counts' => [
				[[$inbox, 'failed', self::T1, null, 'ask', 'boom'], [$inbox, 'delivered', self::T2, self::D2, 're-ask'], [$mail, 'failed', self::T1]],
				['state' => 'delivered', 'channels' => [$inbox => ['state' => 'delivered', 'kind' => 're-ask', 'error' => null, 'deliveredAt' => self::D2]], 'requestedAt' => self::T2, 'deliveredAt' => self::D2],
			],
			'rows without a request time always count' => [
				[[$mail, 'requested'], [$inbox, 'requested', self::T2], [$inbox, 'delivered', self::T1, self::D1]],
				['state' => 'requested', 'channels' => [$mail => ['state' => 'requested', 'kind' => 'ask', 'error' => null, 'deliveredAt' => null], $inbox => ['state' => 'requested', 'kind' => 'ask', 'error' => null, 'deliveredAt' => null]], 'requestedAt' => self::T2, 'deliveredAt' => null],
			],
			'no request times at all' => [
				[[$inbox, 'delivered', null, self::D1], [$mail, 'requested']],
				['state' => 'delivered', 'channels' => [$inbox => ['state' => 'delivered', 'kind' => 'ask', 'error' => null, 'deliveredAt' => self::D1], $mail => ['state' => 'requested', 'kind' => 'ask', 'error' => null, 'deliveredAt' => null]], 'requestedAt' => null, 'deliveredAt' => self::D1],
			],
			'same channel twice keeps first position, last values' => [
				[[$inbox, 'requested', self::T1], [$mail, 'requested', self::T1], [$inbox, 'delivered', self::T1, self::D1, 'reminder']],
				['state' => 'delivered', 'channels' => [$inbox => ['state' => 'delivered', 'kind' => 'reminder', 'error' => null, 'deliveredAt' => self::D1], $mail => ['state' => 'requested', 'kind' => 'ask', 'error' => null, 'deliveredAt' => null]], 'requestedAt' => self::T1, 'deliveredAt' => self::D1],
			],
			'inbox delivered twice takes the later fold' => [
				[[$inbox, 'delivered', self::T1, self::D1], [$inbox, 'delivered', self::T1, self::D2]],
				['state' => 'delivered', 'channels' => [$inbox => ['state' => 'delivered', 'kind' => 'ask', 'error' => null, 'deliveredAt' => self::D2]], 'requestedAt' => self::T1, 'deliveredAt' => self::D2],
			],
		];
	}//end cases()

	#[DataProvider('cases')]
	public function testSummariseMatchesGolden(array $specs, array $expected): void {
		$rows = array_map(self::row(...), $specs);
		$this->assertSame($expected, PortalTaskDelivery::summarise($rows));
	}//end testSummariseMatchesGolden()
}//end class
