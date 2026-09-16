<?php

/**
 * The only way this app reaches the network.
 *
 * WHY A DECORATOR AND NOT A HELPER. A helper that returns proxy options has to
 * be remembered at every call site, and this app has sixteen files that make
 * outbound calls across roughly forty call sites. The one somebody forgets is
 * the one that works on a laptop with direct egress and fails in a gemeente
 * that has none, which is precisely the failure design D-6 describes.
 *
 * A decorator cannot be forgotten. Every method on `IClient` passes through
 * here, the proxy options are merged in on the way, and the only thing a call
 * site has to do is ask {@see OutboundClientFactory} for its client instead of
 * asking `IClientService`. That is a substitution a `git grep` can verify,
 * which "did you remember the options" is not.
 *
 * 🔑 THE CALLER'S OWN OPTIONS WIN. The merge is proxy-first, caller-second, so
 * a call site that genuinely needs to bypass the proxy for one request can say
 * so and be believed. That is not a hole: the point of the decorator is that
 * bypassing becomes a deliberate, greppable act rather than the default
 * somebody fell into.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Outbound
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/api-as-a-versioned-surface/specs/api-surface-governance/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Outbound;

use OCP\Http\Client\IClient;
use OCP\Http\Client\IPromise;
use OCP\Http\Client\IResponse;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Throwable;

/**
 * An `IClient` that carries the administered proxy on every request.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Outbound
 */
class OutboundHttpClient implements IClient {

	/**
	 * Constructor.
	 *
	 * @param IClient $inner The client this one decorates.
	 * @param ProxySettings $proxy The one administered proxy setting.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly IClient $inner,
		private readonly ProxySettings $proxy,
	) {

	}//end __construct()

	/**
	 * Merge the proxy options under the caller's own.
	 *
	 * @param array<string, mixed> $options The caller's options.
	 *
	 * @return array<string, mixed> The options the request is made with.
	 *
	 * @spec openspec/changes/api-as-a-versioned-surface/specs/api-surface-governance/spec.md#requirement-the-instance-answers-the-well-known-paths-and-honours-an-administered-proxy-req-avs-004
	 */
	public function withProxy(array $options): array {
		try {
			$proxyOptions = $this->proxy->requestOptions();
		} catch (Throwable) {
			// A proxy setting that cannot be read is not a reason to refuse to
			// make the call; it is a reason to make it the way this app made
			// calls before the setting existed.
			return $options;
		}

		if ($proxyOptions === []) {
			return $options;
		}

		return array_merge($proxyOptions, $options);

	}//end withProxy()

	/**
	 * @inheritDoc
	 */
	public function get(string $uri, array $options = []): IResponse {
		return $this->inner->get($uri, $this->withProxy(options: $options));

	}//end get()

	/**
	 * @inheritDoc
	 */
	public function head(string $uri, array $options = []): IResponse {
		return $this->inner->head($uri, $this->withProxy(options: $options));

	}//end head()

	/**
	 * @inheritDoc
	 */
	public function post(string $uri, array $options = []): IResponse {
		return $this->inner->post($uri, $this->withProxy(options: $options));

	}//end post()

	/**
	 * @inheritDoc
	 */
	public function put(string $uri, array $options = []): IResponse {
		return $this->inner->put($uri, $this->withProxy(options: $options));

	}//end put()

	/**
	 * @inheritDoc
	 */
	public function patch(string $uri, array $options = []): IResponse {
		return $this->inner->patch($uri, $this->withProxy(options: $options));

	}//end patch()

	/**
	 * @inheritDoc
	 */
	public function delete(string $uri, array $options = []): IResponse {
		return $this->inner->delete($uri, $this->withProxy(options: $options));

	}//end delete()

	/**
	 * @inheritDoc
	 */
	public function options(string $uri, array $options = []): IResponse {
		return $this->inner->options($uri, $this->withProxy(options: $options));

	}//end options()

	/**
	 * @inheritDoc
	 */
	public function request(string $method, string $uri, array $options = []): IResponse {
		return $this->inner->request($method, $uri, $this->withProxy(options: $options));

	}//end request()

	/**
	 * @inheritDoc
	 */
	public function getAsync(string $uri, array $options = []): IPromise {
		return $this->inner->getAsync($uri, $this->withProxy(options: $options));

	}//end getAsync()

	/**
	 * @inheritDoc
	 */
	public function headAsync(string $uri, array $options = []): IPromise {
		return $this->inner->headAsync($uri, $this->withProxy(options: $options));

	}//end headAsync()

	/**
	 * @inheritDoc
	 */
	public function postAsync(string $uri, array $options = []): IPromise {
		return $this->inner->postAsync($uri, $this->withProxy(options: $options));

	}//end postAsync()

	/**
	 * @inheritDoc
	 */
	public function putAsync(string $uri, array $options = []): IPromise {
		return $this->inner->putAsync($uri, $this->withProxy(options: $options));

	}//end putAsync()

	/**
	 * @inheritDoc
	 */
	public function deleteAsync(string $uri, array $options = []): IPromise {
		return $this->inner->deleteAsync($uri, $this->withProxy(options: $options));

	}//end deleteAsync()

	/**
	 * @inheritDoc
	 */
	public function optionsAsync(string $uri, array $options = []): IPromise {
		return $this->inner->optionsAsync($uri, $this->withProxy(options: $options));

	}//end optionsAsync()

	/**
	 * @inheritDoc
	 *
	 * 🔴 NOT PROXIED, AND IT CANNOT BE. `sendRequest()` takes a built PSR-7
	 * request with nowhere to put a transport option, so there is no honest
	 * way to apply the proxy here. Passing it through unchanged is the truthful
	 * behaviour; silently succeeding while bypassing the proxy is what a
	 * pretend implementation would do. No call site in this app uses it.
	 */
	public function sendRequest(RequestInterface $request): ResponseInterface {
		return $this->inner->sendRequest($request);

	}//end sendRequest()

	/**
	 * @inheritDoc
	 */
	public function getResponseFromThrowable(Throwable $e): IResponse {
		return $this->inner->getResponseFromThrowable($e);

	}//end getResponseFromThrowable()
}//end class
