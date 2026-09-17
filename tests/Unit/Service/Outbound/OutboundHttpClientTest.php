<?php

/**
 * OutboundHttpClientTest — every method carries the proxy, and the one that
 * cannot says so.
 *
 * The assertion worth having is the exhaustive one: a decorator is only a
 * guarantee if it covers every method, and the method somebody forgets to
 * decorate is indistinguishable from one that works, right up until a gemeente
 * with no direct egress uses it.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Outbound
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @spec openspec/changes/api-as-a-versioned-surface/specs/api-surface-governance/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Outbound;

use OCA\OpenRegister\Service\Outbound\OutboundClientFactory;
use OCA\OpenRegister\Service\Outbound\OutboundHttpClient;
use OCA\OpenRegister\Service\Outbound\ProxySettings;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IPromise;
use OCP\Http\Client\IResponse;
use OCP\IAppConfig;
use OCP\IConfig;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * @covers \OCA\OpenRegister\Service\Outbound\OutboundHttpClient
 * @covers \OCA\OpenRegister\Service\Outbound\OutboundClientFactory
 */
class OutboundHttpClientTest extends TestCase {

	/**
	 * The proxy settings a gemeente behind a proxy would have.
	 *
	 * @param string $proxy The administered proxy, or the empty string.
	 *
	 * @return ProxySettings The settings.
	 */
	private function proxySettings(string $proxy = 'http://proxy.test:3128'): ProxySettings {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static fn (string $a, string $key, string $default = ''): string
				=> (($key === ProxySettings::PROXY_KEY) ? $proxy : $default)
		);

		$config = $this->createMock(IConfig::class);
		$config->method('getSystemValue')->willReturnCallback(
			static fn (string $key, mixed $default = '') => $default
		);

		return new ProxySettings($appConfig, $config);
	}//end proxySettings()

	public function testEverySynchronousMethodCarriesTheProxy(): void {
		foreach (['get', 'head', 'post', 'put', 'patch', 'delete', 'options'] as $method) {
			$inner = $this->createMock(IClient::class);
			$inner->expects($this->once())
				->method($method)
				->with(
					$this->equalTo('https://example.test/'),
					$this->callback(
						static fn (array $options): bool
							=> (($options['proxy'] ?? null) === 'http://proxy.test:3128')
					)
				)
				->willReturn($this->createMock(IResponse::class));

			$client = new OutboundHttpClient($inner, $this->proxySettings());
			$client->{$method}('https://example.test/');
		}
	}//end testEverySynchronousMethodCarriesTheProxy()

	public function testEveryAsynchronousMethodCarriesTheProxy(): void {
		foreach (['getAsync', 'headAsync', 'postAsync', 'putAsync', 'deleteAsync', 'optionsAsync'] as $method) {
			$inner = $this->createMock(IClient::class);
			$inner->expects($this->once())
				->method($method)
				->with(
					$this->equalTo('https://example.test/'),
					$this->callback(
						static fn (array $options): bool
							=> (($options['proxy'] ?? null) === 'http://proxy.test:3128')
					)
				)
				->willReturn($this->createMock(IPromise::class));

			$client = new OutboundHttpClient($inner, $this->proxySettings());
			$client->{$method}('https://example.test/');
		}
	}//end testEveryAsynchronousMethodCarriesTheProxy()

	public function testRequestCarriesTheProxy(): void {
		$inner = $this->createMock(IClient::class);
		$inner->expects($this->once())
			->method('request')
			->with(
				$this->equalTo('PROPFIND'),
				$this->equalTo('https://example.test/'),
				$this->callback(static fn (array $o): bool => (($o['proxy'] ?? null) === 'http://proxy.test:3128'))
			)
			->willReturn($this->createMock(IResponse::class));

		(new OutboundHttpClient($inner, $this->proxySettings()))
			->request('PROPFIND', 'https://example.test/');
	}//end testRequestCarriesTheProxy()

	public function testNoOptionIsAddedWhenNoProxyIsAdministered(): void {
		$inner = $this->createMock(IClient::class);
		$inner->expects($this->once())
			->method('get')
			->with($this->anything(), $this->equalTo(['timeout' => 5]))
			->willReturn($this->createMock(IResponse::class));

		(new OutboundHttpClient($inner, $this->proxySettings(proxy: '')))
			->get('https://example.test/', ['timeout' => 5]);
	}//end testNoOptionIsAddedWhenNoProxyIsAdministered()

	public function testTheCallersOwnOptionsSurvive(): void {
		$decorated = (new OutboundHttpClient($this->createMock(IClient::class), $this->proxySettings()))
			->withProxy(options: ['timeout' => 5, 'headers' => ['X-Test' => '1']]);

		$this->assertSame('http://proxy.test:3128', $decorated['proxy']);
		$this->assertSame(5, $decorated['timeout']);
		$this->assertSame(['X-Test' => '1'], $decorated['headers']);
	}//end testTheCallersOwnOptionsSurvive()

	public function testACallerThatDeliberatelyOverridesTheProxyIsBelieved(): void {
		$decorated = (new OutboundHttpClient($this->createMock(IClient::class), $this->proxySettings()))
			->withProxy(options: ['proxy' => '']);

		$this->assertSame(
			'',
			$decorated['proxy'],
			'Bypassing has to stay possible, but as a deliberate greppable act rather than the default.'
		);
	}//end testACallerThatDeliberatelyOverridesTheProxyIsBelieved()

	public function testEveryClientMethodIsDecorated(): void {
		$declared = [];
		$undecorated = [];
		foreach ((new ReflectionClass(IClient::class))->getMethods() as $method) {
			$declared[] = $method->getName();
			$name = $method->getName();
			$body = (new ReflectionClass(OutboundHttpClient::class))->getMethod($name);
			$source = implode(
				'',
				array_slice(
					file($body->getFileName()),
					($body->getStartLine() - 1),
					($body->getEndLine() - $body->getStartLine() + 1)
				)
			);

			if (str_contains($source, 'withProxy') === false) {
				$undecorated[] = $name;
			}
		}

		sort($undecorated);

		// The exemptions are the two below, but only where the installed
		// IClient declares them. From Nextcloud 34 IClient extends PSR-18 and
		// carries sendRequest; on 32 and 33 it does not, so the method is not
		// part of the contract there and cannot be a hole in it. Intersecting
		// keeps the assertion exact on every major: an undecorated method that
		// is NOT one of these two still fails it.
		$exempt = array_values(array_intersect(['getResponseFromThrowable', 'sendRequest'], $declared));

		$this->assertSame(
			$exempt,
			$undecorated,
			'A decorator is a guarantee only if it covers every method. These two are exempt on purpose: '
				. 'sendRequest takes a built PSR-7 request with nowhere to put a transport option, and '
				. 'getResponseFromThrowable makes no request at all. Any OTHER name here is a hole.'
		);
	}//end testEveryClientMethodIsDecorated()

	public function testTheFactoryIsAClientServiceSoNoCallSiteHasToChange(): void {
		$inner = $this->createMock(IClientService::class);
		$inner->method('newClient')->willReturn($this->createMock(IClient::class));

		$factory = new OutboundClientFactory($inner, $this->proxySettings());

		$this->assertInstanceOf(
			IClientService::class,
			$factory,
			'Binding this under IClientService is what covers the twenty existing call sites and every future one.'
		);
		$this->assertInstanceOf(OutboundHttpClient::class, $factory->newClient());
	}//end testTheFactoryIsAClientServiceSoNoCallSiteHasToChange()
}//end class
