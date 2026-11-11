<?php

declare(strict_types=1);

/**
 * Golden of GenericInitializeActions: what the repair step writes into the
 * action-authorisation matrix, and what it reports, for every seed state.
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

use JsonException;
use OCA\OpenRegister\AppHost\Repair\GenericInitializeActions;
use OCA\OpenRegister\AppHost\Service\GenericActionAuthService;
use OCP\App\IAppManager;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Stringable;

/**
 * Pins the matrix writes and the report trail of the action seed repair step.
 */
class GenericInitializeActionsTrailTest extends TestCase {

	/**
	 * Everything observable, in order.
	 *
	 * @var array<int, string>
	 */
	private array $trail = [];

	/**
	 * Temporary seed file, removed after each test.
	 *
	 * @var string
	 */
	private string $seedPath = '';

	protected function tearDown(): void {
		if ($this->seedPath !== '' && is_file($this->seedPath) === true) {
			unlink($this->seedPath);
		}
	}//end tearDown()

	public function testNoAppPathLeavesTheMatrixAlone(): void {
		$this->runStep(seed: null, existing: [], writeFails: false, appPath: false);

		$this->assertSame(
			[
				'warning actions.seed.json not found — matrix left unchanged (default-deny).',
				'log warning [AppHost:demo] ADR-023 seed file missing',
			],
			$this->trail
		);
	}//end testNoAppPathLeavesTheMatrixAlone()

	public function testAMissingSeedLeavesTheMatrixAlone(): void {
		$this->runStep(seed: null, existing: ['a' => ['admin']], writeFails: false, appPath: true);

		$this->assertSame(
			[
				'warning actions.seed.json not found — matrix left unchanged (default-deny).',
				'log warning [AppHost:demo] ADR-023 seed file missing',
			],
			$this->trail
		);
	}//end testAMissingSeedLeavesTheMatrixAlone()

	public function testAnInvalidSeedLeavesTheMatrixAlone(): void {
		$this->runStep(seed: '{nope', existing: [], writeFails: false, appPath: true);

		$this->assertSame(
			[
				'warning actions.seed.json invalid JSON: Syntax error',
				'log error [AppHost:demo] ADR-023 seed malformed: Syntax error',
			],
			$this->trail
		);
	}//end testAnInvalidSeedLeavesTheMatrixAlone()

	public function testASeedWithoutActionsLeavesTheMatrixAlone(): void {
		$this->runStep(seed: '{"other": 1}', existing: [], writeFails: false, appPath: true);

		$this->assertSame(['warning actions.seed.json missing `actions` object — matrix left unchanged.'], $this->trail);
	}//end testASeedWithoutActionsLeavesTheMatrixAlone()

	public function testAMatrixThatHasEverySeededActionIsPreserved(): void {
		$this->runStep(seed: '{"actions": {"a": ["x"]}}', existing: ['a' => ['admin'], 'b' => []], writeFails: false, appPath: true);

		$this->assertSame(['info Action matrix already has 2 entries — preserving.'], $this->trail);
	}//end testAMatrixThatHasEverySeededActionIsPreserved()

	public function testAnEmptyMatrixIsSeeded(): void {
		$this->runStep(seed: '{"actions": {"a": ["x"], "b": []}}', existing: [], writeFails: false, appPath: true);

		$this->assertSame(
			['set {"a":["x"],"b":[]}', 'info Seeded action matrix with 2 actions (default: admin-only).'],
			$this->trail
		);
	}//end testAnEmptyMatrixIsSeeded()

	public function testAnExistingMatrixOnlyGainsWhatItLacks(): void {
		$this->runStep(seed: '{"actions": {"a": ["x"], "b": ["y"], "c": []}}', existing: ['a' => ['admin']], writeFails: false, appPath: true);

		$this->assertSame(
			[
				'set {"a":["admin"],"b":["y"],"c":[]}',
				'info Action matrix kept its 1 entries and gained 2 seeded actions: b, c.',
			],
			$this->trail
		);
	}//end testAnExistingMatrixOnlyGainsWhatItLacks()

	public function testAFailedWriteIsReported(): void {
		$this->runStep(seed: '{"actions": {"a": ["x"]}}', existing: [], writeFails: true, appPath: true);

		$this->assertSame(['set {"a":["x"]}', 'warning Failed to write matrix: cannot encode'], $this->trail);
	}//end testAFailedWriteIsReported()

	/**
	 * Run the repair step over a recording matrix, output and logger.
	 *
	 * @param string|null                     $seed       The seed file content; null for no file.
	 * @param array<string, array<int,string>> $existing   The stored matrix.
	 * @param bool                            $writeFails Whether setMatrix() throws.
	 * @param bool                            $appPath    Whether the app path resolves.
	 *
	 * @return void
	 */
	private function runStep(?string $seed, array $existing, bool $writeFails, bool $appPath): void {
		$this->trail = [];
		$dir         = sys_get_temp_dir() . '/or-seed-' . getmypid();
		if (is_dir($dir . '/lib') === false) {
			mkdir($dir . '/lib', 0777, true);
		}

		$this->seedPath = $dir . '/lib/actions.seed.json';
		if (is_file($this->seedPath) === true) {
			unlink($this->seedPath);
		}

		if ($seed !== null) {
			file_put_contents($this->seedPath, $seed);
		}

		$auth = $this->createMock(GenericActionAuthService::class);
		$auth->method('getMatrix')->willReturn($existing);
		$auth->method('setMatrix')->willReturnCallback(
			function (array $matrix) use ($writeFails): void {
				$this->trail[] = 'set ' . json_encode($matrix);
				if ($writeFails === true) {
					throw new JsonException('cannot encode');
				}
			}
		);

		$apps = $this->createMock(IAppManager::class);
		if ($appPath === true) {
			$apps->method('getAppPath')->willReturn($dir);
		} else {
			$apps->method('getAppPath')->willThrowException(new \RuntimeException('no app'));
		}

		$output = $this->createMock(IOutput::class);
		$output->method('info')->willReturnCallback(function (string $message): void {
			$this->trail[] = 'info ' . $message;
		});
		$output->method('warning')->willReturnCallback(function (string $message): void {
			$this->trail[] = 'warning ' . $message;
		});

		$trail  = &$this->trail;
		$logger = new class($trail) extends AbstractLogger {
			/**
			 * @param array<int, string> $trail The shared trail.
			 */
			public function __construct(private array &$trail) {
			}

			public function log($level, string|Stringable $message, array $context = []): void {
				$this->trail[] = 'log ' . $level . ' ' . $message;
			}
		};

		(new GenericInitializeActions('demo', $auth, $apps, $logger))->run($output);
	}//end runStep()
}//end class
