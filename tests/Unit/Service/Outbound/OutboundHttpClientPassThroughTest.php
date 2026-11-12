<?php

/**
 * The outbound client's pass-throughs: an unreadable proxy setting leaves
 * the request options as given, and a built PSR-7 request and a thrown
 * error go to the decorated client unchanged.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Outbound
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Outbound;

use OCA\OpenRegister\Service\Outbound\OutboundHttpClient;
use OCA\OpenRegister\Service\Outbound\ProxySettingsInterface;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IResponse;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;

/**
 * Pass-through tests for {@see OutboundHttpClient}.
 */
class OutboundHttpClientPassThroughTest extends TestCase {

	/**
	 * A proxy setting that cannot be read leaves the options untouched, and
	 * the request still goes out with them.
	 *
	 * @return void
	 */
	public function testAnUnreadableProxySettingLeavesTheOptionsAsGiven(): void {
		$proxy = $this->createMock(ProxySettingsInterface::class);
		$proxy->method('requestOptions')->willThrowException(new RuntimeException('settings unreadable'));
		$response = $this->createMock(IResponse::class);
		$inner = $this->createMock(IClient::class);
		$inner->expects($this->once())->method('get')->with('https://example.org', ['timeout' => 5])->willReturn($response);
		$client = new OutboundHttpClient(inner: $inner, proxy: $proxy);

		$this->assertSame(['timeout' => 5], $client->withProxy(options: ['timeout' => 5]));
		$this->assertSame($response, $client->get(uri: 'https://example.org', options: ['timeout' => 5]));
	}//end testAnUnreadableProxySettingLeavesTheOptionsAsGiven()

	/**
	 * Skip when the Nextcloud under test publishes an IClient without the
	 * named method (older servers): there is no inner method to forward to.
	 *
	 * @param string $method The IClient method.
	 *
	 * @return void
	 */
	private function requireClientMethod(string $method): void {
		if (method_exists(IClient::class, $method) === false) {
			$this->markTestSkipped('This Nextcloud\'s IClient has no ' . $method . '().');
		}
	}//end requireClientMethod()

	/**
	 * A built request is forwarded unchanged, and the proxy setting is not
	 * consulted for it.
	 *
	 * @return void
	 */
	public function testABuiltRequestIsForwardedUnchanged(): void {
		$this->requireClientMethod(method: 'sendRequest');
		$proxy = $this->createMock(ProxySettingsInterface::class);
		$proxy->expects($this->never())->method('requestOptions');
		$request = $this->createMock(RequestInterface::class);
		$psrResponse = $this->createMock(ResponseInterface::class);
		$inner = $this->createMock(IClient::class);
		$inner->expects($this->once())->method('sendRequest')->with($this->identicalTo($request))->willReturn($psrResponse);
		$client = new OutboundHttpClient(inner: $inner, proxy: $proxy);

		$this->assertSame($psrResponse, $client->sendRequest(request: $request));
	}//end testABuiltRequestIsForwardedUnchanged()

	/**
	 * A thrown error is mapped by the decorated client, and the proxy
	 * setting is not consulted for it.
	 *
	 * @return void
	 */
	public function testAThrowableIsMappedByTheDecoratedClient(): void {
		$this->requireClientMethod(method: 'getResponseFromThrowable');
		$proxy = $this->createMock(ProxySettingsInterface::class);
		$proxy->expects($this->never())->method('requestOptions');
		$mapped = $this->createMock(IResponse::class);
		$error = new RuntimeException('refused');
		$inner = $this->createMock(IClient::class);
		$inner->expects($this->once())->method('getResponseFromThrowable')->with($this->identicalTo($error))->willReturn($mapped);
		$client = new OutboundHttpClient(inner: $inner, proxy: $proxy);

		$this->assertSame($mapped, $client->getResponseFromThrowable(e: $error));
	}//end testAThrowableIsMappedByTheDecoratedClient()
}//end class
