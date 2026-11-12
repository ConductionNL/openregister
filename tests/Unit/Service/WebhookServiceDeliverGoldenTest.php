<?php

/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Service;

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Psr7\Request as GuzzleRequest;
use GuzzleHttp\Psr7\Response as GuzzleResponse;
use OCA\OpenRegister\Db\MappingMapper;
use OCA\OpenRegister\Db\Webhook;
use OCA\OpenRegister\Db\WebhookLog;
use OCA\OpenRegister\Db\WebhookLogMapper;
use OCA\OpenRegister\Db\WebhookMapper;
use OCA\OpenRegister\Service\MappingService;
use OCA\OpenRegister\Service\WebhookDelivery;
use OCA\OpenRegister\Service\WebhookDispatchServices;
use OCA\OpenRegister\Service\WebhookEvent;
use OCA\OpenRegister\Service\WebhookService;
use OCA\OpenRegister\Service\WebhookStores;
use OCP\BackgroundJob\IJobList;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Golden test for WebhookService::deliverWebhook().
 *
 * Each case scripts the HTTP client's answer. The return value, every logger,
 * webhook mapper and log mapper call, and the saved log entry are compared with
 * fixtures/webhook-deliver-golden.json. Set UPDATE_GOLDEN=1 to rewrite the fixture.
 */
class WebhookServiceDeliverGoldenTest extends TestCase {
	private const FIXTURE = __DIR__ . '/fixtures/webhook-deliver-golden.json';

	/**
	 * The recorded collaborator calls of the current case.
	 *
	 * @var array<int, array<int, mixed>>
	 */
	private array $calls = [];

	public static function cases(): array {
		return [
			'disabled' => [['enabled' => false, 'answer' => ['status', 200, 'ok']]],
			'filters-mismatch' => [['filters' => ['kind' => 'other'], 'answer' => ['status', 200, 'ok']]],
			'success-200' => [['answer' => ['status', 200, '{"ok":true}']]],
			'success-204-attempt-2' => [['attempt' => 2, 'answer' => ['status', 204, '']]],
			'status-500-retry-left' => [['answer' => ['status', 500, '{"message":"boom"}']]],
			'status-404-no-retry' => [['maxRetries' => 1, 'answer' => ['status', 404, 'missing']]],
			'status-302-last-attempt' => [['attempt' => 3, 'answer' => ['status', 302, '']]],
			'status-199' => [['answer' => ['status', 199, 'early']]],
			'connect-error' => [['answer' => ['connect', 'Could not resolve host']]],
			'request-error-no-response-code' => [['answer' => ['request-noresponse', 'Timed out', 28]]],
			'request-error-no-response-zero' => [['maxRetries' => 1, 'answer' => ['request-noresponse', 'Reset', 0]]],
			'request-error-json-message' => [['answer' => ['request', 422, '{"message":"Invalid","error":"E"}']]],
			'request-error-json-error' => [['answer' => ['request', 403, '{"error":"Forbidden"}']]],
			'request-error-plain-body' => [['maxRetries' => 1, 'answer' => ['request', 500, 'Internal error']]],
			'request-error-json-without-keys' => [['answer' => ['request', 400, '{"detail":"x"}']]],
			'request-error-last-attempt' => [['attempt' => 3, 'answer' => ['request', 503, '']]],
			'unexpected-error' => [['answer' => ['throw', 'kaput']]],
			'request-error-private-target' => [['url' => 'http://10.0.0.5/hook', 'answer' => ['request', 500, '{"message":"inside"}']]],
			'request-error-long-body' => [['answer' => ['request', 500, str_repeat('x', 1500)]]],
		];
	}//end cases()

