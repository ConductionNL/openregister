<?php

/**
 * Every object file route runs the object guard.
 *
 * An object's files sit in the openregister account's home, so a file route
 * that forgets the guard fails OPEN. This walks appinfo/routes.php and fails
 * on a new `files#` route over an object that does not call the guard.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/object-files-follow-object-access/specs/file-actions/spec.md#requirement-reading-an-objects-files-follows-the-objects-read-rule-req-ofoa-002
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller;

use OCA\OpenRegister\Controller\FilesController;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Route walk over the files controller.
 */
class FilesControllerRouteGuardTest extends TestCase {

	/**
	 * The calls that count as the object guard.
	 *
	 * @var array<int, string>
	 */
	private const GUARDS = ['$this->ensureObjectAccess(', '$this->openOfficeSession('];

	public function testEveryObjectFileRouteRunsTheGuard(): void {
		$routes = require __DIR__ . '/../../../appinfo/routes.php';
		$checked = 0;
		$missing = [];

		foreach (($routes['routes'] ?? []) as $route) {
			$name = (string)($route['name'] ?? '');
			$url = (string)($route['url'] ?? '');
			if (str_starts_with($name, 'files#') === false || str_contains($url, '{id}') === false) {
				continue;
			}

			$method = substr($name, strlen('files#'));
			$reflection = new ReflectionMethod(FilesController::class, $method);
			$lines = file((string)$reflection->getFileName());
			$body = implode('', array_slice($lines, $reflection->getStartLine() - 1, $reflection->getEndLine() - $reflection->getStartLine() + 1));

			$guarded = false;
			foreach (self::GUARDS as $guard) {
				if (str_contains($body, $guard) === true) {
					$guarded = true;
				}
			}

			if ($guarded === false) {
				$missing[] = $name;
			}

			$checked++;
		}

		$this->assertGreaterThan(15, $checked, 'the walk found too few object file routes to be the real list');
		$this->assertSame([], $missing, 'object file routes without the guard');
	}//end testEveryObjectFileRouteRunsTheGuard()
}//end class
