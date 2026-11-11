<?php

/**
 * Characterisation of request interception past the fast path.
 *
 * Pins the CloudEvent the webhooks receive (basic and formatted), the
 * response-processing log after a successful delivery, the per-webhook
 * failure log and that one failure does not stop the next webhook. Every
 * target is a loopback address opted in with allowPrivateTargets, so no DNS
 * lookup is needed and the HTTP client is a double.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service;

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Psr7\Response as GuzzleResponse;
use OCA\OpenRegister\Db\MappingMapper;
use OCA\OpenRegister\Db\Webhook;
use OCA\OpenRegister\Db\WebhookLogMapper;
use OCA\OpenRegister\Db\WebhookMapper;
use OCA\OpenRegister\Notifications\Service\Webhook\CloudEventFormatterInterface;
use OCA\OpenRegister\Notifications\Service\Webhook\WebhookInterceptionCache;
use OCA\OpenRegister\Service\MappingService;
use OCA\OpenRegister\Service\WebhookDispatchServices;
use OCA\OpenRegister\Service\WebhookService;
use OCA\OpenRegister\Service\WebhookStores;
use OCP\BackgroundJob\IJobList;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * interceptRequest with matching webhooks.
 *
 * @covers \OCA\OpenRegister\Service\WebhookService
 */
class WebhookInterceptRequestCharacterisationTest extends TestCase {

	private WebhookMapper&MockObject $webhookMapper;
	private WebhookLogMapper&MockObject $webhookLogMapper;
	private LoggerInterface&MockObject $logger;
	private GuzzleClient&MockObject $client;

	/**
	 * The bodies the client was asked to send, in order.
	 *
	 * @var list<array<string, mixed>>
	 */
	private array $sent = [];

	/**
	 * Build the doubles.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->webhookMapper    = $this->createMock(WebhookMapper::class);
		$this->webhookLogMapper = $this->createMock(WebhookLogMapper::class);
		$this->logger           = $this->createMock(LoggerInterface::class);
		$this->client           = $this->createMock(GuzzleClient::class);
		$this->sent             = [];
	}//end setUp()

	/**
	 * The service, its client replaced by the double.
	 *
	 * @param CloudEventFormatterInterface|null $formatter The optional formatter.
	 *
	 * @return WebhookService The service.
	 */
	private function service(?CloudEventFormatterInterface $formatter=null): WebhookService {
		$cache = $this->createMock(WebhookInterceptionCache::class);
		$cache->method('get')->willReturn(true);

		$service = new WebhookService(
			stores: new WebhookStores(
				webhookMapper: $this->webhookMapper,
				webhookLogMapper: $this->webhookLogMapper,
				mappingMapper: $this->createMock(MappingMapper::class),
				mappingService: $this->createMock(MappingService::class)
			),
			dispatch: new WebhookDispatchServices(
				jobList: $this->createMock(IJobList::class),
				cloudEventFormatter: $formatter,
				interceptionCache: $cache
			),
			logger: $this->logger
		);

		$property = (new \ReflectionClass($service))->getProperty('client');
		$property->setValue($service, $this->client);

		return $service;
	}//end service()

	/**
	 * A request double.
	 *
	 * @return IRequest&MockObject The request.
	 */
	private function request(): IRequest&MockObject {
		$request = $this->createMock(IRequest::class);
		$request->method('getParams')->willReturn(['title' => 'x']);
		$request->method('getMethod')->willReturn('POST');
		$request->method('getPathInfo')->willReturn('/api/objects/1/2');
		return $request;
	}//end request()

	/**
	 * An interception webhook to a loopback target.
	 *
	 * @param int   $id     The id.
	 * @param array $config Extra configuration.
	 *
	 * @return Webhook The webhook.
	 */
	private function webhook(int $id, array $config=[]): Webhook {
		$webhook = new Webhook();
		$webhook->setId($id);
		$webhook->setName('hook ' . $id);
		$webhook->setUrl('http://127.0.0.1/hook' . $id);
		$webhook->setMethod('POST');
		$webhook->setEnabled(true);
		$webhook->setTimeout(30);
		$webhook->setMaxRetries(0);
		$webhook->setConfiguration(json_encode(['interceptRequests' => true, 'allowPrivateTargets' => true] + $config));
		return $webhook;
	}//end webhook()

