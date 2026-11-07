<?php

/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Service;

use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Psr7\Request as GuzzleRequest;
use GuzzleHttp\Psr7\Response as GuzzleResponse;
use OCA\OpenRegister\Db\Webhook;
use OCA\OpenRegister\Db\WebhookLog;
use OCA\OpenRegister\Db\WebhookLogMapper;
use OCA\OpenRegister\Db\WebhookMapper;
use OCA\OpenRegister\Service\WebhookDeliveryAttempt;
use OCA\OpenRegister\Service\WebhookDeliveryRecorder;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\StreamInterface;
use Psr\Log\AbstractLogger;
use RuntimeException;

/**
 * Golden test for WebhookDeliveryRecorder: pins the log entry, the statistics call,
 * the saved log row and the structured log lines of every delivery outcome.
 *
 * @covers \OCA\OpenRegister\Service\WebhookDeliveryRecorder
 */
final class WebhookDeliveryRecorderGoldenTest extends TestCase {

	/** @var array<int,array{level:string,message:string,context:array}> */
	private array $logLines = [];

	/** @var array<int,bool> */
	private array $statistics = [];

	/** @var array<int,WebhookLog> */
	private array $inserted = [];

	private WebhookDeliveryRecorder $recorder;

	protected function setUp(): void {
		$lines = &$this->logLines;
		$logger = new class($lines) extends AbstractLogger {
			private array $lines;

			public function __construct(array &$lines) {
				$this->lines = &$lines;
			}

			public function log($level, $message, array $context = []): void {
				unset($context['line'], $context['trace']);
				$this->lines[] = ['level' => (string)$level, 'message' => (string)$message, 'context' => $context];
			}
		};

		$webhookMapper = $this->createMock(WebhookMapper::class);
		$webhookMapper->method('updateStatistics')->willReturnCallback(
			function (Webhook $webhook, bool $success): Webhook {
				$this->statistics[] = $success;
				return $webhook;
			}
		);
		$logMapper = $this->createMock(WebhookLogMapper::class);
		$logMapper->method('insert')->willReturnCallback(
			function (WebhookLog $log): WebhookLog {
				$this->inserted[] = $log;
				return $log;
			}
		);

		$this->recorder = new WebhookDeliveryRecorder(
			$logger,
			$webhookMapper,
			$logMapper,
			static fn (string $body, string $url): string => 'preview(' . substr($body, 0, 10) . '|' . $url . ')'
		);
	}

	private function webhook(string $policy = 'exponential', int $maxRetries = 3): Webhook {
		$webhook = new Webhook();
		$webhook->setId(7);
		$webhook->setName('hook');
		$webhook->setUrl('https://example.test/hook');
		$webhook->setMethod('PUT');
		$webhook->setRetryPolicy($policy);
		$webhook->setMaxRetries($maxRetries);
		$webhook->setTimeout(12);
		return $webhook;
	}

	private function delivery(Webhook $webhook, int $attempt = 1): WebhookDeliveryAttempt {
		$log = $this->recorder->startDeliveryLog($webhook, ['a' => 1]);
		$log->setEventClass('Evt');
		$log->setAttempt($attempt);
		return new WebhookDeliveryAttempt($webhook, $log);
	}

	private function stripFile(array $context): array {
		$this->assertStringEndsWith('/lib/Service/WebhookDeliveryRecorder.php', $context['file']);
		unset($context['file']);
		return $context;
	}

	public function testStartDeliveryLog(): void {
		$log = $this->recorder->startDeliveryLog($this->webhook(), ['a' => 1]);
		$this->assertSame(7, $log->getWebhook());
		$this->assertSame(['a' => 1], $log->getPayloadArray());
		$this->assertSame('https://example.test/hook', $log->getUrl());
		$this->assertSame('PUT', $log->getMethod());
	}

	public function testSuccessfulResponse(): void {
		$delivery = $this->delivery($this->webhook());
		$this->assertTrue($this->recorder->recordResponse($delivery, ['status_code' => 204, 'body' => 'ok']));

		$this->assertTrue($delivery->log->getSuccess());
		$this->assertSame(204, $delivery->log->getStatusCode());
		$this->assertSame('ok', $delivery->log->getResponseBody());
		$this->assertNull($delivery->log->getRequestBody());
		$this->assertSame([true], $this->statistics);
		$this->assertSame([$delivery->log], $this->inserted);
		$this->assertCount(1, $this->logLines);
		$this->assertSame('info', $this->logLines[0]['level']);
		$this->assertSame('[WebhookService] Webhook delivered successfully', $this->logLines[0]['message']);
		$this->assertSame(
			['webhook_id' => 7, 'webhook_name' => 'hook', 'event' => 'Evt', 'status_code' => 204, 'attempt' => 1],
			$this->stripFile($this->logLines[0]['context'])
		);
	}

