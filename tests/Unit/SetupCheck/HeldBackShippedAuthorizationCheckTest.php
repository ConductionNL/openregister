<?php

/**
 * Tests for the setup check that warns about held-back shipped authorization.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\SetupCheck
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/local-changes-to-app-shipped-configuration/specs/schema-import/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\SetupCheck;

use OCA\OpenRegister\Service\RegisterDescriptorServiceInterface;
use OCA\OpenRegister\SetupCheck\HeldBackShippedAuthorizationCheck;
use OCP\IL10N;
use OCP\SetupCheck\SetupResult;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Pins the three outcomes: nothing held back, something held back, and an unreadable inventory.
 */
final class HeldBackShippedAuthorizationCheckTest extends TestCase {

	public function testNothingHeldBackIsASuccess(): void {
		$result = $this->check(rows: [$this->row(heldBack: null)])->run();

		$this->assertSame(SetupResult::SUCCESS, $result->getSeverity());
	}

	public function testAHeldBackBlockIsAWarningNamingIt(): void {
		$result = $this->check(rows: [$this->row(heldBack: null), $this->row(heldBack: 'dashboard: authorization')])->run();

		$this->assertSame(SetupResult::WARNING, $result->getSeverity());
		$this->assertStringContainsString('launchpad/launchpad (dashboard: authorization)', (string)$result->getDescription());
		$this->assertStringContainsString('--problems-only', (string)$result->getDescription());
	}

	public function testAnUnreadableInventoryIsReportedNotThrown(): void {
		$descriptors = $this->createMock(RegisterDescriptorServiceInterface::class);
		$descriptors->method('inventory')->willThrowException(new RuntimeException('boom'));

		$result = (new HeldBackShippedAuthorizationCheck(descriptors: $descriptors, l10n: $this->l10n(), logger: $this->createMock(LoggerInterface::class)))->run();

		$this->assertSame(SetupResult::INFO, $result->getSeverity());
		$this->assertStringContainsString('boom', (string)$result->getDescription());
	}

	public function testItIsASecurityCheck(): void {
		$check = $this->check(rows: []);

		$this->assertSame('security', $check->getCategory());
		$this->assertSame('OpenRegister shipped access rules', $check->getName());
	}

	/**
	 * The check over a fixed inventory.
	 *
	 * @param array<int, array<string, string|null>> $rows The inventory.
	 *
	 * @return HeldBackShippedAuthorizationCheck
	 */
	private function check(array $rows): HeldBackShippedAuthorizationCheck {
		$descriptors = $this->createMock(RegisterDescriptorServiceInterface::class);
		$descriptors->method('inventory')->willReturn($rows);

		return new HeldBackShippedAuthorizationCheck(descriptors: $descriptors, l10n: $this->l10n(), logger: $this->createMock(LoggerInterface::class));
	}

	/**
	 * One inventory row for the launchpad register.
	 *
	 * @param string|null $heldBack The held-back blocks, or null.
	 *
	 * @return array<string, string|null>
	 */
	private function row(?string $heldBack): array {
		return [
			'appId' => 'launchpad',
			'slug' => 'launchpad',
			'title' => 'LaunchPad',
			'state' => RegisterDescriptorServiceInterface::STATE_CURRENT,
			'installedVersion' => '1.0.1',
			'shippedVersion' => '1.0.1',
			'descriptor' => 'launchpad_register.json',
			'heldBack' => $heldBack,
		];
	}

	/**
	 * A translator that formats without translating.
	 *
	 * @return IL10N
	 */
	private function l10n(): IL10N {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(
			static fn (string $text, array $parameters = []): string => vsprintf($text, $parameters)
		);

		return $l10n;
	}
}
