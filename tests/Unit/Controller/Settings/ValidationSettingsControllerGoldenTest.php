<?php

/**
 * Golden test for the ValidationSettingsController actions.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Controller\Settings
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Controller\Settings;

use Exception;
use InvalidArgumentException;
use OCA\OpenRegister\Controller\Settings\ValidationSettingsController;
use OCA\OpenRegister\Kernel\Contracts\AppSettingsInterface;
use OCP\IRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use TypeError;

/**
 * Pins every collaborator call and the response of each validation action.
 *
 * Each case records the request reads, the settings service calls and the
 * log lines, plus the response status and data, and compares the recording
 * with fixtures/validation-settings-golden.json. A request body is served
 * through ValidationSettingsInputStub in place of php://input. Values that
 * belong to the run rather than the code are replaced by markers: the
 * process memory, the timestamp, the log line number, file path and trace.
 * A TypeError that escapes an action is recorded by class only.
 * Set UPDATE_GOLDEN=1 to rewrite the fixture.
 *
 * @covers \OCA\OpenRegister\Controller\Settings\ValidationSettingsController
 */
class ValidationSettingsControllerGoldenTest extends TestCase {
	private const FIXTURE = __DIR__ . '/fixtures/validation-settings-golden.json';

	/** @var list<array<string, mixed>> */
	private array $calls = [];

	/** @var array<string, mixed>|null */
	private static ?array $recorded = null;

	/** @var array<string, mixed> */
	private static array $golden = [];

	/** @var bool Whether the php wrapper is replaced by the stub right now. */
	private static bool $replaced = false;

	/**
	 * @return array<string, array{0: string, 1: array<string, mixed>}>
	 */
	public static function cases(): array {
		$huge = 1 << 50;
		$query = ['maxObjects' => 5, 'batchSize' => 10, 'mode' => 'parallel', 'collectErrors' => 'true'];
		return [
			'validate all' => ['validateAllObjects', []],
			'validate all throws' => ['validateAllObjects', ['throw' => 'exception']],
			'mass defaults' => ['massValidateObjects', []],
			'mass query params' => ['massValidateObjects', ['params' => $query]],
			'mass collect errors no' => ['massValidateObjects', ['params' => ['collectErrors' => 'no']]],
			'mass collect errors bool' => ['massValidateObjects', ['params' => ['collectErrors' => true]]],
			'mass body' => ['massValidateObjects', ['body' => '{"maxObjects":3,"batchSize":50,"mode":"parallel","collectErrors":"yes"}']],
			'mass body overrides query mode' => ['massValidateObjects', ['params' => ['mode' => 'parallel', 'collectErrors' => true], 'body' => '{}']],
			'mass body partial' => ['massValidateObjects', ['body' => '{"batchSize":20}']],
			'mass body null' => ['massValidateObjects', ['params' => ['mode' => 'parallel'], 'body' => 'null']],
			'mass body false' => ['massValidateObjects', ['params' => ['mode' => 'parallel'], 'body' => 'false']],
			'mass body scalar' => ['massValidateObjects', ['params' => ['mode' => 'parallel'], 'body' => '5']],
			'mass body not json' => ['massValidateObjects', ['params' => ['mode' => 'parallel'], 'body' => '{oops']],
			'mass body ignored when max set' => ['massValidateObjects', ['params' => ['maxObjects' => 2], 'body' => '{"maxObjects":9}']],
			'mass body ignored when batch set' => ['massValidateObjects', ['params' => ['batchSize' => 7], 'body' => '{"maxObjects":9}']],
			'mass string max skips body' => ['massValidateObjects', ['params' => ['maxObjects' => '0'], 'body' => '{"maxObjects":9}']],
			'mass invalid argument' => ['massValidateObjects', ['throw' => 'invalid']],
			'mass throws' => ['massValidateObjects', ['throw' => 'exception']],
			'predict default count safe' => ['predictMassValidationMemory', ['limit' => $huge]],
			'predict default count unsafe' => ['predictMassValidationMemory', ['limit' => 0]],
			'predict query count' => ['predictMassValidationMemory', ['limit' => $huge, 'params' => ['maxObjects' => 5]]],
			'predict negative count' => ['predictMassValidationMemory', ['limit' => $huge, 'params' => ['maxObjects' => -1]]],
			'predict body count' => ['predictMassValidationMemory', ['limit' => $huge, 'body' => '{"maxObjects":12}']],
			'predict body without count' => ['predictMassValidationMemory', ['limit' => $huge, 'body' => '{"x":1}']],
			'predict body null' => ['predictMassValidationMemory', ['limit' => $huge, 'body' => 'null']],
			'predict body ignored' => ['predictMassValidationMemory', ['limit' => $huge, 'params' => ['maxObjects' => 3], 'body' => '{"maxObjects":12}']],
			'predict throws' => ['predictMassValidationMemory', ['throw' => 'exception']],
		];
	}

	#[DataProvider('cases')]
	public function testActionMatchesTheRecording(string $action, array $case): void {
		$this->calls = [];
		$controller = new ValidationSettingsController('openregister', $this->request($case), $this->settings($case), $this->logger());

		$this->serveInput($case['body'] ?? null);
		try {
			$response = $controller->$action();
			$actual = ['calls' => $this->calls, 'status' => $response->getStatus(), 'data' => $response->getData()];
		} catch (TypeError $e) {
			$actual = ['calls' => $this->calls, 'threw' => get_class($e)];
		} finally {
			$this->serveInput(null);
		}

		$this->compare($this->dataName(), $this->masked($actual));
	}

