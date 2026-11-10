<?php

/**
 * Golden test for the queued file and object text extraction jobs.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\BackgroundJob
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\BackgroundJob;

use OCA\OpenRegister\BackgroundJob\ConfiguredJobRuntime;
use OCA\OpenRegister\BackgroundJob\FileTextExtractionJob;
use OCA\OpenRegister\BackgroundJob\ObjectTextExtractionJob;
use OCA\OpenRegister\Files\Service\FileExtractionOptions;
use OCA\OpenRegister\Files\Service\TextExtractionService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Records the config reads, extractor calls and log lines of both jobs in every
 * situation, against a golden recorded before the jobs shared a base.
 *
 * @covers \OCA\OpenRegister\BackgroundJob\AbstractTextExtractionJob
 * @covers \OCA\OpenRegister\BackgroundJob\FileTextExtractionJob
 * @covers \OCA\OpenRegister\BackgroundJob\ObjectTextExtractionJob
 */
class TextExtractionJobsGoldenTest extends TestCase {

	private const GOLDEN = __DIR__ . '/Golden/text-extraction-jobs.json';

	/**
	 * Run one job in one situation and record what it did.
	 *
	 * @param string $job file or object.
	 * @param string $case The situation.
	 *
	 * @return array<int, string>
	 */
	private function runJob(string $job, string $case): array {
		$seen = [];
		$config = $this->createMock(IAppConfig::class);
		$settings = [
			'file disabled' => ['fileManagement' => '{"extractionScope":"none"}'],
			'file unset' => [],
			'object disabled' => ['objectManagement' => '{"objectExtractionMode":"none"}'],
			'object unset' => ['fileManagement' => '{"extractionScope":"all"}'],
		][$case] ?? ['fileManagement' => '{"extractionScope":"all"}', 'objectManagement' => '{"objectExtractionMode":"background"}'];
		$config->method('getValueString')->willReturnCallback(function (string $app, string $key, string $default = '') use ($settings, &$seen) {
			$seen[] = "config.get $app $key default=" . json_encode($default);
			return $settings[$key] ?? $default;
		});
		$config->method('hasKey')->willReturnCallback(function (string $app, string $key) use ($settings, &$seen) {
			$seen[] = "config.has $app $key";
			return isset($settings[$key]);
		});
		$extractor = $this->createMock(TextExtractionService::class);
		foreach (['extractFile', 'extractObject'] as $method) {
			$extractor->method($method)->willReturnCallback(function (...$args) use ($method, $case, &$seen) {
				$seen[] = "extractor.$method " . json_encode(array_merge(...array_map(static fn ($arg) => $arg instanceof FileExtractionOptions ? [$arg->forceReExtract, $arg->entityTypes] : [$arg], $args)));
				if ($case === 'failing') {
					throw new RuntimeException('cannot read');
				}
			});
		}
		$logger = $this->createMock(LoggerInterface::class);
		foreach (['info', 'error', 'warning', 'debug'] as $level) {
			$logger->method($level)->willReturnCallback(function (string $message, array $context = []) use ($level, &$seen) {
				$context['file'] = basename((string)($context['file'] ?? ''));
				unset($context['line'], $context['trace']);
				if (isset($context['processing_time_ms']) === true) {
					$context['processing_time_ms'] = is_float($context['processing_time_ms']) ? 'float' : 'other';
				}

				$seen[] = "log.$level $message " . json_encode($context);
			});
		}

		$class = $job === 'file' ? FileTextExtractionJob::class : ObjectTextExtractionJob::class;
		$instance = new $class(new ConfiguredJobRuntime($this->createMock(ITimeFactory::class), $config, $logger), $extractor);
		$argument = [
			'missing' => ['other' => 1],
		][$case] ?? ['file_id' => '12', 'object_id' => '34'];
		$run = new \ReflectionMethod($instance, 'run');
		$run->invoke($instance, $argument);
		return $seen;
	}

	/**
	 * Both jobs behave as recorded in every situation.
	 *
	 * @return void
	 */
	public function testJobsMatchTheGolden(): void {
		$actual = [];
		foreach (['file' => ['file disabled', 'file unset'], 'object' => ['object disabled', 'object unset']] as $job => $disabled) {
			foreach ([...$disabled, 'missing', 'good', 'failing'] as $case) {
				$actual[$job][$case] = $this->runJob(job: $job, case: $case);
			}
		}

		$json = json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
		if (getenv('RECORD_GOLDEN') === '1') {
			file_put_contents(self::GOLDEN, $json);
		}

		$this->assertSame(file_get_contents(self::GOLDEN), $json);
	}
}
