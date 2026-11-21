<?php

/**
 * FlowErrorPolicy: the one reading of a step's onError policy.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Flow
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Flow;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Service\Flow\FlowEngine;
use OCA\OpenRegister\Service\Flow\FlowErrorPolicy;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Service\Flow\FlowErrorPolicy
 */
class FlowErrorPolicyTest extends TestCase {

	/**
	 * @return array<string, array{0: array<string, mixed>|null, 1: string, 2: bool}>
	 */
	public static function steps(): array {
		return [
			'no policy is stop' => [['id' => 'a'], 'stop', false],
			'stop' => [['onError' => 'stop'], 'stop', false],
			'continue' => [['onError' => 'continue'], 'continue', true],
			'dead letter' => [['onError' => 'dead_letter'], 'dead_letter', false],
			'a typo fails safe as stop' => [['onError' => 'carry-on-regardless'], 'stop', false],
			'a non-string fails safe as stop' => [['onError' => ['continue']], 'stop', false],
			'an unknown step is stop' => [null, 'stop', false],
		];
	}

	/**
	 * @dataProvider steps
	 */
	public function testThePolicyOfAStep(?array $step, string $policy, bool $continues): void {
		$this->assertSame($policy, FlowErrorPolicy::for($step));
		$this->assertSame($continues, FlowErrorPolicy::continues($step));
	}

	public function testTheDeclaredPolicyKeepsATypoForTheLog(): void {
		$this->assertSame('carry-on-regardless', FlowErrorPolicy::declared(['onError' => 'carry-on-regardless']));
		$this->assertSame('stop', FlowErrorPolicy::declared([]));
		$this->assertSame('stop', FlowErrorPolicy::declared(['onError' => ['x']]));
	}

	public function testTheEngineConstantsAreThePolicies(): void {
		$this->assertSame(FlowErrorPolicy::STOP, FlowEngine::ON_ERROR_STOP);
		$this->assertSame(FlowErrorPolicy::CONTINUE, FlowEngine::ON_ERROR_CONTINUE);
		$this->assertSame(FlowErrorPolicy::DEAD_LETTER, FlowEngine::ON_ERROR_DEAD_LETTER);
	}
}
