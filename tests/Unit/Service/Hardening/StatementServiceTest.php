<?php

/**
 * Unit tests for StatementService.
 *
 * The claim is that an acceptance names the version it was given for. So the
 * tests here move the version under the user and check what happens: a new
 * version asks again, an acceptance of the old one is refused, and a withdrawn
 * statement asks nothing.
 *
 * The stores are simple arrays rather than mocks with expectations, because the
 * behaviour under test is what the NEXT read sees. A mock that records a write
 * proves the call was made; it cannot prove the user is asked again.
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

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.
// phpcs:disable Squiz.Commenting.VariableComment.Missing -- typed mock fixtures; the declaration IS the description.

use InvalidArgumentException;
use OCA\OpenRegister\Service\Hardening\HardeningAuditWriter;
use OCA\OpenRegister\Service\Hardening\StatementService;
use OCP\IAppConfig;
use OCP\IConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Service\Hardening\StatementService
 */
class StatementServiceTest extends TestCase {

	private HardeningAuditWriter&MockObject $audit;
	private StatementService $service;

	/** @var array<string, string> */
	private array $appStore = [];

	/** @var array<string, string> */
	private array $userStore = [];

	protected function setUp(): void {
		parent::setUp();

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			fn (string $app, string $key, string $default = ''): string => ($this->appStore[$key] ?? $default)
		);
		$appConfig->method('setValueString')->willReturnCallback(
			function (string $app, string $key, string $value): bool {
				$this->appStore[$key] = $value;
				return true;
			}
		);

		$config = $this->createMock(IConfig::class);
		$config->method('getUserValue')->willReturnCallback(
			fn (string $uid, string $app, string $key, $default = ''): string => ($this->userStore[$uid . $key] ?? (string)$default)
		);
		$config->method('setUserValue')->willReturnCallback(
			function (string $uid, string $app, string $key, string $value): void {
				$this->userStore[$uid . $key] = $value;
			}
		);

		$this->audit = $this->createMock(HardeningAuditWriter::class);

		$this->service = new StatementService(
			appConfig: $appConfig,
			config: $config,
			audit: $this->audit
		);
	}

	// ---- Task 1.1/1.2: published, then accepted, then recorded. ------------

	public function testNothingIsAskedBeforeAStatementIsPublished(): void {
		$this->assertNull($this->service->published());
		$this->assertFalse($this->service->needsAcceptance(userId: 'medewerker'));
	}

	public function testAPublishedStatementIsAskedOfAUserWhoAcceptedNothing(): void {
		$this->service->publish(version: '2', body: 'How we process your data', title: 'Verwerking', userId: 'admin');

		$this->assertTrue($this->service->needsAcceptance(userId: 'medewerker'));
	}

	public function testAnAcceptanceRecordsTheUserTheVersionAndTheTime(): void {
		$this->service->publish(version: '2', body: 'text', userId: 'admin');

		$acceptance = $this->service->accept(userId: 'medewerker', version: '2');

		$this->assertSame('2', $acceptance['version']);
		$this->assertNotSame('', $acceptance['acceptedAt']);
		$this->assertSame('2', $this->service->acceptanceOf(userId: 'medewerker')['version']);
		$this->assertFalse($this->service->needsAcceptance(userId: 'medewerker'));
	}

	// ---- Task 1.3: a new version asks everybody again. ---------------------

	public function testANewVersionAsksAUserWhoAcceptedTheOldOne(): void {
		$this->service->publish(version: '2', body: 'text', userId: 'admin');
		$this->service->accept(userId: 'medewerker', version: '2');

		$this->service->publish(version: '3', body: 'text, revised', userId: 'admin');

		$this->assertTrue(
			$this->service->needsAcceptance(userId: 'medewerker'),
			'a user who accepted version 2 must be asked about version 3'
		);
	}

	/**
	 * The version is checked against the one in force rather than trusted, so a
	 * client posting the old number cannot close the gate on a text the user
	 * was never shown.
	 */
	public function testAcceptingAVersionThatIsNoLongerInForceIsRefusedAndAudited(): void {
		$this->service->publish(version: '2', body: 'text', userId: 'admin');
		$this->service->publish(version: '3', body: 'text, revised', userId: 'admin');

		$this->audit->expects($this->atLeastOnce())->method('record');
		$this->expectException(InvalidArgumentException::class);

		$this->service->accept(userId: 'medewerker', version: '2');
	}

	public function testAWithdrawnStatementAsksNothing(): void {
		$this->service->publish(version: '2', body: 'text', userId: 'admin');
		$this->service->withdraw();

		$this->assertNull($this->service->published());
		$this->assertFalse($this->service->needsAcceptance(userId: 'medewerker'));
	}

	// ---- Fail closed on what is not a statement. ---------------------------

	public function testAStatementWithoutAVersionOrABodyIsNotPublished(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->service->publish(version: '', body: 'text', userId: 'admin');
	}

	public function testAStoredStatementThatCannotBeDecodedReadsAsNoStatement(): void {
		$this->appStore[StatementService::STATEMENT_KEY] = '{ not json';

		$this->assertNull($this->service->published());
	}

	public function testAnAnonymousCallerIsNeverAskedAndCannotAccept(): void {
		$this->service->publish(version: '2', body: 'text', userId: 'admin');

		$this->assertFalse($this->service->needsAcceptance(userId: ''));

		$this->expectException(InvalidArgumentException::class);
		$this->service->accept(userId: '', version: '2');
	}
}//end class
