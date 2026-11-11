<?php

/**
 * Golden test: the session and group manager reach the five session-holding controller bundles.
 *
 * AnonymisationBackend, AuditQuery, Bulk, ImportPreview and SearchTrail gate
 * their endpoints on the session user and the admin group. Pinned before their
 * bundles take the session through one aggregate: whatever the bundle's
 * constructor looks like, the session and group manager a controller is built
 * with are exactly the ones its bundle hands out, so every allowed and refused
 * outcome of those gates is decided on the same objects as before.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/specs/auth-system/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller;

use OCA\OpenRegister\Tests\Unit\Controller\Support\AnonymisationBackendControllerFactory;
use OCA\OpenRegister\Tests\Unit\Controller\Support\AuditQueryControllerFactory;
use OCA\OpenRegister\Tests\Unit\Controller\Support\BulkControllerFactory;
use OCA\OpenRegister\Tests\Unit\Controller\Support\ImportPreviewControllerFactory;
use OCA\OpenRegister\Tests\Unit\Controller\Support\SearchTrailControllerFactory;
use OCP\IGroupManager;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionNamedType;

/**
 * Pins which session and group manager each session-holding bundle exposes.
 */
final class SessionBundlesWiringGoldenTest extends TestCase {

	/**
	 * Each factory, and how to reach the bundle that holds the session from its arguments.
	 *
	 * @return array<string, array{0: class-string, 1: string|null}>
	 */
	public static function factoryProvider(): array {
		return [
			'anonymisation backend' => [AnonymisationBackendControllerFactory::class, null],
			'audit query'           => [AuditQueryControllerFactory::class, null],
			'bulk'                  => [BulkControllerFactory::class, 'platform'],
			'import preview'        => [ImportPreviewControllerFactory::class, 'platform'],
			'search trail'          => [SearchTrailControllerFactory::class, null],
		];
	}

	/**
	 * The bundle hands out exactly the session and group manager the controller was built with.
	 *
	 * @param class-string $factory The test factory of the controller.
	 * @param string|null  $member  The bundle member holding the session, or null for the bundle itself.
	 *
	 * @dataProvider factoryProvider
	 *
	 * @return void
	 */
	public function testTheBundleHoldsTheGivenSession(string $factory, ?string $member): void {
		$session = $this->createMock(IUserSession::class);
		$groups  = $this->createMock(IGroupManager::class);

		$args   = $factory::args(...$this->argumentsFor(factory: $factory, given: [IUserSession::class => $session, IGroupManager::class => $groups]));
		$holder = $this->servicesBundleIn(args: $args);
		if ($member !== null) {
			$holder = $holder->{$member};
		}

		$this->assertSame($session, $holder->userSession);
		$this->assertSame($groups, $holder->groupManager);
		// Mutation check: a different session is told apart.
		$this->assertNotSame($this->createMock(IUserSession::class), $holder->userSession);
	}

	/**
	 * The controller's services bundle among its constructor arguments, picked by type, not by position.
	 *
	 * @param array<int, mixed> $args The constructor arguments.
	 *
	 * @return object The bundle.
	 */
	private function servicesBundleIn(array $args): object {
		foreach ($args as $arg) {
			if (is_object($arg) === true && str_ends_with($arg::class, 'ControllerServices') === true) {
				return $arg;
			}
		}

		$this->fail('No *ControllerServices bundle among the constructor arguments');
	}

	/**
	 * Named arguments for a factory: the given objects by type, a mock for every other object, the app id for scalars.
	 *
	 * @param class-string          $factory The factory.
	 * @param array<string, object> $given   Objects to pass, by type.
	 *
	 * @return array<string, mixed> The arguments by name.
	 */
	private function argumentsFor(string $factory, array $given): array {
		$args = [];
		foreach ((new ReflectionMethod($factory, 'args'))->getParameters() as $param) {
			$type = $param->getType();
			if ($param->isDefaultValueAvailable() === true) {
				continue;
			}

			if ($type instanceof ReflectionNamedType === false || $type->isBuiltin() === true) {
				$args[$param->getName()] = 'openregister';
				continue;
			}

			$args[$param->getName()] = ($given[$type->getName()] ?? $this->createMock($type->getName()));
		}

		return $args;
	}
}
