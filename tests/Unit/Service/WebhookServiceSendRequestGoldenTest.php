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
use GuzzleHttp\Psr7\Request as GuzzleRequest;
use GuzzleHttp\Psr7\Response as GuzzleResponse;
use GuzzleHttp\Psr7\Uri;
use OCA\OpenRegister\Db\MappingMapper;
use OCA\OpenRegister\Db\Webhook;
use OCA\OpenRegister\Db\WebhookLogMapper;
use OCA\OpenRegister\Db\WebhookMapper;
use OCA\OpenRegister\Service\MappingService;
use OCA\OpenRegister\Service\WebhookDispatchServices;
use OCA\OpenRegister\Service\WebhookService;
use OCA\OpenRegister\Service\WebhookStores;
use OCP\BackgroundJob\IJobList;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use Throwable;

/**
 * Golden test for WebhookService::sendRequest().
 *
 * Records the method, URI and options handed to the HTTP client (the redirect
 * guard is exercised on a public and a private target), and the result, per case,
 * in fixtures/webhook-send-request-golden.json. Set UPDATE_GOLDEN=1 to rewrite it.
 *
 * @covers \OCA\OpenRegister\Service\WebhookService
 * @covers \OCA\OpenRegister\Service\WebhookRequestOptions
 * @uses   \OCA\OpenRegister\Db\Webhook
 * @uses   \OCA\OpenRegister\Service\Security\OutboundAddressGuard
 * @uses   \OCA\OpenRegister\Service\WebhookDeliveryRecorder
 */
class WebhookServiceSendRequestGoldenTest extends TestCase {

	public static function cases(): array {
		return [
			'post plain' => [[]],
			'get' => [['method' => 'get']],
			'secret and headers' => [['secret' => 's3cret', 'headers' => ['X-A' => '1', 'User-Agent' => 'Mine']]],
			'cap above timeout' => [['timeout' => 5, 'cap' => 10]],
			'cap below timeout' => [['timeout' => 30, 'cap' => 10]],
			'cap with zero timeout' => [['timeout' => 0, 'cap' => 10]],
			'cap equal timeout' => [['timeout' => 10, 'cap' => 10]],
			'private target refused' => [['url' => 'http://10.0.0.5/hook']],
			'private target allowed' => [['url' => 'http://10.0.0.5/hook', 'allowPrivate' => true]],
			'long body' => [['body' => str_repeat('y', 1500), 'status' => 500]],
		];
	}//end cases()

	#[DataProvider('cases')]
	public function testSendRequestMatchesGolden(array $case): void {
		$service = new WebhookService(
			stores: new WebhookStores(
				webhookMapper: $this->createMock(WebhookMapper::class),
				webhookLogMapper: $this->createMock(WebhookLogMapper::class),
				mappingMapper: $this->createMock(MappingMapper::class),
				mappingService: $this->createMock(MappingService::class)
			),
			dispatch: new WebhookDispatchServices(
				jobList: $this->createMock(IJobList::class)
			),
			logger: $this->createMock(LoggerInterface::class)
		);
		$seen = [];
		$client = $this->createMock(GuzzleClient::class);
		$client->method('request')->willReturnCallback(
			function (string $method, $uri, array $options) use (&$seen, $case): GuzzleResponse {
				$redirect = $options['allow_redirects'];
				$guard = $redirect['on_redirect'];
				unset($redirect['on_redirect']);
				$options['allow_redirects'] = $redirect;
				$verdicts = [];
				foreach (['https://93.184.216.34/next', 'http://127.0.0.1/next'] as $target) {
					try {
						$guard(new GuzzleRequest('GET', $target), new GuzzleResponse(302), new Uri($target));
						$verdicts[$target] = 'allowed';
					} catch (Throwable $e) {
						$verdicts[$target] = get_class($e);
					}
				}

				$seen = ['method' => $method, 'uri' => (string)$uri, 'options' => $options, 'redirects' => $verdicts];
				return new GuzzleResponse($case['status'] ?? 200, [], $case['body'] ?? 'ok');
			}
		);
		$property = (new \ReflectionClass($service))->getProperty('client');
		$property->setAccessible(true);
		$property->setValue($service, $client);

		$webhook = new Webhook();
		$webhook->setUrl($case['url'] ?? 'https://93.184.216.34/hook');
		$webhook->setMethod($case['method'] ?? 'POST');
		$webhook->setTimeout($case['timeout'] ?? 30);
		if (isset($case['secret']) === true) {
			$webhook->setSecret($case['secret']);
		}

		if (isset($case['headers']) === true) {
			$webhook->setHeaders(json_encode($case['headers']));
		}

		if (isset($case['allowPrivate']) === true) {
			$webhook->setConfigurationArray(['allowPrivateTargets' => true]);
		}

		try {
			$result = (new ReflectionMethod(WebhookService::class, 'sendRequest'))->invoke($service, new \OCA\OpenRegister\Service\WebhookDelivery(webhook: $webhook, event: new \OCA\OpenRegister\Service\WebhookEvent(name: 'Golden\\Event', payload: []), timeoutCapSeconds: $case['cap'] ?? null), ['kind' => 'thing', 'id' => 5]);
			$actual = ['result' => $result, 'sent' => $seen];
		} catch (Throwable $e) {
			$actual = ['error' => [get_class($e), $e->getMessage()], 'sent' => $seen];
		}

		$actual = json_decode((string)json_encode($actual, JSON_UNESCAPED_SLASHES), true);
		$file = __DIR__ . '/fixtures/webhook-send-request-golden.json';
		$name = $this->dataName();
		$golden = is_file($file) === true ? json_decode((string)file_get_contents($file), true) : [];
		if (getenv('UPDATE_GOLDEN') === '1') {
			$golden[$name] = $actual;
			ksort($golden);
			file_put_contents($file, json_encode($golden, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
		}

		$this->assertSame($golden[$name], $actual);
	}//end testSendRequestMatchesGolden()
}//end class
