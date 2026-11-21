<?php

/**
 * Unit tests for DetectorVersionHistory: a changed record starts an entry at
 * the moment it is first seen, an unchanged one does not, and a record's own
 * change day wins over that moment.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Anonymisation
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/anonymisation-discloses-itself/specs/anonymisation-disclosure/spec.md#requirement-the-detector-says-when-it-last-changed-req-adi-004
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Anonymisation;

use OCA\OpenRegister\Service\Anonymisation\DetectorVersion;
use OCA\OpenRegister\Service\Anonymisation\DetectorVersionHistory;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Service\Anonymisation\DetectorVersionHistory
 * @uses   \OCA\OpenRegister\Service\Anonymisation\DetectorVersion
 */
final class DetectorVersionHistoryTest extends TestCase {
	use InMemoryAppConfigTrait;

	/**
	 * A Presidio record at a model version.
	 *
	 * @param string $modelVersion The model version.
	 *
	 * @return DetectorVersion
	 */
	private static function presidio(string $modelVersion): DetectorVersion {
		return new DetectorVersion(fields: ['engine' => 'presidio', 'model' => 'nl_core_news_lg', 'modelVersion' => $modelVersion]);
	}//end presidio()

	public function testANewModelVersionStartsAHistoryEntry(): void {
		$history = new DetectorVersionHistory($this->inMemoryAppConfig());

		$first = $history->observe('presidio', self::presidio('1.4'), '2026-11-01T09:00:00+00:00');
		$again = $history->observe('presidio', self::presidio('1.4'), '2026-11-05T09:00:00+00:00');
		$upgraded = $history->observe('presidio', self::presidio('1.5'), '2026-11-10T09:00:00+00:00');

		$this->assertSame('2026-11-01T09:00:00+00:00', $first->changedAt);
		$this->assertSame('2026-11-01T09:00:00+00:00', $again->changedAt);
		$this->assertSame('2026-11-10T09:00:00+00:00', $upgraded->changedAt);
		$this->assertSame('1.5', $upgraded->modelVersion);
		$entries = $history->entries('presidio');
		$this->assertSame(['1.4', '1.5'], array_map(static fn (array $entry): string => $entry['record']['modelVersion'], $entries));
		$this->assertSame(['2026-11-01T09:00:00+00:00', '2026-11-10T09:00:00+00:00'], array_column($entries, 'firstSeen'));
		$this->assertSame(2, $this->configWrites);
		$this->assertArrayHasKey('openregister/' . DetectorVersionHistory::CONFIG_KEY, $this->configValues);
	}

	public function testARecordsOwnChangeDayWinsButTheEntryIsStillKept(): void {
		$history = new DetectorVersionHistory($this->inMemoryAppConfig());
		$record = new DetectorVersion(fields: ['engine' => 'openregister-patterns', 'patternSetVersion' => '1.0.0', 'changedAt' => '2026-11-13']);

		$seen = $history->observe('regex', $record, '2026-11-20T10:00:00+00:00');

		$this->assertSame('2026-11-13', $seen->changedAt);
		$this->assertSame('2026-11-20T10:00:00+00:00', $history->entries('regex')[0]['firstSeen']);
	}

	public function testBackendsKeepTheirOwnHistory(): void {
		$history = new DetectorVersionHistory($this->inMemoryAppConfig());

		$history->observe('presidio', self::presidio('1.4'), '2026-11-01T09:00:00+00:00');
		$history->observe('llm', new DetectorVersion(fields: ['engine' => 'llm']), '2026-11-02T09:00:00+00:00');

		$this->assertCount(1, $history->entries('presidio'));
		$this->assertCount(1, $history->entries('llm'));
		$this->assertSame([], $history->entries('hybrid'));
	}

	public function testOnlyTheNewestEntriesAreKept(): void {
		$history = new DetectorVersionHistory($this->inMemoryAppConfig());
		for ($i = 1; $i <= DetectorVersionHistory::MAX_ENTRIES + 2; $i++) {
			$history->observe('presidio', self::presidio('1.' . $i), sprintf('2026-11-%02dT00:00:00+00:00', $i));
		}

		$entries = $history->entries('presidio');
		$this->assertCount(DetectorVersionHistory::MAX_ENTRIES, $entries);
		$this->assertSame('1.3', $entries[0]['record']['modelVersion']);
		$this->assertSame('1.22', $entries[DetectorVersionHistory::MAX_ENTRIES - 1]['record']['modelVersion']);
	}

	public function testAnUnreadableStoreStartsAfresh(): void {
		$config = $this->inMemoryAppConfig();
		$this->configValues['openregister/' . DetectorVersionHistory::CONFIG_KEY] = '{"presidio":[{"record":"x"},{"firstSeen":"t"}],"7":"no"';
		$history = new DetectorVersionHistory($config);

		$this->assertSame([], $history->entries('presidio'));
		$this->assertSame('2026-11-01T09:00:00+00:00', $history->observe('presidio', self::presidio('1.4'), '2026-11-01T09:00:00+00:00')->changedAt);

		$this->configValues['openregister/' . DetectorVersionHistory::CONFIG_KEY] = '{"presidio":[{"record":"x"},{"firstSeen":"t"}],"llm":"no"}';
		$this->assertSame([], $history->entries('presidio'));
		$this->assertSame([], $history->entries('llm'));
	}
}
