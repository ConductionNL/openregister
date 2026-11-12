<?php

/**
 * Golden test for AppConfigurationRecord::applySource().
 *
 * Records the source type, source url and GitHub location a Configuration
 * holds, and which of its fields were set, after applying `x-openregister`
 * blocks with flat and nested GitHub keys, null values, non-array blocks and
 * a non-array `github` entry. Re-record with UPDATE_GOLDEN=1.
 *
 * @category  Test
 * @package   OCA\OpenRegister\Tests\Unit\Service\Configuration
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://www.OpenRegister.app
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Service\Configuration;

use OCA\OpenRegister\Db\Configuration;
use OCA\OpenRegister\Service\Configuration\AppConfigurationRecord;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * @covers \OCA\OpenRegister\Service\Configuration\AppConfigurationRecord
 * @uses   \OCA\OpenRegister\Db\Configuration
 */
class ImportHandlerConfigurationSourceGoldenTest extends TestCase {
	private const FIXTURE = __DIR__ . '/fixtures/import-configuration-source-golden.json';

	private static function blocks(): array {
		$flat = [
			'sourceType' => 'github',
			'sourceUrl' => 'https://example.org/a.json',
			'githubRepo' => 'org/flat',
			'githubBranch' => 'main',
			'githubPath' => 'flat.json',
		];
		return [
			'null' => null,
			'empty' => [],
			'string' => 'x-openregister',
			'integer' => 42,
			'flat' => $flat,
			'flat partial' => ['githubRepo' => 'org/only', 'sourceUrl' => null],
			'flat empty strings' => ['sourceType' => '', 'githubBranch' => '', 'githubPath' => ''],
			'nested' => ['github' => ['repo' => 'org/nested', 'branch' => 'dev', 'path' => 'nested.json']],
			'nested wins over flat' => $flat + ['github' => ['repo' => 'org/nested']],
			'nested empty array' => $flat + ['github' => []],
			'nested null' => $flat + ['github' => null],
			'nested string' => $flat + ['github' => 'org/string'],
			'nested with flat names' => ['github' => ['githubRepo' => 'org/x', 'path' => 'p.json']],
			'list' => ['github', 'sourceType'],
		];
	}//end blocks()

	public function testConfigurationSourceMatchesGolden(): void {
		$handler = (new ReflectionClass(AppConfigurationRecord::class))->newInstanceWithoutConstructor();
		$method = new ReflectionMethod(AppConfigurationRecord::class, 'applySource');
		$method->setAccessible(true);

		$actual = [];
		foreach (self::blocks() as $name => $block) {
			foreach (['fresh' => [], 'preset' => ['org/old', 'old-branch']] as $start => $preset) {
				$configuration = new Configuration();
				if ($preset !== []) {
					$configuration->setGithubRepo($preset[0]);
					$configuration->setGithubBranch($preset[1]);
					$configuration->resetUpdatedFields();
				}

				$method->invoke($handler, $configuration, $block);
				$actual[$name][$start] = [
					'sourceType' => $configuration->getSourceType(),
					'sourceUrl' => $configuration->getSourceUrl(),
					'githubRepo' => $configuration->getGithubRepo(),
					'githubBranch' => $configuration->getGithubBranch(),
					'githubPath' => $configuration->getGithubPath(),
					'updated' => $configuration->getUpdatedFields(),
				];
			}
		}

		if (getenv('UPDATE_GOLDEN') === '1') {
			file_put_contents(self::FIXTURE, json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
			$this->addToAssertionCount(1);
			return;
		}

		$expected = json_decode((string) file_get_contents(self::FIXTURE), true);
		foreach ($expected as $name => $outcome) {
			$this->assertSame($outcome, $actual[$name] ?? null, $name);
		}

		$this->assertSame(array_keys($expected), array_keys($actual));
	}//end testConfigurationSourceMatchesGolden()
}//end class
