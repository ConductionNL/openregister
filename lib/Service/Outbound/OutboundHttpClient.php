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
 * @spec openspec/specs/api-surface-governance/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Outbound;

use GuzzleHttp\Exception\RequestException;
use OCA\OpenRegister\Service\Resilience\BreakerRegistry;
use OCA\OpenRegister\Service\Resilience\DependencyUnavailableException;
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
 *
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)
 * Reason: the count is `IClient`'s, not this class's. A decorator implements
 *         every method of the interface it decorates or it is not a decorator,
 *         and the method left out is exactly the hole this class exists to
 *         close. Splitting it to satisfy a count would mean two objects a
 *         caller has to pick between, which is the situation design D-6 names
 *         as the cause of the bug.
 */
class OutboundHttpClient implements IClient {

	/**
	 * Constructor.
	 *
	 * @param IClient $inner The client this one decorates.
	 * @param ProxySettingsInterface $proxy The one administered proxy setting.
	 * @param BreakerRegistry|null $breakers The dependency breakers; null leaves calls unguarded.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly IClient $inner,
		private readonly ProxySettingsInterface $proxy,
		private readonly ?BreakerRegistry $breakers = null,
	) {

	}//end __construct()

	/**
	 * Merge the proxy options under the caller's own.
	 *
	 * @param array<string, mixed> $options The caller's options.
	 *
	 * @return array<string, mixed> The options the request is made with.
	 *
	 * @spec openspec/specs/api-surface-governance/spec.md#requirement-the-instance-answers-the-well-known-paths-and-honours-an-administered-proxy-req-avs-004
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
	 * Forwards to the decorated client with the administered proxy merged in.
	 *
	 * @param string $uri The target URI.
	 * @param array<string, mixed> $options The caller's request options.
	 *
	 * @return IResponse The response.
	 *
	 * @spec openspec/specs/api-surface-governance/spec.md#requirement-the-instance-answers-the-well-known-paths-and-honours-an-administered-proxy-req-avs-004
	 */
	public function get(string $uri, array $options = []): IResponse {
		return $this->guarded(
			uri: $uri,
			options: $options,
			send: fn (array $sendOptions): IResponse => $this->inner->get($uri, $this->withProxy(options: $sendOptions))
		);

	}//end get()

	/**
	 * Forwards to the decorated client with the administered proxy merged in.
	 *
	 * @param string $uri The target URI.
	 * @param array<string, mixed> $options The caller's request options.
	 *
	 * @return IResponse The response.
	 *
	 * @spec openspec/specs/api-surface-governance/spec.md#requirement-the-instance-answers-the-well-known-paths-and-honours-an-administered-proxy-req-avs-004
	 */
	public function head(string $uri, array $options = []): IResponse {
		return $this->guarded(
			uri: $uri,
			options: $options,
			send: fn (array $sendOptions): IResponse => $this->inner->head($uri, $this->withProxy(options: $sendOptions))
		);

	}//end head()

	/**
	 * Forwards to the decorated client with the administered proxy merged in.
	 *
	 * @param string $uri The target URI.
	 * @param array<string, mixed> $options The caller's request options.
	 *
	 * @return IResponse The response.
	 *
	 * @spec openspec/specs/api-surface-governance/spec.md#requirement-the-instance-answers-the-well-known-paths-and-honours-an-administered-proxy-req-avs-004
	 */
	public function post(string $uri, array $options = []): IResponse {
		return $this->guarded(
			uri: $uri,
			options: $options,
			send: fn (array $sendOptions): IResponse => $this->inner->post($uri, $this->withProxy(options: $sendOptions))
		);

	}//end post()

	/**
	 * Forwards to the decorated client with the administered proxy merged in.
	 *
	 * @param string $uri The target URI.
	 * @param array<string, mixed> $options The caller's request options.
	 *
	 * @return IResponse The response.
	 *
	 * @spec openspec/specs/api-surface-governance/spec.md#requirement-the-instance-answers-the-well-known-paths-and-honours-an-administered-proxy-req-avs-004
	 */
	public function put(string $uri, array $options = []): IResponse {
		return $this->guarded(
			uri: $uri,
			options: $options,
			send: fn (array $sendOptions): IResponse => $this->inner->put($uri, $this->withProxy(options: $sendOptions))
		);

	}//end put()