	public function testRejectedStatusSchedulesExponentialRetry(): void {
		$delivery = $this->delivery($this->webhook(), 2);
		$before = time();
		$this->assertFalse($this->recorder->recordResponse($delivery, ['status_code' => '500', 'body' => 'boom']));
		$after = time();

		$log = $delivery->log;
		$this->assertFalse($log->getSuccess());
		$this->assertSame(500, $log->getStatusCode());
		$this->assertSame('boom', $log->getResponseBody());
		$this->assertSame('Webhook endpoint returned non-2xx status: 500', $log->getErrorMessage());
		$this->assertSame('{"a":1}', $log->getRequestBody());
		$retryAt = $log->getNextRetryAt()->getTimestamp();
		$this->assertGreaterThanOrEqual($before + 240, $retryAt);
		$this->assertLessThanOrEqual($after + 240, $retryAt);
		$this->assertSame([false], $this->statistics);
		$this->assertSame([$log], $this->inserted);

		$this->assertCount(2, $this->logLines);
		$this->assertSame('error', $this->logLines[0]['level']);
		$this->assertSame('[WebhookService] Webhook delivery failed with non-2xx status', $this->logLines[0]['message']);
		$this->assertSame(
			['webhook_id' => 7, 'webhook_name' => 'hook', 'event' => 'Evt', 'status_code' => 500, 'attempt' => 2, 'max_retries' => 3],
			$this->stripFile($this->logLines[0]['context'])
		);
		$this->assertSame('info', $this->logLines[1]['level']);
		$this->assertSame('[WebhookService] Scheduling webhook retry', $this->logLines[1]['message']);
		$this->assertSame(
			['webhook_id' => 7, 'webhook_name' => 'hook', 'event' => 'Evt', 'attempt' => 3, 'delay' => 480],
			$this->stripFile($this->logLines[1]['context'])
		);
	}

	public function testRetryDelayPerPolicy(): void {
		$expected = ['exponential' => [240, 120], 'linear' => [600, 300], 'fixed' => [300, 300], 'other' => [300, 300]];
		foreach ($expected as $policy => [$scheduleDelay, $retryDelay]) {
			$this->logLines = [];
			$delivery = $this->delivery($this->webhook($policy), 1);
			$before = time();
			$this->recorder->recordResponse($delivery, ['status_code' => 404, 'body' => '']);
			$after = time();
			$this->assertSame($scheduleDelay, $this->logLines[1]['context']['delay'], $policy);
			$retryAt = $delivery->log->getNextRetryAt()->getTimestamp();
			$this->assertGreaterThanOrEqual($before + $retryDelay, $retryAt, $policy);
			$this->assertLessThanOrEqual($after + $retryDelay, $retryAt, $policy);
		}
	}

	public function testRejectedStatusWithoutRetriesLeft(): void {
		$delivery = $this->delivery($this->webhook('linear', 2), 2);
		$this->recorder->recordResponse($delivery, ['status_code' => 199, 'body' => str_repeat('x', 1048577)]);

		$this->assertNull($delivery->log->getNextRetryAt());
		$this->assertSame(str_repeat('x', 1048576) . '... [truncated]', $delivery->log->getResponseBody());
		$this->assertCount(1, $this->logLines);
		$this->assertSame([$delivery->log], $this->inserted);
	}

	public function testConnectionFailureWithCode(): void {
		$delivery = $this->delivery($this->webhook('fixed', 1), 1);
		$withCode = new class('refused', new GuzzleRequest('PUT', 'https://example.test/hook')) extends RequestException {
			public function __construct(string $message, GuzzleRequest $request) {
				parent::__construct($message, $request);
				$this->code = 28;
			}
		};

		$this->assertFalse($this->recorder->recordRequestFailure($delivery, $withCode));

		$log = $delivery->log;
		$this->assertFalse($log->getSuccess());
		$this->assertNull($log->getStatusCode());
		$this->assertSame('refused', $log->getErrorMessage());
		$this->assertSame('{"a":1}', $log->getRequestBody());
		$this->assertNull($log->getNextRetryAt());
		$this->assertSame([false], $this->statistics);
		$this->assertCount(1, $this->logLines);
		$context = $this->stripFile($this->logLines[0]['context']);
		$this->assertSame('[WebhookService] Webhook delivery failed', $this->logLines[0]['message']);
		$this->assertSame(
			[
				'webhook_id' => 7,
				'webhook_name' => 'hook',
				'event' => 'Evt',
				'error' => 'refused',
				'error_details' => [
					'connection_error' => true,
					'error_code' => 28,
					'request_url' => 'https://example.test/hook',
					'request_method' => 'PUT',
					'timeout' => 12,
				],
				'attempt' => 1,
				'max_retries' => 1,
				'exception_class' => get_class($withCode),
				'exception_code' => 28,
			],
			$context
		);
	}

