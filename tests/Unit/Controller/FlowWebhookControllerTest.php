<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller;

use OCA\OpenRegister\Controller\FlowWebhookController;
use OCA\OpenRegister\Controller\Support\RawRequestBody;
use OCA\OpenRegister\Db\Flow;
use OCA\OpenRegister\Db\FlowMapperInterface;
use OCA\OpenRegister\Db\FlowRun;
use OCA\OpenRegister\Service\Flow\FlowRunQueueOptions;
use OCA\OpenRegister\Service\Flow\FlowRunServiceInterface;
use OCA\OpenRegister\Service\Flow\FlowWebhookSecrets;
use OCP\AppFramework\Http;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * The public address of an incoming-webhook trigger: a signed call queues one
 * run; every refusal answers the same 404 and queues nothing.
 *
 * @spec openspec/changes/flow-calendar-and-webhook-triggers/specs/flow-engine/spec.md#requirement-an-incoming-webhook-trigger-starts-a-flow-from-a-signed-call-by-another-system
 */
class FlowWebhookControllerTest extends TestCase {

	private const TOKEN = 'abababababababababababababababab';

	private const SECRET = 'the-trigger-secret';

	private const BODY = '{"order": "A-1042", "status": "paid"}';

	/**
	 * Request headers.
	 *
	 * @var array<string, string>
	 */
	private array $headers = [];

	/**
	 * In-memory distributed cache.
	 *
	 * @var array<string, mixed>
	 */
	private array $cache = [];

	/**
	 * @var FlowRunServiceInterface&MockObject
	 */
	private FlowRunServiceInterface&MockObject $runs;

	/**
	 * Whether the flow is enabled.
	 *
	 * @var boolean
	 */
	private bool $enabled = true;

	protected function setUp(): void {
		parent::setUp();
		$this->headers = [];
		$this->cache = [];
		$this->enabled = true;
		$this->runs = $this->createMock(FlowRunServiceInterface::class);
	}//end setUp()

	/**
	 * The controller over one trigger on flow-1, receiving a body.
	 *
	 * @param string $body The raw body.
	 *
	 * @return FlowWebhookController
	 */
	private function controller(string $body=self::BODY): FlowWebhookController {
		$request = $this->createMock(IRequest::class);
		$request->method('getHeader')->willReturnCallback(fn (string $name): string => ($this->headers[$name] ?? ''));
		$raw = $this->createMock(RawRequestBody::class);
		$raw->method('read')->willReturnCallback(static fn (int $max): string => substr($body, 0, $max));
		$secrets = $this->createMock(FlowWebhookSecrets::class);
		$secrets->method('find')->willReturnCallback(
			static fn (string $token): ?array => ($token === self::TOKEN ? ['flow' => 'flow-1', 'node' => 'hook', 'secret' => self::SECRET] : null)
		);
		$flows = $this->createMock(FlowMapperInterface::class);
		$flows->method('findByUuid')->willReturnCallback(
			function (string $uuid): Flow {
				$flow = new Flow();
				$flow->setUuid($uuid);
				$flow->setEnabled($this->enabled);
				$flow->setOwner('admin');
				$flow->setNodes([['id' => 'hook', 'type' => 'openregister.trigger-webhook', 'config' => ['runAs' => 'betalingen', 'token' => self::TOKEN]]]);
				return $flow;
			}
		);
		$cache = $this->createMock(ICache::class);
		$cache->method('get')->willReturnCallback(fn (string $key): mixed => ($this->cache[$key] ?? null));
		$cache->method('set')->willReturnCallback(
			function (string $key, mixed $value, int $ttl=0): bool {
				$this->cache[$key] = $value;
				return true;
			}
		);
		$caches = $this->createMock(ICacheFactory::class);
		$caches->method('createDistributed')->willReturn($cache);

		return new FlowWebhookController('openregister', $request, $raw, $secrets, $flows, $this->runs, $caches, new NullLogger());
	}//end controller()

	/**
	 * Sign a body as the caller does.
	 *
	 * @param string $body   The body.
	 * @param string $secret The secret.
	 *
	 * @return void
	 */
	private function sign(string $body=self::BODY, string $secret=self::SECRET): void {
		$this->headers['X-OpenRegister-Signature'] = 'sha256=' . hash_hmac('sha256', $body, $secret);
	}//end sign()

