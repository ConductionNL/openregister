<?php

/**
 * The attach-targets route is declared where the router reaches it.
 *
 * `fileExtraction#show` takes `/api/files/{id}` with no requirements, so a
 * literal `/api/files/attach-targets` declared after it is answered by the
 * extraction controller and the picker never reaches AttachTargetsController.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Architecture
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * Structural: the route's place in the table.
 *
 * @coversNothing
 */
class AttachTargetsRouteIsReachableTest extends TestCase {

	/**
	 * The index of a named route with a url, or null.
	 *
	 * @param string $name The route name.
	 * @param string $url  The url it must carry.
	 *
	 * @return int|null The index.
	 */
	private function indexOf(string $name, string $url): ?int {
		$declared = require dirname(__DIR__, 3) . '/appinfo/routes.php';
		foreach (($declared['routes'] ?? []) as $index => $route) {
			if (($route['name'] ?? '') === $name && ($route['url'] ?? '') === $url) {
				return $index;
			}
		}

		return null;
	}//end indexOf()

	/**
	 * It is declared before the generic `/api/files/{id}` route.
	 *
	 * @return void
	 */
	public function testItIsDeclaredBeforeTheRouteThatWouldSwallowIt(): void {
		$targets = $this->indexOf('attachTargets#index', '/api/files/attach-targets');
		$show    = $this->indexOf('fileExtraction#show', '/api/files/{id}');

		$this->assertIsInt($targets);
		$this->assertIsInt($show);
		$this->assertLessThan(
			$show,
			$targets,
			'Declared after fileExtraction#show, the picker request is answered by /api/files/{id}.'
		);
	}//end testItIsDeclaredBeforeTheRouteThatWouldSwallowIt()
}//end class
