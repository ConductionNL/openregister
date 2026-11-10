<?php
/**
 * One route answers `POST /api/files/{id}/extract`.
 *
 * The router answers a request with the first route whose verb and path match,
 * so a second route on the same verb and path is dead.
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
 * Structural: the file extract route.
 *
 * @coversNothing
 */
class FileExtractRouteTest extends TestCase {

	/**
	 * Only fileExtraction#extract is declared for the path.
	 *
	 * @return void
	 */
	public function testOneRouteAnswersFileExtract(): void {
		$declared = require dirname(__DIR__, 3) . '/appinfo/routes.php';
		$names    = [];
		foreach (($declared['routes'] ?? []) as $route) {
			$path = preg_replace(pattern: '/\{[^}]+\}/', replacement: '{}', subject: (string) ($route['url'] ?? ''));
			if (strtoupper(string: (string) ($route['verb'] ?? 'GET')) === 'POST' && $path === '/api/files/{}/extract') {
				$names[] = $route['name'];
			}
		}

		$this->assertSame(expected: ['fileExtraction#extract'], actual: $names);
	}//end testOneRouteAnswersFileExtract()
}//end class