	/**
	 * A queued run.
	 *
	 * @param string $uuid Its id.
	 *
	 * @return FlowRun
	 */
	private static function queued(string $uuid): FlowRun {
		$run = new FlowRun();
		$run->setUuid($uuid);

		return $run;
	}//end queued()

	public function testASignedCallQueuesOneRunWithTheBodyAsInput(): void {
		$this->sign();
		$this->runs->expects($this->once())->method('queueRun')->with(
			'flow-1',
			$this->callback(static fn (FlowRunQueueOptions $o): bool => $o->trigger === 'webhook' && $o->context['payload'] === ['order' => 'A-1042', 'status' => 'paid'])
		)->willReturn(self::queued(uuid: 'R1'));

		$response = $this->controller()->receive(token: self::TOKEN);

		$this->assertSame(Http::STATUS_ACCEPTED, $response->getStatus());
		$this->assertSame(['runId' => 'R1'], $response->getData());
	}//end testASignedCallQueuesOneRunWithTheBodyAsInput()

	public function testAWrongSignatureIsTheSameNotFoundAsAnUnknownAddress(): void {
		$this->sign(secret: 'another-secret');
		$this->runs->expects($this->never())->method('queueRun');

		$wrong = $this->controller()->receive(token: self::TOKEN);
		$unknown = $this->controller()->receive(token: str_repeat('cd', 16));

		$this->assertSame(Http::STATUS_NOT_FOUND, $wrong->getStatus());
		$this->assertSame([$wrong->getStatus(), $wrong->getData()], [$unknown->getStatus(), $unknown->getData()]);
	}//end testAWrongSignatureIsTheSameNotFoundAsAnUnknownAddress()

	public function testAMissingSignatureIsRefused(): void {
		$this->runs->expects($this->never())->method('queueRun');

		$this->assertSame(Http::STATUS_NOT_FOUND, $this->controller()->receive(token: self::TOKEN)->getStatus());
	}//end testAMissingSignatureIsRefused()

	public function testADisabledFlowRefusesACorrectlySignedCall(): void {
		$this->enabled = false;
		$this->sign();
		$this->runs->expects($this->never())->method('queueRun');

		$this->assertSame(Http::STATUS_NOT_FOUND, $this->controller()->receive(token: self::TOKEN)->getStatus());
	}//end testADisabledFlowRefusesACorrectlySignedCall()

	public function testARepeatedDeliveryReturnsTheFirstRun(): void {
		$this->sign();
		$this->headers['X-Delivery-Id'] = '7f3a';
		$this->runs->expects($this->once())->method('queueRun')->willReturn(self::queued(uuid: 'R1'));

		$first = $this->controller()->receive(token: self::TOKEN);
		$retry = $this->controller()->receive(token: self::TOKEN);

		$this->assertSame(['runId' => 'R1'], $first->getData());
		$this->assertSame(Http::STATUS_ACCEPTED, $retry->getStatus());
		$this->assertSame(['runId' => 'R1'], $retry->getData());
	}//end testARepeatedDeliveryReturnsTheFirstRun()

	public function testABodyOverOneMegabyteIsRefused(): void {
		$body = '"' . str_repeat('x', FlowWebhookController::MAX_BODY) . '"';
		$this->sign(body: $body);
		$this->runs->expects($this->never())->method('queueRun');

		$this->assertSame(Http::STATUS_REQUEST_ENTITY_TOO_LARGE, $this->controller(body: $body)->receive(token: self::TOKEN)->getStatus());
	}//end testABodyOverOneMegabyteIsRefused()

	public function testASignedBodyThatIsNotJsonIsRefused(): void {
		$this->sign(body: 'not json');
		$this->runs->expects($this->never())->method('queueRun');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $this->controller(body: 'not json')->receive(token: self::TOKEN)->getStatus());
	}//end testASignedBodyThatIsNotJsonIsRefused()

	public function testARefusedRunAnswersNotFound(): void {
		$this->sign();
		$this->runs->method('queueRun')->willThrowException(new RuntimeException('no acting identity'));

		$this->assertSame(Http::STATUS_NOT_FOUND, $this->controller()->receive(token: self::TOKEN)->getStatus());
	}//end testARefusedRunAnswersNotFound()
}//end class