	public function testConnectionFailureWithoutCode(): void {
		$delivery = $this->delivery($this->webhook(), 1);
		$exception = new RequestException('timeout', new GuzzleRequest('PUT', 'https://example.test/hook'));

		$this->recorder->recordRequestFailure($delivery, $exception);

		$this->assertSame(
			['connection_error' => true, 'request_url' => 'https://example.test/hook', 'request_method' => 'PUT', 'timeout' => 12],
			$this->logLines[0]['context']['error_details']
		);
		$this->assertNotNull($delivery->log->getNextRetryAt());
		$this->assertSame('[WebhookService] Scheduling webhook retry', $this->logLines[1]['message']);
		$this->assertSame(240, $this->logLines[1]['context']['delay']);
	}

	public function testErrorResponseBodies(): void {
		$cases = [
			'{"message":"bad","error":"e"}' => 'oops: bad',
			'{"error":"nope"}' => 'oops: nope',
			'{"other":1}' => 'oops',
			'not json' => 'oops',
			'{"message":null,"error":null}' => 'oops',
		];
		foreach ($cases as $body => $message) {
			$this->logLines = [];
			$delivery = $this->delivery($this->webhook('fixed', 0), 1);
			$response = new GuzzleResponse(422, [], $body);
			$exception = new RequestException('oops', new GuzzleRequest('PUT', 'https://example.test/hook'), $response);

			$this->recorder->recordRequestFailure($delivery, $exception);

			$this->assertSame($message, $delivery->log->getErrorMessage(), $body);
			$this->assertSame(422, $delivery->log->getStatusCode(), $body);
			$this->assertSame($body, $delivery->log->getResponseBody(), $body);
			$this->assertSame(
				[
					'status_code' => 422,
					'response_body' => 'preview(' . substr($body, 0, 10) . '|https://example.test/hook)',
					'request_url' => 'https://example.test/hook',
					'request_method' => 'PUT',
					'timeout' => 12,
				],
				$this->logLines[0]['context']['error_details'],
				$body
			);
		}
	}

	public function testUnreadableErrorBodyIsIgnored(): void {
		$delivery = $this->delivery($this->webhook('fixed', 0), 1);
		$stream = $this->createMock(StreamInterface::class);
		$stream->method('__toString')->willThrowException(new RuntimeException('stream gone'));
		$response = new GuzzleResponse(503, [], $stream);
		$exception = new RequestException('down', new GuzzleRequest('PUT', 'https://example.test/hook'), $response);

		$this->recorder->recordRequestFailure($delivery, $exception);

		$this->assertSame('down', $delivery->log->getErrorMessage());
		$this->assertSame(503, $delivery->log->getStatusCode());
		$this->assertNull($delivery->log->getResponseBody());
		$this->assertSame(
			['status_code' => 503, 'request_url' => 'https://example.test/hook', 'request_method' => 'PUT', 'timeout' => 12],
			$this->logLines[0]['context']['error_details']
		);
	}

	public function testUnexpectedFailure(): void {
		$delivery = $this->delivery($this->webhook(), 1);
		$this->assertFalse($this->recorder->recordUnexpectedFailure($delivery, new \Exception('kaput')));

		$this->assertFalse($delivery->log->getSuccess());
		$this->assertSame('kaput', $delivery->log->getErrorMessage());
		$this->assertNull($delivery->log->getRequestBody());
		$this->assertSame([], $this->statistics);
		$this->assertSame([$delivery->log], $this->inserted);
		$this->assertSame('error', $this->logLines[0]['level']);
		$this->assertSame('[WebhookService] Unexpected error during webhook delivery', $this->logLines[0]['message']);
		$this->assertSame(
			['webhook_id' => 7, 'event' => 'Evt', 'error' => 'kaput'],
			$this->stripFile($this->logLines[0]['context'])
		);
	}

	public function testCapResponseBody(): void {
		$this->assertSame('short', $this->recorder->capResponseBody('short'));
		$this->assertSame(str_repeat('y', 1048576), $this->recorder->capResponseBody(str_repeat('y', 1048576)));
		$this->assertSame(
			str_repeat('y', 1048576) . '... [truncated]',
			$this->recorder->capResponseBody(str_repeat('y', 1048577))
		);
	}
}
