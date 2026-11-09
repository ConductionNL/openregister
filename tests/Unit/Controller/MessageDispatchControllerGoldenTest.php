<?php

/**
 * MessageDispatchControllerGoldenTest
 *
 * Golden test pinning the send relay's input coercion and response bodies,
 * alongside MessageDispatchControllerTest.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Controller
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/integration-message-dispatch/spec.md
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Controller;

use OCA\OpenRegister\Controller\MessageDispatchController;
use OCA\OpenRegister\Service\Integration\Providers\MessageDispatchProvider;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * MessageDispatchControllerGoldenTest.
 */
class MessageDispatchControllerGoldenTest extends TestCase {

	private IRequest&MockObject $request;

	private MessageDispatchProvider&MockObject $provider;

	private MessageDispatchController $controller;

	protected function setUp(): void {
		parent::setUp();
		$this->request = $this->createMock(IRequest::class);
		$this->provider = $this->createMock(MessageDispatchProvider::class);
		$this->controller = new MessageDispatchController('openregister', $this->request, $this->provider);
	}

	private function withParams(array $params): void {
		$this->request->method('getParam')->willReturnCallback(
			static function (string $key, mixed $default = null) use ($params): mixed {
				return ($params[$key] ?? $default);
			}
		);
	}

	public function testTrimsSourceAndPathAndCoercesBodyAndHeaders(): void {
		$this->withParams(
			[
				'source' => '  twilio-sms ',
				'path' => ' /2010-04-01/Messages.json ',
				'body' => 'not-an-array',
				'headers' => [7 => 42, 'X-Flag' => true, 'X-Off' => false, 'X-Null' => null, 'X-F' => 1.5],
			]
		);
		$result = ['status' => 'sent', 'source' => 'twilio-sms', 'response' => ['sid' => 'S1']];
		$this->provider->expects($this->once())
			->method('dispatch')
			->with('twilio-sms', [], '/2010-04-01/Messages.json', ['7' => '42', 'X-Flag' => '1', 'X-Off' => '', 'X-F' => '1.5'])
			->willReturn($result);

		$response = $this->controller->smsSend();

		$this->assertSame(200, $response->getStatus());
		$this->assertSame($result, $response->getData());
	}

	public function testNonArrayHeadersBecomeEmpty(): void {
		$this->withParams(['source' => 'whatsapp-bsp', 'path' => '/send', 'body' => ['a' => 1], 'headers' => 'x']);
		$this->provider->expects($this->once())
			->method('dispatch')
			->with('whatsapp-bsp', ['a' => 1], '/send', [])
			->willReturn(['status' => 'sent']);

		$this->assertSame(['status' => 'sent'], $this->controller->whatsappSend()->getData());
	}

	public function testBlankSourceOrPathIsA400(): void {
		$this->provider->expects($this->never())->method('dispatch');
		$this->withParams(['source' => '   ', 'path' => '/x']);

		$response = $this->controller->smsSend();

		$this->assertSame(400, $response->getStatus());
		$this->assertSame(['error' => 'source and path are required'], $response->getData());
	}

	public function testBlankPathWithAWrongSourceStillReportsTheMissingField(): void {
		$this->provider->expects($this->never())->method('dispatch');
		$this->withParams(['source' => 'nope', 'path' => '  ']);

		$response = $this->controller->whatsappSend();

		$this->assertSame(400, $response->getStatus());
		$this->assertSame(['error' => 'source and path are required'], $response->getData());
	}

	public function testWrongChannelSourceNamesTheSource(): void {
		$this->provider->expects($this->never())->method('dispatch');
		$this->withParams(['source' => ' whatsapp-bsp ', 'path' => '/x']);

		$response = $this->controller->smsSend();

		$this->assertSame(400, $response->getStatus());
		$this->assertSame(['error' => 'source "whatsapp-bsp" is not valid for this channel'], $response->getData());
	}

	public function testUnavailableSourceIsA503WithCause(): void {
		$this->withParams(['source' => 'cmcom-sms', 'path' => '/gateway']);
		$this->provider->method('dispatch')->willReturn(['unavailable' => true, 'cause' => 'source-unconfigured']);

		$response = $this->controller->smsSend();

		$this->assertSame(503, $response->getStatus());
		$this->assertSame(
			[
				'error' => 'messaging source "cmcom-sms" is not available',
				'details' => ['cause' => 'source-unconfigured'],
			],
			$response->getData()
		);
	}

	public function testATruthyButNotTrueUnavailableIsRelayedAsIs(): void {
		$this->withParams(['source' => 'cmcom-sms', 'path' => '/gateway']);
		$result = ['unavailable' => 'yes', 'cause' => 'odd'];
		$this->provider->method('dispatch')->willReturn($result);

		$response = $this->controller->smsSend();

		$this->assertSame(200, $response->getStatus());
		$this->assertSame($result, $response->getData());
	}
}
