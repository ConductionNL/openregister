<?php

/**
 * The deleted-objects routes that write ask for the request token; the reads do not.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Controller
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
 * @spec openspec/specs/deletion-audit-trail/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller;

use OCA\OpenRegister\Controller\DeletedController;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Every deleted# route, judged by its verb.
 */
final class DeletedControllerCsrfTest extends TestCase {

	/**
	 * Every deleted# route: its method and its verb.
	 *
	 * @return array<string, array{0: string, 1: string}>
	 */
	public static function routes(): array {
		$routes = require __DIR__.'/../../../appinfo/routes.php';
		$cases  = [];
		foreach ($routes['routes'] as $route) {
			if (str_starts_with($route['name'], 'deleted#') === true) {
				$method = substr($route['name'], strlen('deleted#'));
				$cases[$route['name']] = [$method, $route['verb']];
			}
		}

		return $cases;
	}//end routes()

	/**
	 * Whether the method opts out of the CSRF check, by docblock or attribute.
	 *
	 * @param string $method The controller method.
	 *
	 * @return bool
	 */
	private static function skipsCsrf(string $method): bool {
		$reflection = new ReflectionMethod(DeletedController::class, $method);

		return str_contains((string) $reflection->getDocComment(), '@NoCSRFRequired')
			|| $reflection->getAttributes(NoCSRFRequired::class) !== [];
	}//end skipsCsrf()

	#[DataProvider('routes')]
	public function testOnlyTheReadsSkipTheCsrfCheck(string $method, string $verb): void {
		$this->assertSame($verb === 'GET', self::skipsCsrf($method), $method.' ('.$verb.')');
	}//end testOnlyTheReadsSkipTheCsrfCheck()

	public function testTheWritingRoutesAreAllCovered(): void {
		$writes = array_keys(array_filter(self::routes(), static fn (array $route): bool => $route[1] !== 'GET'));

		$this->assertSame(['deleted#restore', 'deleted#restoreMultiple', 'deleted#destroy', 'deleted#destroyMultiple'], $writes);
	}//end testTheWritingRoutesAreAllCovered()
}//end class