	/**
	 * Record what the client is asked to send and answer each call in turn.
	 *
	 * @param list<GuzzleResponse|\Exception> $answers The answers.
	 *
	 * @return void
	 */
	private function clientAnswers(array $answers): void {
		$this->client->method('request')->willReturnCallback(
			function (string $method, string $url, array $options) use (&$answers): GuzzleResponse {
				$this->sent[] = ['url' => $url, 'body' => ($options['json'] ?? $options['body'] ?? null)];
				$answer = array_shift($answers);
				if ($answer instanceof \Exception) {
					throw $answer;
				}

				return $answer;
			}
		);
	}//end clientAnswers()

	/**
	 * A successful delivery with processResponse logs that processing is not implemented; the params come back.
	 *
	 * @return void
	 */
	public function testASuccessfulDeliveryThatWantsItsResponseIsLogged(): void {
		$this->webhookMapper->method('findEnabled')->willReturn([$this->webhook(1, ['processResponse' => true])]);
		$this->clientAnswers([new GuzzleResponse(200, [], '{}')]);
		$infos = [];
		$this->logger->method('info')->willReturnCallback(
			static function (string $message, array $context=[]) use (&$infos): void {
				$infos[] = [$message, $context];
			}
		);

		$result = $this->service()->interceptRequest($this->request(), 'object.creating');

		$this->assertSame(['title' => 'x'], $result);
		$this->assertCount(1, $this->sent);
		$pending = array_values(array_filter($infos, static fn (array $i): bool => str_contains($i[0], 'response processing not yet implemented')));
		$this->assertCount(1, $pending);
		$this->assertSame(1, $pending[0][1]['webhook_id']);
		$this->assertSame('object.creating', $pending[0][1]['event_type']);
	}//end testASuccessfulDeliveryThatWantsItsResponseIsLogged()

	/**
	 * Without processResponse, or with async, or after a failed delivery, nothing about processing is logged.
	 *
	 * @return array<string, array{0: array<string, mixed>, 1: int}> Config and status.
	 */
	public static function noProcessing(): array {
		return [
			'not asked'      => [[], 200],
			'async'          => [['processResponse' => true, 'async' => true], 200],
			'delivery fails' => [['processResponse' => true], 500],
		];
	}//end noProcessing()

	/**
	 * No response-processing log.
	 *
	 * @param array<string, mixed> $config The extra configuration.
	 * @param int                  $status The target's answer.
	 *
	 * @dataProvider noProcessing
	 *
	 * @return void
	 */
	public function testNoProcessingLog(array $config, int $status): void {
		$this->webhookMapper->method('findEnabled')->willReturn([$this->webhook(1, $config)]);
		$this->clientAnswers([new GuzzleResponse($status, [], '{}')]);
		$this->logger->method('info')->willReturnCallback(
			function (string $message): void {
				$this->assertStringNotContainsString('response processing not yet implemented', $message);
			}
		);

		$this->assertSame(['title' => 'x'], $this->service()->interceptRequest($this->request(), 'object.creating'));
	}//end testNoProcessingLog()

	/**
	 * Without a formatter the webhook receives the basic request event; with one, the formatted event.
	 *
	 * @return void
	 */
	public function testTheCloudEventSent(): void {
		$this->webhookMapper->method('findEnabled')->willReturn([$this->webhook(1)]);
		$this->clientAnswers([new GuzzleResponse(200, [], '{}'), new GuzzleResponse(200, [], '{}')]);

		$this->service()->interceptRequest($this->request(), 'object.creating');

		$formatter = $this->createMock(CloudEventFormatterInterface::class);
		$formatter->expects($this->once())->method('formatRequestAsCloudEvent')->willReturn(['specversion' => '1.0', 'id' => 'e1']);
		$this->service($formatter)->interceptRequest($this->request(), 'object.creating');

		$this->assertCount(2, $this->sent);
		$this->assertSame(
			['type' => 'object.creating', 'method' => 'POST', 'path' => '/api/objects/1/2', 'body' => ['title' => 'x']],
			$this->findCloudEvent($this->sent[0]['body'])
		);
		$this->assertSame(['specversion' => '1.0', 'id' => 'e1'], $this->findCloudEvent($this->sent[1]['body']));
	}//end testTheCloudEventSent()

