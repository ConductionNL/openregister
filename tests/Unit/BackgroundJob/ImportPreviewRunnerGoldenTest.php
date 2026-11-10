<?php

/**
 * Golden test for ImportPreviewRunner::run().
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\BackgroundJob
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\BackgroundJob;

use OCA\OpenRegister\BackgroundJob\ImportPreviewRunner;
use OCA\OpenRegister\Db\ImportPreview;
use OCA\OpenRegister\Db\ImportPreviewMapper;
use OCA\OpenRegister\Service\Import\ImportPreviewService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use ReflectionMethod;
use RuntimeException;
use Throwable;

/**
 * Pins every call run() makes on the mapper, the service and the logger.
 *
 * @covers \OCA\OpenRegister\BackgroundJob\ImportPreviewRunner
 */
class ImportPreviewRunnerGoldenTest extends TestCase {

	/**
	 * The recorded trace.
	 *
	 * @var array<int, array>
	 */
	private array $trace = [];

	/**
	 * Run the job once.
	 *
	 * @param mixed          $argument The job argument.
	 * @param Throwable|null $findFails What find() throws, if anything.
	 * @param Throwable|null $phaseFails What the service phase throws, if anything.
	 *
	 * @return array The trace.
	 */
	private function act(mixed $argument, ?Throwable $findFails, ?Throwable $phaseFails): array {
		$preview = new ImportPreview();
		$mapper  = $this->createMock(ImportPreviewMapper::class);
		$mapper->method('find')->willReturnCallback(
			function (int $id) use ($findFails, $preview): ImportPreview {
				$this->trace[] = ['find', $id];
				if ($findFails !== null) {
					throw $findFails;
				}

				return $preview;
			}
		);

		$service = $this->createMock(ImportPreviewService::class);
		$service->method('commit')->willReturnCallback(
			function (ImportPreview $given, ?string $hash, $user) use ($preview, $phaseFails): ImportPreview {
				$this->trace[] = ['commit', $given === $preview, $hash, $user];
				if ($phaseFails !== null) {
					throw $phaseFails;
				}

				return $given;
			}
		);
		$service->method('runPreview')->willReturnCallback(
			function (ImportPreview $given) use ($preview, $phaseFails): ImportPreview {
				$this->trace[] = ['runPreview', $given === $preview];
				if ($phaseFails !== null) {
					throw $phaseFails;
				}

				return $given;
			}
		);

		$logger = new class($this->trace) extends AbstractLogger {
			public function __construct(private array &$sink) {
			}

			public function log($level, $message, array $context = []): void {
				$this->sink[] = ['log', $level, (string) $message, $context];
			}
		};

		$job = new ImportPreviewRunner($this->createMock(ITimeFactory::class), $mapper, $service, $logger);
		try {
			(new ReflectionMethod($job, 'run'))->invoke($job, $argument);
		} catch (Throwable $e) {
			$this->trace[] = ['thrown', get_class($e), $e->getMessage()];
		}

		return $this->trace;
	}

	public function testMissingPreviewId(): void {
		$this->assertSame(
			[['log', 'error', '[ImportPreviewRunner] Missing preview_id argument', []]],
			$this->act(['phase' => 'commit'], null, null)
		);
	}

	public function testPreviewGone(): void {
		$this->assertSame(
			[['find', 5], ['log', 'warning', '[ImportPreviewRunner] Preview 5 no longer exists', []]],
			$this->act(['preview_id' => '5'], new DoesNotExistException('x'), null)
		);
	}

	public function testOtherFindFailurePropagates(): void {
		$this->assertSame(
			[['find', 5], ['thrown', RuntimeException::class, 'db down']],
			$this->act(['preview_id' => 5], new RuntimeException('db down'), null)
		);
	}

	public function testDefaultPhaseIsPreview(): void {
		$this->assertSame([['find', 6], ['runPreview', true]], $this->act(['preview_id' => 6], null, null));
	}

	public function testUnknownPhaseRunsPreview(): void {
		$this->assertSame(
			[['find', 6], ['runPreview', true]],
			$this->act(['preview_id' => 6, 'phase' => 'other', 'source_hash' => 'h'], null, null)
		);
	}

	public function testCommitPassesSourceHash(): void {
		$this->assertSame(
			[['find', 7], ['commit', true, 'abc', null]],
			$this->act(['preview_id' => 7, 'phase' => 'commit', 'source_hash' => 'abc'], null, null)
		);
	}

	public function testCommitWithoutHashPassesNull(): void {
		$this->assertSame(
			[['find', 7], ['commit', true, null, null]],
			$this->act(['preview_id' => 7, 'phase' => 'commit'], null, null)
		);
	}

	public function testCommitFailureIsLogged(): void {
		$this->assertSame(
			[
				['find', 7],
				['commit', true, null, null],
				['log', 'error', '[ImportPreviewRunner] Preview 7 failed: stale', ['phase' => 'commit']],
			],
			$this->act(['preview_id' => '7', 'phase' => 'commit'], null, new RuntimeException('stale'))
		);
	}

	public function testPreviewFailureIsLogged(): void {
		$this->assertSame(
			[
				['find', 8],
				['runPreview', true],
				['log', 'error', '[ImportPreviewRunner] Preview 8 failed: bad csv', ['phase' => 'preview']],
			],
			$this->act(['preview_id' => 8], null, new RuntimeException('bad csv'))
		);
	}
}
