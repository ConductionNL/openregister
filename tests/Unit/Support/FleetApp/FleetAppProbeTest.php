<?php

/**
 * FleetAppProbe: which installed app id answers for a canonical fleet app,
 * whether it is enabled, and which namespace a fleet class loads from.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Support\FleetApp
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Support\FleetApp;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Support\FleetApp\FleetAppProbe;
use OCP\App\IAppManager;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use stdClass;

/**
 * @covers \OCA\OpenRegister\Support\FleetApp\FleetAppProbe
 */
class FleetAppProbeTest extends TestCase {

	/**
	 * An app manager reporting exactly the given ids as installed.
	 *
	 * @param array<int, string> $installed The installed app ids.
	 *
	 * @return IAppManager
	 */
	private function managerWith(array $installed): IAppManager {
		$manager = $this->createMock(IAppManager::class);
		$manager->method('isInstalled')->willReturnCallback(static fn (string $id): bool => in_array($id, $installed, true));
		return $manager;
	}

	public function testResolvePrefersTheNewIdOverTheLegacyOne(): void {
		$this->assertSame('integriq', FleetAppProbe::resolve($this->managerWith(['openconnector', 'integriq']), 'integriq'));
	}

	public function testResolveFallsBackToTheLegacyId(): void {
		$this->assertSame('openconnector', FleetAppProbe::resolve($this->managerWith(['openconnector']), 'integriq'));
	}

	public function testResolveReturnsNullWhenNoCandidateIsInstalled(): void {
		$this->assertNull(FleetAppProbe::resolve($this->managerWith(['decidesk']), 'integriq'));
	}

	public function testAnUncataloguedAppResolvesUnderItsOwnId(): void {
		$this->assertSame('mydash', FleetAppProbe::resolve($this->managerWith(['mydash']), 'mydash'));
	}

	public function testAnInstallCheckThatThrowsCountsAsNotInstalled(): void {
		$manager = $this->createMock(IAppManager::class);
		$manager->method('isInstalled')->willReturnCallback(static function (string $id): bool {
			if ($id === 'dossiq') {
				throw new RuntimeException('app store down');
			}

			return $id === 'procest';
		});

		$this->assertSame('procest', FleetAppProbe::resolve($manager, 'dossiq'));
	}

	public function testEnabledAsksForTheResolvedId(): void {
		$manager = $this->managerWith(['decidesk']);
		$manager->expects($this->once())->method('isEnabledForUser')->with('decidesk')->willReturn(true);

		$this->assertTrue(FleetAppProbe::isEnabledForUser($manager, 'decidiq'));
	}

	public function testDisabledAppIsNotEnabled(): void {
		$manager = $this->managerWith(['decidiq']);
		$manager->method('isEnabledForUser')->willReturn(false);

		$this->assertFalse(FleetAppProbe::isEnabledForUser($manager, 'decidiq'));
	}

	public function testANotInstalledAppIsNotEnabledAndIsNeverAsked(): void {
		$manager = $this->managerWith([]);
		$manager->expects($this->never())->method('isEnabledForUser');

		$this->assertFalse(FleetAppProbe::isEnabledForUser($manager, 'decidiq'));
	}

	public function testAnEnabledCheckThatThrowsIsNotEnabled(): void {
		$manager = $this->managerWith(['decidiq']);
		$manager->method('isEnabledForUser')->willThrowException(new RuntimeException('no user'));

		$this->assertFalse(FleetAppProbe::isEnabledForUser($manager, 'decidiq'));
	}

	public function testResolveClassFindsTheLegacyNamespaceWhenOnlyItLoads(): void {
		$legacy = 'OCA\\OpenConnector\\FleetAppProbeFixture\\Loadable';
		if (class_exists($legacy) === false) {
			class_alias(stdClass::class, $legacy);
		}

		$this->assertSame($legacy, FleetAppProbe::resolveClass('integriq', 'FleetAppProbeFixture\\Loadable'));
	}

	public function testResolveClassReturnsNullWhenNoNamespaceLoadsIt(): void {
		$this->assertNull(FleetAppProbe::resolveClass('integriq', 'FleetAppProbeFixture\\Missing'));
	}

	public function testResolveClassReturnsNullForAnAppWithoutNamespaces(): void {
		$this->assertNull(FleetAppProbe::resolveClass('decidiq', 'Anything'));
	}
}
