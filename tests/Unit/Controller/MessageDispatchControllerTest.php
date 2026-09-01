<?php

/**
 * Contract tests for {@see \OCA\OpenRegister\Controller\MessageDispatchController}.
 *
 * Covers both outbound-messaging send endpoints: the per-channel source
 * allow-list (an SMS source may not be posted to the WhatsApp endpoint and vice
 * versa), the required `source`/`path` 400, the 200 relay of the provider
 * response, and the AD-23 degraded relay (`503` carrying `details.cause`).
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/messaging-dispatch-leaf/specs/integration-message-dispatch/spec.md
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Controller;

use OCA\OpenRegister\Controller\MessageDispatchController;
use OCA\OpenRegister\Service\Integration\Providers\MessageDispatchProvider;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * MessageDispatchControllerTest.
 */
class MessageDispatchControllerTest extends TestCase {

	/**
	 * HTTP request mock.
	 *
	 * @var IRequest&MockObject
	 */
	private IRequest&MockObject $request;

	/**
	 * Outbound-messaging dispatch leaf mock.
	 *
	 * @var MessageDispatchProvider&MockObject
	 */
	private MessageDispatchProvider&MockObject $provider;

	/**
	 * Controller under test.
	 *
	 * @var MessageDispatchController
	 */
	private MessageDispatchController $controller;

	protected function setUp(): void {
		parent::setUp();

		$this->request = $this->createMock(IRequest::class);
		$this->provider = $this->createMock(MessageDispatchProvider::class);

		$this->controller = new MessageDispatchController(
			'openregister',
			$this->request,
			$this->provider
		);
	}//end setUp()

	/**
	 * Stub the request params from a simple map.
	 *
	 * @param array<string,mixed> $params The params the request should answer.
	 *
	 * @return void
	 */
	private function withParams(array $params): void {
		$this->request->method('getParam')->willReturnCallback(
			static function (string $key, mixed $default = null) use ($params): mixed {
				return ($params[$key] ?? $default);
			}
		);
	}//end withParams()

	public function testSmsSendDispatchesThroughTheNamedSourceAndRelaysTheResponse(): void {
		$this->withParams(
			[
				'source' => 'messagebird-sms',
				'path' => '/messages',
				'body' => ['to' => '+31600000000', 'body' => 'hi'],
				'headers' => ['X-Trace' => 'abc', 'ignored' => ['not', 'scalar']],
			]
		);

		$this->provider->expects($this->once())
			->method('dispatch')
			->with('messagebird-sms', ['to' => '+31600000000', 'body' => 'hi'], '/messages', ['X-Trace' => 'abc'])
			->willReturn(['status' => 'sent', 'source' => 'messagebird-sms', 'response' => ['id' => 'm-1']]);

		$response = $this->controller->smsSend();

		$this->assertInstanceOf(JSONResponse::class, $response);
		$this->assertSame(200, $response->getStatus());
		$this->assertSame('sent', $response->getData()['status']);
		$this->assertSame('m-1', $response->getData()['response']['id']);
	}//end testSmsSendDispatchesThroughTheNamedSourceAndRelaysTheResponse()

	public function testSmsSendRejectsAMissingPathWith400(): void {
		$this->withParams(['source' => 'twilio-sms']);
		$this->provider->expects($this->never())->method('dispatch');

		$response = $this->controller->smsSend();

		$this->assertSame(400, $response->getStatus());
		$this->assertSame('source and path are required', $response->getData()['error']);
	}//end testSmsSendRejectsAMissingPathWith400()

	public function testSmsSendRefusesAWhatsAppSourceOnTheSmsChannel(): void {
		$this->withParams(['source' => 'whatsapp-cloud-api', 'path' => '/messages', 'body' => []]);
		$this->provider->expects($this->never())->method('dispatch');

		$response = $this->controller->smsSend();

		$this->assertSame(400, $response->getStatus());
		$this->assertStringContainsString('not valid for this channel', $response->getData()['error']);
	}//end testSmsSendRefusesAWhatsAppSourceOnTheSmsChannel()

	public function testWhatsappSendDispatchesThroughTheNamedSource(): void {
		$this->withParams(
			[
				'source' => 'whatsapp-cloud-api',
				'path' => '/1234/messages',
				'body' => ['messaging_product' => 'whatsapp'],
			]
		);

		$this->provider->expects($this->once())
			->method('dispatch')
			->with('whatsapp-cloud-api', ['messaging_product' => 'whatsapp'], '/1234/messages', [])
			->willReturn(['status' => 'sent', 'source' => 'whatsapp-cloud-api', 'response' => []]);

		$response = $this->controller->whatsappSend();

		$this->assertSame(200, $response->getStatus());
		$this->assertSame('whatsapp-cloud-api', $response->getData()['source']);
	}//end testWhatsappSendDispatchesThroughTheNamedSource()

	public function testWhatsappSendRefusesAnSmsSourceOnTheWhatsAppChannel(): void {
		$this->withParams(['source' => 'cmcom-sms', 'path' => '/messages']);
		$this->provider->expects($this->never())->method('dispatch');

		$response = $this->controller->whatsappSend();

		$this->assertSame(400, $response->getStatus());
	}//end testWhatsappSendRefusesAnSmsSourceOnTheWhatsAppChannel()

	public function testWhatsappSendRelaysADegradedSourceAs503WithCause(): void {
		$this->withParams(['source' => 'whatsapp-bsp', 'path' => '/send', 'body' => []]);

		$this->provider->method('dispatch')->willReturn(
			['unavailable' => true, 'cause' => 'openconnector-down']
		);

		$response = $this->controller->whatsappSend();

		$this->assertSame(503, $response->getStatus());
		$this->assertSame('openconnector-down', $response->getData()['details']['cause']);
	}//end testWhatsappSendRelaysADegradedSourceAs503WithCause()
}//end class
