<?php

/**
 * Tests for BundleCrossingGuard.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Config
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/federated-config-sharing/specs/federated-config-sharing/spec.md
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Service\Config;

use OCA\OpenRegister\Service\Config\BundleCrossingGuard;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * @covers \OCA\OpenRegister\Service\Config\BundleCrossingGuard
 * @uses   \OCA\OpenRegister\Service\Rbac\CrossOrganisationMarkerChange
 */
class BundleCrossingGuardTest extends TestCase {

	private const MARKED = ['read' => [['group' => 'public', 'crossOrganisation' => true]]];

	/**
	 * A guard for a user; only `root` is an administrator, and only the acting uid is asked about.
	 *
	 * @param string|null $uid The signed-in user, or null for none.
	 *
	 * @return BundleCrossingGuard
	 */
	private function guardFor(?string $uid): BundleCrossingGuard {
		$user = null;
		if ($uid !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
		}

		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('isAdmin')->willReturnCallback(static fn (string $asked): bool => $asked === 'root' && $uid === 'root');

		return new BundleCrossingGuard($session, $groups);
	}//end guardFor()

	/**
	 * @return array<string, array{0: array<string, mixed>}>
	 */
	public static function markedBundles(): array {
		return [
			'an array'    => [['components' => ['schemas' => ['a' => ['title' => 'A'], 'b' => ['authorization' => self::MARKED]]]]],
			'a JSON text' => [['components' => ['schemas' => ['a' => ['authorization' => json_encode(self::MARKED)]]]]],
		];
	}//end markedBundles()

	#[DataProvider('markedBundles')]
	public function testAPlainUserCannotInstallAMarkedBundle(array $bundle): void {
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('crossOrganisation');
		$this->guardFor(uid: 'mallory')->assertAllowed(bundle: $bundle);
	}//end testAPlainUserCannotInstallAMarkedBundle()

	#[DataProvider('markedBundles')]
	public function testNobodyWithoutASessionCanInstallAMarkedBundle(array $bundle): void {
		$this->expectException(RuntimeException::class);
		$this->guardFor(uid: null)->assertAllowed(bundle: $bundle);
	}//end testNobodyWithoutASessionCanInstallAMarkedBundle()

	#[DataProvider('markedBundles')]
	public function testAnAdministratorMayInstallAMarkedBundle(array $bundle): void {
		$this->guardFor(uid: 'root')->assertAllowed(bundle: $bundle);
		$this->addToAssertionCount(1);
	}//end testAnAdministratorMayInstallAMarkedBundle()

	/**
	 * @return array<string, array{0: array<string, mixed>}>
	 */
	public static function plainBundles(): array {
		return [
			'no components'      => [[]],
			'no marker'          => [['components' => ['schemas' => ['a' => ['authorization' => ['read' => ['hr']]]]]]],
			'a false marker'     => [
				['components' => ['schemas' => ['a' => ['authorization' => ['read' => [['group' => 'x', 'crossOrganisation' => false]]]]]]],
			],
			'unreadable schemas' => [['components' => ['schemas' => 'x']]],
		];
	}//end plainBundles()

	#[DataProvider('plainBundles')]
	public function testAPlainUserMayInstallABundleWithoutAMarker(array $bundle): void {
		$this->guardFor(uid: 'mallory')->assertAllowed(bundle: $bundle);
		$this->addToAssertionCount(1);
	}//end testAPlainUserMayInstallABundleWithoutAMarker()
}//end class
