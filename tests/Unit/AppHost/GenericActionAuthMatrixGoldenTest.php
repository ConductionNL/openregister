<?php

declare(strict_types=1);

/**
 * Golden of GenericActionAuthService::getMatrix(): how a stored
 * action-authorisation matrix is read back and cleaned.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\AppHost
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 */

namespace OCA\OpenRegister\Tests\Unit\AppHost;

use OCA\OpenRegister\AppHost\Service\GenericActionAuthService;
use OCP\IAppConfig;
use OCP\IGroupManager;
use PHPUnit\Framework\TestCase;

/**
 * Pins the stored-matrix read: what is kept, cleaned and dropped.
 */
class GenericActionAuthMatrixGoldenTest extends TestCase {

	/**
	 * Stored JSON → the matrix getMatrix() returns.
	 *
	 * @return array<string, array{0: string, 1: array}>
	 */
	public static function storedMatrices(): array {
		return [
			'not json' => ['{nope', []],
			'json but not an object' => ['"admin"', []],
			'json null' => ['null', []],
			'empty' => ['{}', []],
			'clean entries are kept' => ['{"read":["staff","admin"],"write":[]}', ['read' => ['staff', 'admin'], 'write' => []]],
			'groups are cleaned' => [
				'{"read":["staff","",5,null,"staff",["x"],"admin"]}',
				['read' => ['staff', 'admin']],
			],
			'an action whose groups are not a list is dropped' => ['{"read":"staff","write":["admin"]}', ['write' => ['admin']]],
			'a numeric action key is dropped' => ['{"7":["staff"],"read":["admin"]}', ['read' => ['admin']]],
			'a list instead of an object' => ['[["staff"]]', []],
		];
	}//end storedMatrices()

	/**
	 * @dataProvider storedMatrices
	 *
	 * @param string $stored   The stored JSON.
	 * @param array  $expected The matrix read back.
	 *
	 * @return void
	 */
	public function testTheStoredMatrixIsReadBackCleaned(string $stored, array $expected): void {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn($stored);

		$service = new GenericActionAuthService('myapp', $appConfig, $this->createMock(IGroupManager::class));

		$this->assertSame($expected, $service->getMatrix());
	}//end testTheStoredMatrixIsReadBackCleaned()
}//end class
