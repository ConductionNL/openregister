<?php

/**
 * The `search.textSearchConfig` setting: default, validation against `pg_ts_config`, platform.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Settings
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Settings;

use Doctrine\DBAL\Platforms\MySQL80Platform;
use Doctrine\DBAL\Platforms\PostgreSQL120Platform;
use InvalidArgumentException;
use OCA\OpenRegister\Service\Search\TextSearchConfiguration;
use OCP\DB\IResult;
use OCP\IAppConfig;
use OCP\IDBConnection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Service\Search\TextSearchConfiguration
 *
 * @spec openspec/changes/search-dutch-language-quality/specs/zoeken-filteren/spec.md
 */
final class SearchConfigSettingTest extends TestCase {

	private IDBConnection&MockObject $db;
	private IAppConfig&MockObject $appConfig;

	protected function setUp(): void {
		$this->db        = $this->createMock(IDBConnection::class);
		$this->appConfig = $this->createMock(IAppConfig::class);
	}

	private function onPostgres(array $configs = ['dutch', 'english', 'simple']): TextSearchConfiguration {
		$this->db->method('getDatabasePlatform')->willReturn($this->createMock(PostgreSQL120Platform::class));
		$result = $this->createMock(IResult::class);
		$result->method('fetchAll')->willReturn($configs);
		$this->db->method('executeQuery')->willReturn($result);

		return new TextSearchConfiguration(db: $this->db, appConfig: $this->appConfig);
	}

	public function testAnUnknownConfigurationIsRefused(): void {
		$setting = $this->onPostgres();
		$this->appConfig->expects($this->never())->method('setValueString');

		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('Known: dutch, english, simple.');
		$setting->save(name: 'klingon');
	}

	public function testANameThatIsNotAnIdentifierIsRefusedEvenIfListed(): void {
		$setting = $this->onPostgres(configs: ["dutch'); DROP TABLE x; --"]);
		$this->appConfig->expects($this->never())->method('setValueString');

		$this->expectException(InvalidArgumentException::class);
		$setting->save(name: "dutch'); DROP TABLE x; --");
	}

	public function testAListedConfigurationIsSavedLowerCase(): void {
		$setting = $this->onPostgres();
		$this->appConfig->expects($this->once())->method('setValueString')
			->with('openregister', 'search.textSearchConfig', 'english');

		$this->assertSame('english', $setting->save(name: ' English '));
	}

	public function testTheDefaultIsDutch(): void {
		$setting = $this->onPostgres();
		$this->appConfig->method('getValueString')
			->with('openregister', 'search.textSearchConfig', 'dutch')
			->willReturn('dutch');

		$this->assertSame('dutch', $setting->active());
		$this->assertSame('dutch', $setting->stemming());
	}

	public function testAStoredValueThatIsNotAnIdentifierFallsBackToTheDefault(): void {
		$setting = $this->onPostgres();
		$this->appConfig->method('getValueString')->willReturn("x' OR 1=1");

		$this->assertSame('dutch', $setting->configured());
	}

	public function testOffPostgresNothingIsActiveAndSavingIsRefused(): void {
		$this->db->method('getDatabasePlatform')->willReturn($this->createMock(MySQL80Platform::class));
		$this->db->expects($this->never())->method('executeQuery');
		$this->appConfig->method('getValueString')->willReturn('dutch');
		$setting = new TextSearchConfiguration(db: $this->db, appConfig: $this->appConfig);

		$this->assertNull($setting->active());
		$this->assertFalse($setting->stemming());
		$this->assertSame([], $setting->available());

		$this->expectException(InvalidArgumentException::class);
		$setting->save(name: 'dutch');
	}

	public function testTheExpressionsNameTheConfigurationAsALiteral(): void {
		$this->assertSame(
			"to_tsvector('dutch', COALESCE(CAST(t._name AS TEXT), '') || ' ' || COALESCE(CAST(t._description AS TEXT), '') || ' ' || COALESCE(CAST(t._summary AS TEXT), ''))",
			TextSearchConfiguration::metadataVector(config: 'dutch', prefix: 't.')
		);
		$this->assertSame("to_tsvector('dutch', COALESCE(CAST(\"titel\" AS TEXT), ''))", TextSearchConfiguration::vector(config: 'dutch', column: '"titel"'));
		$this->assertSame("plainto_tsquery('dutch', :term)", TextSearchConfiguration::query(config: 'dutch', quotedTerm: ':term'));
	}
}