	/**
	 * Forwards to the decorated client with the administered proxy merged in.
	 *
	 * @param string $uri The target URI.
	 * @param array<string, mixed> $options The caller's request options.
	 *
	 * @return IResponse The response.
	 *
	 * @spec openspec/specs/api-surface-governance/spec.md#requirement-the-instance-answers-the-well-known-paths-and-honours-an-administered-proxy-req-avs-004
	 */
	public function patch(string $uri, array $options = []): IResponse {
		return $this->guarded(
			uri: $uri,
			options: $options,
			send: fn (array $sendOptions): IResponse => $this->inner->patch($uri, $this->withProxy(options: $sendOptions))
		);

	}//end patch()

	/**
	 * Forwards to the decorated client with the administered proxy merged in.
	 *
	 * @param string $uri The target URI.
	 * @param array<string, mixed> $options The caller's request options.
	 *
	 * @return IResponse The response.
	 *
	 * @spec openspec/specs/api-surface-governance/spec.md#requirement-the-instance-answers-the-well-known-paths-and-honours-an-administered-proxy-req-avs-004
	 */
	public function delete(string $uri, array $options = []): IResponse {
		return $this->guarded(
			uri: $uri,
			options: $options,
			send: fn (array $sendOptions): IResponse => $this->inner->delete($uri, $this->withProxy(options: $sendOptions))
		);

	}//end delete()

	/**
	 * Forwards to the decorated client with the administered proxy merged in.
	 *
	 * @param string $uri The target URI.
	 * @param array<string, mixed> $options The caller's request options.
	 *
	 * @return IResponse The response.
	 *
	 * @spec openspec/specs/api-surface-governance/spec.md#requirement-the-instance-answers-the-well-known-paths-and-honours-an-administered-proxy-req-avs-004
	 */
	public function options(string $uri, array $options = []): IResponse {
		return $this->guarded(
			uri: $uri,
			options: $options,
			send: fn (array $sendOptions): IResponse => $this->inner->options($uri, $this->withProxy(options: $sendOptions))
		);

	}//end options()

	/**
	 * Forwards to the decorated client with the administered proxy merged in.
	 *
	 * @param string $method The HTTP method.
	 * @param string $uri The target URI.
	 * @param array<string, mixed> $options The caller's request options.
	 *
	 * @return IResponse The response.
	 *
	 * @spec openspec/specs/api-surface-governance/spec.md#requirement-the-instance-answers-the-well-known-paths-and-honours-an-administered-proxy-req-avs-004
	 */
	public function request(string $method, string $uri, array $options = []): IResponse {
		return $this->guarded(
			uri: $uri,
			options: $options,
			send: fn (array $sendOptions): IResponse => $this->inner->request($method, $uri, $this->withProxy(options: $sendOptions))
		);

	}//end request()

	/**
	 * Forwards to the decorated client with the administered proxy merged in.
	 *
	 * @param string $uri The target URI.
	 * @param array<string, mixed> $options The caller's request options.
	 *
	 * @return IPromise The promise.
	 *
	 * @spec openspec/specs/api-surface-governance/spec.md#requirement-the-instance-answers-the-well-known-paths-and-honours-an-administered-proxy-req-avs-004
	 */
	public function getAsync(string $uri, array $options = []): IPromise {
		return $this->inner->getAsync($uri, $this->withProxy(options: self::withoutDependency(options: $options)));

	}//end getAsync()

	/**
	 * Forwards to the decorated client with the administered proxy merged in.
	 *
	 * @param string $uri The target URI.
	 * @param array<string, mixed> $options The caller's request options.
	 *
	 * @return IPromise The promise.
	 *
	 * @spec openspec/specs/api-surface-governance/spec.md#requirement-the-instance-answers-the-well-known-paths-and-honours-an-administered-proxy-req-avs-004
	 */
	public function headAsync(string $uri, array $options = []): IPromise {
		return $this->inner->headAsync($uri, $this->withProxy(options: self::withoutDependency(options: $options)));

	}//end headAsync()

