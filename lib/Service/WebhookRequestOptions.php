<?php

/**
 * OpenRegister Webhook Request Options
 *
 * The HTTP client options for one webhook delivery.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 *
 * @author	Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git-id>
 * @link	  https://www.OpenRegister.app
 *
 * @spec openspec/specs/notificatie-engine/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

use Closure;
use OCA\OpenRegister\Db\Webhook;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\UriInterface;

/**
 * Headers (with the HMAC signature), timeouts and redirect policy for sending a
 * payload to a webhook, optionally under a hard timeout cap.
 *
 * @spec openspec/specs/notificatie-engine/spec.md
 */
final class WebhookRequestOptions {

	/**
	 * Constructor.
	 *
	 * @param Webhook  $webhook           Webhook configuration.
	 * @param int|null $timeoutCapSeconds Hard cap on connect + total timeout (request-blocking deliveries).
	 *
	 * @spec openspec/specs/notificatie-engine/spec.md
	 */
	public function __construct(
		private readonly Webhook $webhook,
		private readonly ?int $timeoutCapSeconds = null,
	) {
	}//end __construct()

	/**
	 * Request headers: JSON content type and user agent, the webhook's own headers
	 * over those, and an HMAC-SHA256 signature of the payload when a secret is set.
	 *
	 * @param array $payload Payload to send.
	 *
	 * @return array<string, string>
	 *
	 * @spec openspec/specs/notificatie-engine/spec.md
	 */
	public function headers(array $payload): array {
		$headers = array_merge(
			[
				'Content-Type' => 'application/json',
				'User-Agent' => 'OpenRegister-Webhooks/1.0',
			],
			$this->webhook->getHeadersArray()
		);

		$secret = $this->webhook->getSecret();
		if ($secret !== null) {
			$headers['X-Webhook-Signature'] = hash_hmac('sha256', json_encode($payload), $secret);
		}

		return $headers;
	}//end headers()

	/**
	 * The timeout options. Under a cap, connect and total timeout are capped; the cap
	 * never raises a webhook's own shorter timeout, but a non-positive per-webhook
	 * timeout (Guzzle: 0 = wait forever) does not escape it either.
	 *
	 * @return array{timeout: mixed, connect_timeout?: int}
	 *
	 * @spec openspec/specs/notificatie-engine/spec.md
	 */
	public function timeouts(): array {
		if ($this->timeoutCapSeconds === null) {
			return ['timeout' => $this->webhook->getTimeout()];
		}

		$webhookTimeout = (int)$this->webhook->getTimeout();
		$timeout = $this->timeoutCapSeconds;
		if ($webhookTimeout > 0 && $webhookTimeout < $this->timeoutCapSeconds) {
			$timeout = $webhookTimeout;
		}

		return ['timeout' => $timeout, 'connect_timeout' => $this->timeoutCapSeconds];
	}//end timeouts()

	/**
	 * The per-request redirect policy: the shared client's default (max, strict,
	 * protocols) with every redirect target passed to the given check, so it can
	 * honour this hook's allowPrivate flag.
	 *
	 * @param Closure(string): void $assertSafe Throws when a redirect target is not allowed.
	 *
	 * @return array<string, mixed> Guzzle allow_redirects option.
	 *
	 * @spec openspec/specs/notificatie-engine/spec.md
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) Guzzle calls `on_redirect` with (request, response, uri); only the uri is checked.
	 */
	public function redirects(Closure $assertSafe): array {
		return [
			'max' => 5,
			'strict' => true,
			'referer' => false,
			'protocols' => ['http', 'https'],
			'track_redirects' => true,
			'on_redirect' => static function (RequestInterface $request, ResponseInterface $response, UriInterface $uri) use ($assertSafe): void {
				$assertSafe((string)$uri);
			},
		];
	}//end redirects()
}//end class