	private function serveInput(?string $body): void {
		if ($body === null) {
			if (self::$replaced === true) {
				stream_wrapper_restore('php');
				self::$replaced = false;
			}

			return;
		}

		ValidationSettingsInputStub::$body = $body;
		stream_wrapper_unregister('php');
		stream_wrapper_register('php', ValidationSettingsInputStub::class);
		self::$replaced = true;
	}

	private function request(array $case): IRequest {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(function (string $key, $default = null) use ($case) {
			$this->calls[] = ['request.getParam' => [$key, $default]];
			return $case['params'][$key] ?? $default;
		});
		return $request;
	}

	private function settings(array $case): AppSettingsInterface {
		$settings = $this->createMock(AppSettingsInterface::class);
		$throw = function () use ($case): void {
			if (($case['throw'] ?? null) === 'invalid') {
				throw new InvalidArgumentException('batchSize must be positive');
			}

			if (($case['throw'] ?? null) === 'exception') {
				throw new Exception('database gone');
			}
		};
		$settings->method('validateAllObjects')->willReturnCallback(function () use ($throw) {
			$this->calls[] = ['settings.validateAllObjects' => []];
			$throw();
			return ['total_objects' => 3, 'valid_objects' => 2];
		});
		$settings->method('massValidateObjects')->willReturnCallback(function (int $max, int $batch, string $mode, bool $collect) use ($throw) {
			$this->calls[] = ['settings.massValidateObjects' => [$max, $batch, $mode, $collect]];
			$throw();
			return ['success' => true, 'processed' => $max];
		});
		$settings->method('convertToBytes')->willReturnCallback(function (string $limit) use ($case, $throw) {
			$this->calls[] = ['settings.convertToBytes' => ['(memory_limit)']];
			$throw();
			return $case['limit'];
		});
		$settings->method('formatBytes')->willReturnCallback(function (int $bytes) {
			$this->calls[] = ['settings.formatBytes' => [$bytes]];
			return 'fmt(' . $bytes . ')';
		});
		return $settings;
	}

	private function logger(): LoggerInterface {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('error')->willReturnCallback(function ($message, array $context = []) {
			$context['file'] = basename($context['file']);
			$context['line'] = '(line)';
			$context['trace'] = '(trace)';
			$this->calls[] = ['logger.error' => [$message, $context]];
		});
		return $logger;
	}

	/**
	 * Replace the values that belong to the run with markers.
	 *
	 * @param array<string, mixed> $actual The recording.
	 *
	 * @return array<string, mixed> The recording with markers.
	 */
	private function masked(array $actual): array {
		$data = $actual['data'] ?? [];
		if (isset($data['timestamp']) === true) {
			$this->assertMatchesRegularExpression('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d[+-]\d\d:\d\d$/', $data['timestamp']);
			$actual['data']['timestamp'] = '(timestamp)';
		}

		if (isset($data['current_memory_bytes']) === true) {
			$this->assertSame($data['memory_limit_bytes'] - $data['current_memory_bytes'], $data['available_memory_bytes']);
			$markers = [$data['current_memory_bytes'] => '(current)', $data['available_memory_bytes'] => '(limit - current)'];
			$actual['data']['current_memory_bytes'] = '(current)';
			$actual['data']['available_memory_bytes'] = '(limit - current)';
			$actual['data']['formatted']['available'] = '(formatted limit - current)';
			$actual['data']['formatted']['current_usage'] = '(formatted current)';
			foreach ($actual['calls'] as $i => $call) {
				if (isset($call['settings.formatBytes'], $markers[$call['settings.formatBytes'][0]]) === true) {
					$actual['calls'][$i]['settings.formatBytes'][0] = $markers[$call['settings.formatBytes'][0]];
				}
			}
		}

		return $actual;
	}

	/**
	 * Compare a recording with the fixture, or rewrite it under UPDATE_GOLDEN=1.
	 *
	 * @param string $key The case name.
	 * @param array<string, mixed> $actual The recording.
	 *
	 * @return void
	 */
	private function compare(string $key, array $actual): void {
		if (getenv('UPDATE_GOLDEN') === '1') {
			if (self::$recorded === null) {
				self::$recorded = [];
			}

			self::$recorded[$key] = $actual;
			ksort(self::$recorded);
			file_put_contents(
				self::FIXTURE,
				json_encode(self::$recorded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n"
			);
			self::$golden = self::$recorded;
		}

		if (self::$golden === []) {
			self::$golden = json_decode((string)file_get_contents(self::FIXTURE), true);
		}

		$this->assertArrayHasKey($key, self::$golden);
		$this->assertSame(self::$golden[$key], $actual);
	}
}

/**
 * Serves a fixed body for php://input while the php wrapper is replaced.
 */
final class ValidationSettingsInputStub {
	/** @var string The body php://input yields. */
	public static string $body = '';

	/** @var resource|null Set by PHP. */
	public $context;

	/** @var int How far the body has been read. */
	private int $position = 0;

	public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool {
		$this->position = 0;
		return $path === 'php://input';
	}

	public function stream_read(int $count): string {
		$chunk = substr(self::$body, $this->position, $count);
		$this->position += strlen($chunk);
		return $chunk;
	}

	public function stream_eof(): bool {
		return $this->position >= strlen(self::$body);
	}

	/**
	 * @return array<int|string, int>
	 */
	public function stream_stat(): array {
		return [];
	}
}
