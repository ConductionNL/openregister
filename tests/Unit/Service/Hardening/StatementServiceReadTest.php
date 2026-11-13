<?php

/**
 * Unit tests for StatementService: a statement or acceptance that cannot be read, or reads incomplete, counts as none.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Hardening
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Hardening;

use OCA\OpenRegister\Service\Hardening\HardeningAuditWriter;
use OCA\OpenRegister\Service\Hardening\PlatformConfiguration;
use OCA\OpenRegister\Service\Hardening\StatementService;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use OCP\IConfig;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * @covers \OCA\OpenRegister\Service\Hardening\StatementService
 * @uses \OCA\OpenRegister\Service\Hardening\PlatformConfiguration
 */
class StatementServiceReadTest extends TestCase {

	/**
	 * A service over these stores; a Throwable makes the read throw.
	 *
	 * @param string|\Throwable $statement What the app configuration holds.
	 * @param string|\Throwable $acceptance What the user preference holds.
	 *
	 * @return StatementService
	 */
	private function service(string|\Throwable $statement, string|\Throwable $acceptance = ''): StatementService {
		$appConfig = $this->createMock(IAppConfig::class);
		if ($statement instanceof \Throwable) {
			$appConfig->method('getValueString')->willThrowException($statement);
		} else {
			$appConfig->method('getValueString')->with('openregister', StatementService::STATEMENT_KEY, '')->willReturn($statement);
		}

		$config = $this->createMock(IConfig::class);
		if ($acceptance instanceof \Throwable) {
			$config->method('getUserValue')->willThrowException($acceptance);
		} else {
			$config->method('getUserValue')->with('medewerker', 'openregister', StatementService::ACCEPTANCE_KEY, '')->willReturn($acceptance);
		}

		return new StatementService(
			platform: new PlatformConfiguration(config: $config, appConfig: $appConfig, appManager: $this->createMock(IAppManager::class)),
			audit: $this->createMock(HardeningAuditWriter::class)
		);
	}//end service()

	public function testAnUnreadableStatementIsNone(): void {
		$this->assertNull($this->service(new RuntimeException('config table locked'))->published());
	}

	public function testAStatementWithoutAVersionOrBodyIsNone(): void {
		$this->assertNull($this->service('{"version":"","body":"Tekst"}')->published());
		$this->assertNull($this->service('{"version":"1","body":""}')->published());
		$this->assertNull($this->service('not json')->published());
		$this->assertNull($this->service('   ')->published());
	}

	public function testACompleteStatementIsReturnedWithItsDefaults(): void {
		$published = $this->service('{"version":"2","body":"Wij beveiligen"}')->published();

		$this->assertSame('2', $published['version']);
		$this->assertSame('Wij beveiligen', $published['body']);
		$this->assertSame('', $published['title']);
	}

	public function testABlankUserHasAcceptedNothingAndIsNotLookedUp(): void {
		$config = $this->createMock(IConfig::class);
		$config->expects($this->never())->method('getUserValue');
		$service = new StatementService(
			platform: new PlatformConfiguration(config: $config, appConfig: $this->createMock(IAppConfig::class), appManager: $this->createMock(IAppManager::class)),
			audit: $this->createMock(HardeningAuditWriter::class)
		);

		$this->assertNull($service->acceptanceOf(userId: '  '));
	}

	public function testAnUnreadableOrVersionlessAcceptanceIsNone(): void {
		$this->assertNull($this->service('', new RuntimeException('preferences down'))->acceptanceOf(userId: 'medewerker'));
		$this->assertNull($this->service('', '{"acceptedAt":"2026-11-01T10:00:00+00:00"}')->acceptanceOf(userId: 'medewerker'));
		$this->assertNull($this->service('', 'garbage')->acceptanceOf(userId: 'medewerker'));
	}

	public function testAnAcceptanceIsReadWithItsMoment(): void {
		$this->assertSame(
			['version' => '2', 'acceptedAt' => '2026-11-01T10:00:00+00:00'],
			$this->service('', '{"version":"2","acceptedAt":"2026-11-01T10:00:00+00:00"}')->acceptanceOf(userId: 'medewerker')
		);
	}
}
