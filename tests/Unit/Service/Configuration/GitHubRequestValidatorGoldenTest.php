<?php

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

/**
 * Golden test for GitHubRequestValidator.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Configuration
 * @author   Conduction Development Team <dev@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://www.OpenRegister.nl
 */

namespace OCA\OpenRegister\Tests\Unit\Service\Configuration;

use OCA\OpenRegister\Service\Configuration\GitHubRequestValidator;
use OCP\AppFramework\Http\JSONResponse;
use PHPUnit\Framework\TestCase;

/**
 * Pins every validator's answer (null, or the status and body of the refusal) for a table of inputs.
 *
 * Compared with fixtures/github-request-validator-golden.json. Set UPDATE_GOLDEN=1 to rewrite it.
 *
 * @covers \OCA\OpenRegister\Service\Configuration\GitHubRequestValidator
 */
class GitHubRequestValidatorGoldenTest extends TestCase {
	private const FIXTURE = __DIR__ . '/fixtures/github-request-validator-golden.json';

	public function testValidatorsMatchGolden(): void {
		$validator = new GitHubRequestValidator();
		$inputs = [
			'validateRepoFormat' => ['ConductionNL/openregister', 'a/b', 'a.b_c-d/e.f', '-a/b', 'a/-b', 'a', 'a/b/c', '', 'a /b', 'a/b '],
			'validatePerPage' => [-1, 0, 1, 50, 100, 101],
			'validateTitleLength' => ['', 'ab', 'abc', str_repeat('x', 200), str_repeat('x', 201), 'ééé'],
			'validateBodyLength' => ['', '123456789', '1234567890', 'ééééé'],
			'validateSpecRef' => [null, '', 'a', 'ab', 'a-b', '-ab', 'ab-', 'A-b', 'a_b', 'abc123', str_repeat('a', 80), str_repeat('a', 81), str_repeat('-', 81)],
			'validateSort' => ['reactions-+1', 'created', 'updated', 'comments', 'Created', '', 'stars'],
			'validateLabels' => [
				null,
				[],
				['bug'],
				['bug', 'feature-request', 'a_b', 'x1'],
				array_fill(0, 8, 'ok'),
				array_fill(0, 9, 'ok'),
				[str_repeat('a', 50)],
				[str_repeat('a', 51)],
				['Bug'],
				['1bug'],
				['ok', 'not ok'],
				[''],
				array_fill(0, 9, 'NOT OK'),
				[str_repeat('A', 51)],
			],
		];
		$actual = [];
		foreach ($inputs as $method => $values) {
			foreach ($values as $value) {
				$actual[$method][] = ['input' => $value, 'answer' => $this->answer($validator->$method($value))];
			}
		}

		$actual = json_decode(json_encode($actual), true);
		if (getenv('UPDATE_GOLDEN') === '1') {
			file_put_contents(self::FIXTURE, json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
			$this->addToAssertionCount(1);
			return;
		}

		$this->assertSame(json_decode((string)file_get_contents(self::FIXTURE), true), $actual);
	}//end testValidatorsMatchGolden()

	/**
	 * @return array<string, mixed>|null
	 */
	private function answer(?JSONResponse $response): ?array {
		if ($response === null) {
			return null;
		}

		return ['status' => $response->getStatus(), 'data' => $response->getData()];
	}//end answer()
}//end class
