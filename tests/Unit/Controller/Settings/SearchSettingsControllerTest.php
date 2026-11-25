<?php

/**
 * GET and PUT /api/settings/search.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Controller\Settings
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller\Settings;

use Doctrine\DBAL\Platforms\MySQL80Platform;
use Doctrine\DBAL\Platforms\PostgreSQL120Platform;
use OCA\OpenRegister\Controller\Settings\SearchSettingsController;
use OCA\OpenRegister\Service\Search\TextSearchConfiguration;
use OCP\DB\IResult;
use OCP\IAppConfig;
use OCP\IDBConnection;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Controller\Settings\SearchSettingsController
 * @uses \OCA\OpenRegister\Service\Search\TextSearchConfiguration
 *
 * @spec openspec/changes/search-dutch-language-quality/specs/zoeken-filteren/spec.md
 */
final class SearchSettingsControllerTest extends TestCase {

	private IAppConfig&MockObject $appConfig;

	private function controllerOn(string $platformClass): SearchSettingsController {
		$db = $this->createMock(IDBConnection::class);
		$db->method('getDatabasePlatform')->willReturn($this->createMock($platformClass));
		$result = $this->createMock(IResult::class);
		$result->method('fetchAll')->willReturn(['dutch', 'simple']);
		$db->method('executeQuery')->willReturn($result);
		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->appConfig->method('getValueString')->willReturn('dutch');

		return new SearchSettingsController(
			request: $this->createMock(IRequest::class),
			textSearch: new TextSearchConfiguration(db: $db, appConfig: $this->appConfig)
		);
	}

	public function testShowOnPostgresReportsTheConfigurationAndWhatMayBeChosen(): void {
		$response = $this->controllerOn(platformClass: PostgreSQL120Platform::class)->show();

		$this->assertSame(200, $response->getStatus());
		$this->assertSame(['textSearchConfig' => 'dutch', 'stemming' => 'dutch', 'available' => ['dutch', 'simple']], $response->getData());
	}

	public function testShowOnMysqlReportsNoStemming(): void {
		$response = $this->controllerOn(platformClass: MySQL80Platform::class)->show();

		$this->assertSame(['textSearchConfig' => 'dutch', 'stemming' => false, 'available' => []], $response->getData());
	}

	public function testAnUnknownConfigurationIsRefusedWith400(): void {
		$controller = $this->controllerOn(platformClass: PostgreSQL120Platform::class);
		$this->appConfig->expects($this->never())->method('setValueString');

		$response = $controller->update(textSearchConfig: 'klingon');

		$this->assertSame(400, $response->getStatus());
		$this->assertStringContainsString('Unknown text search configuration', $response->getData()['error']);
	}

	public function testAKnownConfigurationIsSaved(): void {
		$controller = $this->controllerOn(platformClass: PostgreSQL120Platform::class);
		$this->appConfig->expects($this->once())->method('setValueString')->with('openregister', 'search.textSearchConfig', 'simple');

		$this->assertSame(200, $controller->update(textSearchConfig: 'simple')->getStatus());
	}
}