	#[DataProvider('cases')]
	public function testDeliverWebhookMatchesGolden(array $case): void {
		$service = $this->buildService(case: $case);
		$webhook = new Webhook();
		$webhook->setId(7);
		$webhook->setUuid('webhook-uuid-7');
		$webhook->setName('Golden hook');
		$webhook->setUrl($case['url'] ?? 'https://93.184.216.34/hook');
		if (isset($case['url']) === true) {
			$webhook->setConfigurationArray(['allowPrivateTargets' => true]);
		}
		$webhook->setMethod('POST');
		$webhook->setEnabled($case['enabled'] ?? true);
		$webhook->setMaxRetries($case['maxRetries'] ?? 3);
		$webhook->setRetryPolicy('fixed');
		if (isset($case['filters']) === true) {
			$webhook->setFiltersArray($case['filters']);
		}

		$result = $service->deliverWebhook(
			new WebhookDelivery(
				webhook: $webhook,
				event: new WebhookEvent(name: 'Golden\\Event', payload: ['kind' => 'thing', 'id' => 5]),
				attempt: $case['attempt'] ?? 1
			)
		);

		$actual = ['result' => $result, 'calls' => $this->calls];
		$json = (string)json_encode($actual, JSON_UNESCAPED_SLASHES);
		$json = (string)preg_replace('/\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d[+-]\d\d:\d\d/', '<time>', $json);
		$actual = json_decode($json, true);
		$name = $this->dataName();

		$golden = [];
		if (is_file(self::FIXTURE) === true) {
			$golden = json_decode((string)file_get_contents(self::FIXTURE), true);
		}

		if (getenv('UPDATE_GOLDEN') === '1') {
			$golden[$name] = $actual;
			ksort($golden);
			file_put_contents(self::FIXTURE, json_encode($golden, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
			$this->addToAssertionCount(1);
			return;
		}

		$this->assertArrayHasKey($name, $golden, 'No golden recording for this case.');
		$this->assertSame($golden[$name], $actual);
	}//end testDeliverWebhookMatchesGolden()

	private function buildService(array $case): WebhookService {
		$this->calls = [];

		$logger = $this->createMock(LoggerInterface::class);
		foreach (['debug', 'info', 'warning', 'error'] as $level) {
			$logger->method($level)->willReturnCallback(function (string $message, array $context = []) use ($level): void {
				unset($context['file'], $context['line'], $context['trace']);
				$this->calls[] = ['logger.' . $level, $message, $context];
			});
		}

		$webhookMapper = $this->createMock(WebhookMapper::class);
		$webhookMapper->method('updateStatistics')->willReturnCallback(function (Webhook $webhook, bool $success): Webhook {
			$this->calls[] = ['webhookMapper.updateStatistics', $webhook->getId(), $success];
			return $webhook;
		});

		$logMapper = $this->createMock(WebhookLogMapper::class);
		$logMapper->method('insert')->willReturnCallback(function (WebhookLog $log): WebhookLog {
			$row = $log->jsonSerialize();
			unset($row['created']);
			$row['payloadRaw'] = $log->getPayload();
			$this->calls[] = ['webhookLogMapper.insert', $row];
			return $log;
		});

		$service = new WebhookService(
			stores: new WebhookStores(
				webhookMapper: $webhookMapper,
				webhookLogMapper: $logMapper,
				mappingMapper: $this->createMock(MappingMapper::class),
				mappingService: $this->createMock(MappingService::class)
			),
			dispatch: new WebhookDispatchServices(
				jobList: $this->createMock(IJobList::class)
			),
			logger: $logger
		);

		$client = $this->createMock(GuzzleClient::class);
		$answer = $case['answer'];
		$request = new GuzzleRequest('POST', 'https://93.184.216.34/hook');
		$client->method('request')->willReturnCallback(function () use ($answer, $request): GuzzleResponse {
			$this->calls[] = ['client.request'];
			switch ($answer[0]) {
				case 'status':
					return new GuzzleResponse($answer[1], [], $answer[2]);
				case 'connect':
					throw new ConnectException($answer[1], $request);
				case 'request-noresponse':
					$exception = new RequestException($answer[1], $request);
					(function (int $code): void {
						$this->code = $code;
					})->call($exception, $answer[2]);
					throw $exception;
				case 'request':
					throw new RequestException('Client error', $request, new GuzzleResponse($answer[1], [], $answer[2]));
				default:
					throw new RuntimeException($answer[1]);
			}
		});
		$property = (new \ReflectionClass($service))->getProperty('client');
		$property->setAccessible(true);
		$property->setValue($service, $client);

		return $service;
	}//end buildService()
}//end class
