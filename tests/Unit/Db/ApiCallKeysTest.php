<?php

/**
 * ApiCallKeysTest — the factory builds the key it is given, field for field.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Db
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @spec openspec/specs/api-surface-governance/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db;

use OCA\OpenRegister\Db\ApiCallKey;
use OCA\OpenRegister\Db\ApiCallKeys;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Db\ApiCallKeys
 */
class ApiCallKeysTest extends TestCase {

	public function testTheKeyCarriesEachField(): void {
		$key = ApiCallKeys::of(principal: 'leverancier', route: '/api/objects/{id}', method: 'GET', apiVersion: '2');

		$this->assertEquals(new ApiCallKey('leverancier', '/api/objects/{id}', 'GET', '2'), $key);
		$this->assertSame(['leverancier', '/api/objects/{id}', 'GET', '2'], [$key->principal, $key->route, $key->method, $key->apiVersion]);
	}//end testTheKeyCarriesEachField()
}//end class
