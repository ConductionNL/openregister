<?php

/**
 * Unit tests for BackendState — the anonymisation backend state as the API reports it.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Anonymisation
 *
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Anonymisation;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Service\Anonymisation\BackendInfo;
use OCA\OpenRegister\Service\Anonymisation\BackendState;
use OCA\OpenRegister\Service\Anonymisation\ProbeResult;
use PHPUnit\Framework\TestCase;

final class BackendStateTest extends TestCase {

	public function testBackendInfoTakesAvailabilityAndTimingFromTheProbe(): void {
		$info = new BackendInfo('presidio', true, new ProbeResult(true, 42, null, '2026-11-12T10:00:00Z'));

		$this->assertTrue($info->available);
		$this->assertSame(42, $info->latencyMs);
		$this->assertSame('2026-11-12T10:00:00Z', $info->lastProbedAt);
		$this->assertSame(
			['name' => 'presidio', 'available' => true, 'configured' => true, 'lastProbedAt' => '2026-11-12T10:00:00Z', 'latencyMs' => 42],
			$info->jsonSerialize()
		);
	}

	public function testAnUnreachableProbeMakesTheBackendUnavailable(): void {
		$info = new BackendInfo('llm', false, new ProbeResult(false, null, ProbeResult::ERROR_TIMEOUT, '2026-11-12T10:00:00Z'));

		$this->assertFalse($info->available);
		$this->assertNull($info->latencyMs);
		$this->assertFalse($info->jsonSerialize()['configured']);
	}

	public function testAProbeResultSerialisesItsOutcome(): void {
		$this->assertSame(
			['reachable' => false, 'latencyMs' => null, 'error' => 'http_5xx', 'probedAt' => 't'],
			(new ProbeResult(false, null, ProbeResult::ERROR_HTTP_5XX, 't'))->jsonSerialize()
		);
	}

	public function testEveryProbeErrorCodeIsListedOnce(): void {
		$this->assertCount(9, ProbeResult::ERRORS);
		$this->assertSame(ProbeResult::ERRORS, array_values(array_unique(ProbeResult::ERRORS)));
	}

	public function testTheStateSerialisesEachBackendByName(): void {
		$state = new BackendState(
			true,
			'presidio',
			'regex',
			[
				'presidio' => new BackendInfo('presidio', true, new ProbeResult(false, null, 'timeout', 't1')),
				'regex' => new BackendInfo('regex', true, new ProbeResult(true, 1, null, 't2')),
			]
		);

		$this->assertSame(
			[
				'entityRecognitionEnabled' => true,
				'activeMethod' => 'presidio',
				'effectiveMethod' => 'regex',
				'backends' => [
					'presidio' => ['name' => 'presidio', 'available' => false, 'configured' => true, 'lastProbedAt' => 't1', 'latencyMs' => null],
					'regex' => ['name' => 'regex', 'available' => true, 'configured' => true, 'lastProbedAt' => 't2', 'latencyMs' => 1],
				],
			],
			$state->jsonSerialize()
		);
	}

	public function testAStateWithoutBackendsSerialisesAnEmptyMap(): void {
		$this->assertSame([], (new BackendState(false, 'none', 'none', []))->jsonSerialize()['backends']);
	}
}
