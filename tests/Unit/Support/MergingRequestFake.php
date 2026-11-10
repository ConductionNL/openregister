<?php

/**
 * OpenRegister MergingRequestFake
 *
 * An IRequest that merges route parameters and a JSON body in the same order
 * as Nextcloud's own request, so a controller test sees what production sees
 * when the body repeats a route key.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Support
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Support;

use OCP\IRequest;

/**
 * Request double with Nextcloud's parameter precedence.
 *
 * In server lib/private/AppFramework/Http/Request.php the parameter set is
 * built in three steps: the constructor merges GET, POST and the URL
 * parameters; App::main() calls setUrlParameters(), which merges the route
 * parameters over that; and decodeContent() merges a decoded JSON body over
 * everything, last. A body key therefore beats a route key of the same name,
 * while the `urlParams` property keeps the route values alone. A mocked
 * getParams() hides this, which is how a PUT body carrying a pack slug as
 * `id` replaced the route id unnoticed.
 *
 * Unit tests cannot load the real class: the bootstrap declares an
 * empty stub under its name when no installed Nextcloud is in scope.
 */
class MergingRequestFake implements IRequest {

	/**
	 * Merged parameters, body last.
	 *
	 * @var array<string, mixed>
	 */
	private array $parameters;

	/**
	 * Constructor.
	 *
	 * @param array<string, mixed> $urlParams Route parameters, string values as the router yields them.
	 * @param array<string, mixed> $body Decoded JSON body.
	 * @param string $method HTTP method.
	 */
	public function __construct(
		private readonly array $urlParams,
		array $body,
		private readonly string $method = 'PUT',
	) {
		$this->parameters = array_merge($urlParams, $body);
	}//end __construct()

	/**
	 * Magic read access, limited to the keys these tests need.
	 *
	 * @param string $name Property name.
	 *
	 * @return mixed
	 */
	public function __get($name) {
		return match ($name) {
			'urlParams' => $this->urlParams,
			'parameters' => $this->parameters,
			'method' => $this->method,
			default => null,
		};
	}//end __get()

	/**
	 * Mirrors Request::__isset(): the known keys always exist.
	 *
	 * @param string $name Property name.
	 *
	 * @return bool
	 */
	public function __isset($name) {
		if (in_array($name, ['urlParams', 'parameters', 'method'], true) === true) {
			return true;
		}

		return isset($this->parameters[$name]);
	}//end __isset()

	/**
	 * Mirrors the dispatcher: read the merged value and cast it to the
	 * declared int type, as Dispatcher::executeController() does with settype().
	 *
	 * @param string $key Parameter name.
	 *
	 * @return int
	 */
	public function dispatchedInt(string $key): int {
		$value = $this->getParam($key);
		settype($value, 'integer');

		return $value;
	}//end dispatchedInt()

	public function getHeader(string $name): string {
		if (strtolower($name) === 'content-type') {
			return 'application/json';
		}

		return '';
	}//end getHeader()

	public function getParam(string $key, $default = null) {
		if (isset($this->parameters[$key]) === true) {
			return $this->parameters[$key];
		}

		return $default;
	}//end getParam()

	public function getParams(): array {
		return $this->parameters;
	}//end getParams()

	public function getMethod(): string {
		return $this->method;
	}//end getMethod()

	public function getUploadedFile(string $key) {
		return null;
	}//end getUploadedFile()

	public function getEnv(string $key) {
		return null;
	}//end getEnv()

	public function getCookie(string $key) {
		return null;
	}//end getCookie()

	public function passesCSRFCheck(): bool {
		return true;
	}//end passesCSRFCheck()

	public function passesStrictCookieCheck(): bool {
		return true;
	}//end passesStrictCookieCheck()

	public function passesLaxCookieCheck(): bool {
		return true;
	}//end passesLaxCookieCheck()

	public function getId(): string {
		return 'test-request';
	}//end getId()

	public function getRemoteAddress(): string {
		return '127.0.0.1';
	}//end getRemoteAddress()

	public function getServerProtocol(): string {
		return 'https';
	}//end getServerProtocol()

	public function getHttpProtocol(): string {
		return 'HTTP/1.1';
	}//end getHttpProtocol()

	public function getRequestUri(): string {
		return '';
	}//end getRequestUri()

	public function getRawPathInfo(): string {
		return '';
	}//end getRawPathInfo()

	public function getPathInfo() {
		return '';
	}//end getPathInfo()

	public function getScriptName(): string {
		return '/index.php';
	}//end getScriptName()

	public function isUserAgent(array $agent): bool {
		return false;
	}//end isUserAgent()

	public function getInsecureServerHost(): string {
		return 'localhost';
	}//end getInsecureServerHost()

	public function getServerHost(): string {
		return 'localhost';
	}//end getServerHost()

	public function throwDecodingExceptionIfAny(): void {
	}//end throwDecodingExceptionIfAny()

	public function getFormat(): ?string {
		return null;
	}//end getFormat()
}//end class
