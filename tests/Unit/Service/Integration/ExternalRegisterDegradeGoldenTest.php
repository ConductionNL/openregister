<?php

/**
 * Golden record of ExternalRegisterDegrade.
 *
 * evaluate(), meansTheRegisterHasNothing() and cacheSecondsFor() over every
 * combination of key, appInstalled, configured, answered, refused and record
 * values (strict trues, truthy non-trues, missing keys, a blank key, empty
 * and non-empty records, a non-array record). The recording lives in
 * fixtures/external-register-degrade-golden.json; set
 * EXTERNAL_REGISTER_DEGRADE_GOLDEN_RECORD=1 to write it.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Integration
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec exclude Pins the external-register degrade states.
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Integration;

use OCA\OpenRegister\Service\Integration\ExternalRegisterDegrade;
use PHPUnit\Framework\TestCase;

/**
 * The external-register degrade states, recorded.
 *
 * @covers \OCA\OpenRegister\Service\Integration\ExternalRegisterDegrade
 */
class ExternalRegisterDegradeGoldenTest extends TestCase {

	private const FIXTURE = __DIR__.'/fixtures/external-register-degrade-golden.json';

	/**
	 * Marks a key as absent from the lookup.
	 */
	private const ABSENT = '(absent)';

	/**
	 * The values each lookup key takes.
	 *
	 * @return array<string, list<mixed>> Key => values.
	 */
	private static function axes(): array {
		$flag = [true, 1, self::ABSENT];
		return [
			'key' => ['123', '  ', self::ABSENT],
			'appInstalled' => $flag,
			'configured' => $flag,
			'answered' => $flag,
			'refused' => $flag,
			'record' => [['name' => 'A'], [], 'text'],
		];
	}//end axes()

	/**
	 * Every lookup the axes span.
	 *
	 * @return list<array<string, mixed>> The lookups.
	 */
	private static function lookups(): array {
		$lookups = [[]];
		foreach (self::axes() as $key => $values) {
			$next = [];
			foreach ($lookups as $lookup) {
				foreach ($values as $value) {
					$extended = $lookup;
					if ($value !== self::ABSENT) {
						$extended[$key] = $value;
					}
					$next[] = $extended;
				}
			}
			$lookups = $next;
		}
		return $lookups;
	}//end lookups()

	/**
	 * Run every lookup and record it.
	 *
	 * @return array<string, mixed> The recording.
	 */
	private function recordAll(): array {
		$degrade = new ExternalRegisterDegrade();
		$recording = [];
		foreach (self::lookups() as $lookup) {
			$evaluated = $degrade->evaluate($lookup);
			$recording[json_encode($lookup)] = [
				$evaluated,
				$degrade->meansTheRegisterHasNothing($evaluated['state']),
				$degrade->cacheSecondsFor($evaluated['state']),
			];
		}
		foreach ([...ExternalRegisterDegrade::STATES, 'unknown', ''] as $state) {
			$recording['state '.$state] = [$degrade->meansTheRegisterHasNothing($state), $degrade->cacheSecondsFor($state)];
		}
		return $recording;
	}//end recordAll()

	/**
	 * Every lookup matches the recording.
	 *
	 * @return void
	 */
	public function testMatchesTheGoldenRecord(): void {
		$recording = $this->recordAll();
		if (getenv('EXTERNAL_REGISTER_DEGRADE_GOLDEN_RECORD') === '1') {
			file_put_contents(self::FIXTURE, json_encode($recording, JSON_UNESCAPED_SLASHES)."\n");
		}
		$expected = json_decode((string)file_get_contents(self::FIXTURE), true);
		$this->assertSame($expected, json_decode((string)json_encode($recording), true));
	}//end testMatchesTheGoldenRecord()
}//end class
