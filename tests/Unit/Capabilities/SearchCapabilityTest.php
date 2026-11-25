<?php

/**
 * The `openregister.search.stemming` capability: the configuration on PostgreSQL, false elsewhere.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Capabilities
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Capabilities;

use Doctrine\DBAL\Platforms\MariaDb1043Platform;
use Doctrine\DBAL\Platforms\MySQL80Platform;
use Doctrine\DBAL\Platforms\PostgreSQL120Platform;
use OCA\OpenRegister\Capabilities\SearchCapability;
use OCA\OpenRegister\Service\Search\TextSearchConfiguration;
use OCP\IAppConfig;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Capabilities\SearchCapability
 * @uses \OCA\OpenRegister\Service\Search\TextSearchConfiguration
 *
 * @spec openspec/changes/search-dutch-language-quality/specs/zoeken-filteren/spec.md
 */
final class SearchCapabilityTest extends TestCase {

	private function capabilityOn(string $platformClass, string $stored = 'dutch'): SearchCapability {
		$db = $this->createMock(IDBConnection::class);
		$db->method('getDatabasePlatform')->willReturn($this->createMock($platformClass));
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn($stored);

		return new SearchCapability(textSearch: new TextSearchConfiguration(db: $db, appConfig: $appConfig));
	}

	public function testPostgresReportsDutch(): void {
		$this->assertSame(
			['openregister' => ['search' => ['stemming' => 'dutch']]],
			$this->capabilityOn(platformClass: PostgreSQL120Platform::class)->getCapabilities()
		);
	}

	public function testPostgresReportsTheSavedConfiguration(): void {
		$this->assertSame(
			'english',
			$this->capabilityOn(platformClass: PostgreSQL120Platform::class, stored: 'english')->getCapabilities()['openregister']['search']['stemming']
		);
	}

	public function testMysqlReportsNoStemming(): void {
		$this->assertSame(
			['openregister' => ['search' => ['stemming' => false]]],
			$this->capabilityOn(platformClass: MySQL80Platform::class)->getCapabilities()
		);
	}

	public function testMariaDbReportsNoStemming(): void {
		$this->assertFalse($this->capabilityOn(platformClass: MariaDb1043Platform::class)->getCapabilities()['openregister']['search']['stemming']);
	}
}
