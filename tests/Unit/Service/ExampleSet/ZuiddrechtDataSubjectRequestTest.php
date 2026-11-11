<?php

/**
 * The Zuiddrecht example set carries one data-subject request in the shared
 * data-subject-requests register, as LqCfPrivacyverzoek draws it: a parent's
 * erasure request for a former pupil, in progress, with its statutory term
 * still running.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\ExampleSet
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 *
 * @spec exclude example-set data integrity; no requirement covers the demo fragment
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\ExampleSet;

use PHPUnit\Framework\TestCase;

/**
 * Reads the shipped cast file from disk.
 */
class ZuiddrechtDataSubjectRequestTest extends TestCase {

	private const CAST_FILE = __DIR__.'/../../../../lib/Settings/example-sets/zuiddrecht.json';

	/**
	 * The erasure request is in the shared register, in progress, due later.
	 *
	 * @return void
	 */
	public function testTheErasureRequestIsInProgress(): void {
		$set     = json_decode((string) file_get_contents(self::CAST_FILE), true);
		$objects = array_column($set['objects'], null, 'key');
		$request = ($objects['dsar-erasure-former-pupil'] ?? null);

		$this->assertNotNull(actual: $request);
		$this->assertSame(expected: ['data-subject-requests', 'dataSubjectRequest'], actual: [$request['register'], $request['schema']]);
		$this->assertSame(expected: ['erasure', 'in-progress'], actual: [$request['data']['type'], $request['data']['status']]);
		$this->assertSame(expected: '{{cast:pieter.uid}}', actual: $request['data']['handler']);
		$this->assertStringStartsWith(prefix: '{{datetime:', string: $request['data']['dueAt']);
	}//end testTheErasureRequestIsInProgress()
}//end class