	/**
	 * Forwards to the decorated client with the administered proxy merged in.
	 *
	 * @param string $uri The target URI.
	 * @param array<string, mixed> $options The caller's request options.
	 *
	 * @return IPromise The promise.
	 *
	 * @spec openspec/specs/api-surface-governance/spec.md#requirement-the-instance-answers-the-well-known-paths-and-honours-an-administered-proxy-req-avs-004
	 */
	public function postAsync(string $uri, array $options = []): IPromise {
		return $this->inner->postAsync($uri, $this->withProxy(options: self::withoutDependency(options: $options)));

	}//end postAsync()

	/**
	 * Forwards to the decorated client with the administered proxy merged in.
	 *
	 * @param string $uri The target URI.
	 * @param array<string, mixed> $options The caller's request options.
	 *
	 * @return IPromise The promise.
	 *
	 * @spec openspec/specs/api-surface-governance/spec.md#requirement-the-instance-answers-the-well-known-paths-and-honours-an-administered-proxy-req-avs-004
	 */
	public function putAsync(string $uri, array $options = []): IPromise {
		return $this->inner->putAsync($uri, $this->withProxy(options: self::withoutDependency(options: $options)));

	}//end putAsync()

	/**
	 * Forwards to the decorated client with the administered proxy merged in.
	 *
	 * @param string $uri The target URI.
	 * @param array<string, mixed> $options The caller's request options.
	 *
	 * @return IPromise The promise.
	 *
	 * @spec openspec/specs/api-surface-governance/spec.md#requirement-the-instance-answers-the-well-known-paths-and-honours-an-administered-proxy-req-avs-004
	 */
	public function deleteAsync(string $uri, array $options = []): IPromise {
		return $this->inner->deleteAsync($uri, $this->withProxy(options: self::withoutDependency(options: $options)));

	}//end deleteAsync()

	/**
	 * Forwards to the decorated client with the administered proxy merged in.
	 *
	 * @param string $uri The target URI.
	 * @param array<string, mixed> $options The caller's request options.
	 *
	 * @return IPromise The promise.
	 *
	 * @spec openspec/specs/api-surface-governance/spec.md#requirement-the-instance-answers-the-well-known-paths-and-honours-an-administered-proxy-req-avs-004
	 */
	public function optionsAsync(string $uri, array $options = []): IPromise {
		return $this->inner->optionsAsync($uri, $this->withProxy(options: self::withoutDependency(options: $options)));

	}//end optionsAsync()

	/**
	 * Forwards a built PSR-7 request to the decorated client, unproxied.
	 *
	 * 🔴 NOT PROXIED, AND IT CANNOT BE. `sendRequest()` takes a built PSR-7
	 * request with nowhere to put a transport option, so there is no honest
	 * way to apply the proxy here. Passing it through unchanged is the truthful
	 * behaviour; silently succeeding while bypassing the proxy is what a
	 * pretend implementation would do. No call site in this app uses it.
	 *
	 * @param RequestInterface $request The built request.
	 *
	 * @return ResponseInterface The response.
	 *
	 * @spec openspec/specs/api-surface-governance/spec.md#requirement-the-instance-answers-the-well-known-paths-and-honours-an-administered-proxy-req-avs-004
	 */
	public function sendRequest(RequestInterface $request): ResponseInterface {
		return $this->inner->sendRequest($request);

	}//end sendRequest()

	/**
	 * Forwards to the decorated client with the administered proxy merged in.
	 *
	 * @param Throwable $e The thrown error.
	 *
	 * @return IResponse The response it maps to.
	 *
	 * @spec openspec/specs/api-surface-governance/spec.md#requirement-the-instance-answers-the-well-known-paths-and-honours-an-administered-proxy-req-avs-004
	 */
	public function getResponseFromThrowable(Throwable $e): IResponse {
		return $this->inner->getResponseFromThrowable($e);

	}//end getResponseFromThrowable()

	/**
	 * The request option a call site names its dependency with.
	 *
	 * Stripped before the request is sent: the transport would reject an
	 * option it does not know.
	 *
	 * @var string
	 */
	public const DEPENDENCY_OPTION = 'openregister_dependency';