	/**
	 * A webhook whose delivery throws is logged as an error and the next webhook is still delivered.
	 *
	 * @return void
	 */
	public function testAThrowingDeliveryIsLoggedAndTheNextOneRuns(): void {
		// The first webhook asks for a CloudEvents payload and the formatter throws on it,
		// before the delivery's own failure handling starts.
		$this->webhookMapper->method('findEnabled')->willReturn([$this->webhook(1, ['useCloudEvents' => true]), $this->webhook(2)]);
		$formatter = $this->createMock(CloudEventFormatterInterface::class);
		$formatter->method('formatRequestAsCloudEvent')->willReturn(['id' => 'e1']);
		$formatter->method('formatAsCloudEvent')->willThrowException(new \Exception('statistics store down'));
		$this->clientAnswers([new GuzzleResponse(200, [], '{}')]);
		$errors = [];
		$this->logger->method('error')->willReturnCallback(
			static function (string $message, array $context=[]) use (&$errors): void {
				$errors[] = [$message, $context];
			}
		);

		$result = $this->service($formatter)->interceptRequest($this->request(), 'object.creating');

		$this->assertSame(['title' => 'x'], $result);
		$this->assertSame(['http://127.0.0.1/hook2'], array_column($this->sent, 'url'));
		$failures = array_values(array_filter($errors, static fn (array $e): bool => $e[0] === '[WebhookService] Failed to deliver webhook during request interception'));
		$this->assertCount(1, $failures);
		$context = $failures[0][1];
		unset($context['line']);
		$this->assertSame(
			['file' => $context['file'], 'webhook_id' => 1, 'webhook_name' => 'hook 1', 'event_type' => 'object.creating', 'error' => 'statistics store down'],
			$context
		);
	}//end testAThrowingDeliveryIsLoggedAndTheNextOneRuns()

	/**
	 * Filter configurations, a payload, and whether it passes.
	 *
	 * @return array<string, array{0: array<string, mixed>, 1: bool}> The cases.
	 */
	public static function filterCases(): array {
		return [
			'no filters'              => [[], true],
			'scalar match'            => [['action' => 'create'], true],
			'scalar mismatch'         => [['action' => 'update'], false],
			'strict scalar compare'   => [['count' => '3'], false],
			'nested dot match'        => [['object.schema' => 7], true],
			'list contains'           => [['action' => ['update', 'create']], true],
			'list contains, loose'    => [['count' => ['3']], true],
			'list misses'             => [['action' => ['update']], false],
			'missing key, null value' => [['absent' => null], true],
			'second filter fails'     => [['action' => 'create', 'object.schema' => 8], false],
		];
	}//end filterCases()

	/**
	 * passesFilters, as pinned.
	 *
	 * @param array<string, mixed> $filters  The webhook filters.
	 * @param bool                 $expected Whether the payload passes.
	 *
	 * @dataProvider filterCases
	 *
	 * @return void
	 */
	public function testPassesFilters(array $filters, bool $expected): void {
		$webhook = $this->webhook(1);
		$webhook->setFilters(json_encode($filters));
		$payload = ['action' => 'create', 'count' => 3, 'object' => ['schema' => 7]];

		$method = new \ReflectionMethod(WebhookService::class, 'passesFilters');

		$this->assertSame($expected, $method->invoke($this->service(), $webhook, $payload));
	}//end testPassesFilters()

	/**
	 * The cloudEvent somewhere in a sent body.
	 *
	 * @param mixed $body The sent body.
	 *
	 * @return mixed The cloudEvent, or null.
	 */
	private function findCloudEvent(mixed $body): mixed {
		if (is_string($body) === true) {
			$body = json_decode($body, true);
		}

		if (is_array($body) === false) {
			return null;
		}

		if (array_key_exists('cloudEvent', $body) === true) {
			return $body['cloudEvent'];
		}

		foreach ($body as $value) {
			$found = $this->findCloudEvent($value);
			if ($found !== null) {
				return $found;
			}
		}

		return null;
	}//end findCloudEvent()
}//end class