	/**
	 * Send one request through the dependency's breaker.
	 *
	 * While the breaker is open the request is NOT sent: the caller gets a
	 * DependencyUnavailableException at once. Otherwise the outcome is counted:
	 * a connect error, a timeout, a 5xx or a 429 is a failure (a 429's
	 * Retry-After becomes the cool-down when longer); any other response,
	 * a 404 included, is the caller's problem and counts as a success.
	 *
	 * @param string   $uri     The request URI.
	 * @param array    $options The caller's request options.
	 * @param callable $send    Sends the request with the given options.
	 *
	 * @return IResponse The response.
	 *
	 * @throws DependencyUnavailableException While the dependency's breaker is open.
	 * @throws Throwable What the transport threw.
	 *
	 * @spec openspec/changes/operate-load-shedding/specs/load-shedding/spec.md
	 */
	private function guarded(string $uri, array $options, callable $send): IResponse {
		$key     = $this->breakerKey(uri: $uri, options: $options);
		$options = self::withoutDependency(options: $options);
		if ($key === null || $this->breakers === null) {
			return $send($options);
		}

		$breaker = $this->breakers->breaker();
		$breaker->allow(key: $key);

		try {
			$response = $send($options);
		} catch (DependencyUnavailableException $refused) {
			throw $refused;
		} catch (Throwable $failure) {
			$this->record(key: $key, response: self::responseOf(failure: $failure));
			throw $failure;
		}

		$this->record(key: $key, response: $response);
		return $response;
	}//end guarded()

	/**
	 * Count one outcome on the breaker.
	 *
	 * @param string                           $key      The breaker key.
	 * @param IResponse|ResponseInterface|null $response The response, or null when none arrived (connect error, timeout).
	 *
	 * @return void
	 */
	private function record(string $key, IResponse|ResponseInterface|null $response): void {
		$breaker = $this->breakers?->breaker();
		if ($breaker === null) {
			return;
		}

		if ($response === null) {
			$breaker->recordFailure(key: $key);
			return;
		}

		$status = $response->getStatusCode();
		if ($status === 429) {
			$breaker->recordFailure(key: $key, retryAfter: self::retryAfter(response: $response));
			return;
		}

		if ($status >= 500) {
			$breaker->recordFailure(key: $key);
			return;
		}

		$breaker->recordSuccess(key: $key);
	}//end record()

	/**
	 * The response a transport failure carried, if any.
	 *
	 * @param Throwable $failure What the transport threw.
	 *
	 * @return ResponseInterface|null The response, or null for a connect error or timeout.
	 */
	private static function responseOf(Throwable $failure): ?ResponseInterface {
		if ($failure instanceof RequestException) {
			return $failure->getResponse();
		}

		return null;
	}//end responseOf()

	/**
	 * A 429's Retry-After in seconds, when it gives a number of seconds.
	 *
	 * @param IResponse|ResponseInterface $response The response.
	 *
	 * @return int|null The seconds, or null.
	 */
	private static function retryAfter(IResponse|ResponseInterface $response): ?int {
		if ($response instanceof ResponseInterface) {
			$value = $response->getHeaderLine('Retry-After');
		} else {
			$value = $response->getHeader('Retry-After');
		}

		$value = trim($value);
		if ($value === '' || ctype_digit($value) === false) {
			return null;
		}

		return (int) $value;
	}//end retryAfter()

	/**
	 * The breaker key of one request: the named dependency, else the host.
	 *
	 * @param string $uri     The request URI.
	 * @param array  $options The caller's request options.
	 *
	 * @return string|null The key, or null when the URI names no host.
	 */
	private function breakerKey(string $uri, array $options): ?string {
		$named = $options[self::DEPENDENCY_OPTION] ?? null;
		if (is_string($named) === true && trim($named) !== '') {
			return BreakerRegistry::outboundKey(hostOrName: $named);
		}

		$host = parse_url($uri, PHP_URL_HOST);
		if (is_string($host) === false || $host === '') {
			return null;
		}

		return BreakerRegistry::outboundKey(hostOrName: $host);
	}//end breakerKey()

	/**
	 * The options without the dependency name, which the transport must not see.
	 *
	 * @param array $options The caller's request options.
	 *
	 * @return array The options to send.
	 */
	private static function withoutDependency(array $options): array {
		unset($options[self::DEPENDENCY_OPTION]);
		return $options;
	}//end withoutDependency()
}//end class
