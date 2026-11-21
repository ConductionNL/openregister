<?php

/**
 * OpenRegister Webhook Service
 *
 * Service for handling webhook delivery.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git-id>
 * @link      https://www.OpenRegister.app
 *
 * @spec openspec/specs/webhook-payload-mapping/spec.md#requirement-webhook-entity-must-support-an-optional-mapping-reference-for-payload-transformation
 * @spec openspec/specs/webhook-payload-mapping/spec.md#requirement-webhook-testing-must-support-dry-run-delivery
 * @spec openspec/specs/webhook-payload-mapping/spec.md#requirement-request-interception-must-support-pre-event-webhooks
 * @spec openspec/specs/webhook-payload-mapping/spec.md
 * @spec openspec/specs/webhook-payload-mapping/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

use InvalidArgumentException;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Exception\RequestException;
use OCA\OpenRegister\BackgroundJob\WebhookDeliveryJob;
use OCA\OpenRegister\Db\Mapping;
use OCA\OpenRegister\Db\MappingMapperInterface;
use OCA\OpenRegister\Db\Webhook;
use OCA\OpenRegister\Db\WebhookLogMapperInterface;
use OCA\OpenRegister\Db\WebhookMapperInterface;
use OCA\OpenRegister\Service\Security\OutboundAddressGuard;
use OCA\OpenRegister\Service\Http\OutboundUrlGuard;
use OCA\OpenRegister\Notifications\Service\Webhook\CloudEventFormatterInterface;
use OCA\OpenRegister\Notifications\Service\Webhook\WebhookInterceptionCacheInterface;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\BackgroundJob\IJobList;
use OCP\EventDispatcher\Event;
use OCP\IRequest;
use OCA\OpenRegister\Notifications\Service\Webhook\CloudEventAttributes;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * WebhookService handles webhook delivery and request interception
 *
 * This service provides two main capabilities:
 * 1. Post-event webhook delivery - Sends webhooks after events occur
 * 2. Pre-request webhook interception - Intercepts requests before controller execution
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) Complex webhook delivery with retry and interception logic
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)   Webhook delivery requires mapping, formatting, and logging dependencies
 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
 */
class WebhookService {

	/**
	 * HTTP client
	 *
	 * @var GuzzleClient
	 */
	private GuzzleClient $client;

	/**
	 * Resolves and classifies webhook target addresses.
	 *
	 * @var OutboundAddressGuard|null
	 */
	private ?OutboundAddressGuard $addressGuard = null;

	/**
	 * Records delivery outcomes; built on first use from this service's collaborators.
	 *
	 * @var WebhookDeliveryRecorder|null
	 */
	private ?WebhookDeliveryRecorder $deliveryRecorder = null;

	/**
	 * Constructor
	 *
	 * @param WebhookStores           $stores           Webhook, log and mapping stores
	 * @param WebhookDispatchServices $dispatch         Job list, CloudEvent formatter and interception cache
	 * @param LoggerInterface         $logger           Logger
	 *
	 * @return void
	 */
	public function __construct(
		private readonly WebhookStores $stores,
		private readonly WebhookDispatchServices $dispatch,
		private readonly LoggerInterface $logger,
	) {
		$this->initializeHttpClient();
	}//end __construct()

	/**
	 * Maximum response body length kept in structured log context, in bytes (1 KB).
	 *
	 * Webhook responses are echoed into the application log to aid debugging,
	 * but full bodies can expose internal data when SSRF probing succeeded and
	 * also bloat the log. The persisted `WebhookLog.responseBody` is capped at
	 * MAX_RESPONSE_BODY_BYTES separately; this constant limits only the
	 * `response_body` field on log-context arrays.
	 *
	 * @var integer
	 */
	private const LOG_BODY_PREVIEW_BYTES = 1024;

	/**
	 * Connect + total timeout for request-interception deliveries, in seconds.
	 *
	 * Interception is request-blocking BY DESIGN: the object write waits for
	 * the hook's answer. A hook that cannot respond within 2 seconds must not
	 * gate writes — with the client default of 30s total / 10s connect, one
	 * slow endpoint stalled every object create for up to 30 seconds. This cap
	 * applies ONLY to the synchronous interception path; post-save deliveries
	 * run async via WebhookDeliveryJob and keep the per-webhook timeout.
	 *
	 * @var integer
	 */
	private const INTERCEPTION_TIMEOUT_SECONDS = 2;

	/**
	 * Initialize HTTP client with default configuration
	 *
	 * Creates a GuzzleHttp\Client instance with secure defaults for webhook delivery.
	 * Enforces TLS verification, blocks SSRF-prone redirects, and refuses to follow
	 * redirects to internal/loopback/link-local IP ranges (the same allowlist used
	 * by ConfigurationController::fetchConfigFromUrl).
	 *
	 * @return void
	 *
	 * @spec openspec/specs/webhook-payload-mapping/spec.md
	 */
	private function initializeHttpClient(): void {
		// Prepare Guzzle client configuration with TLS verification ON.
		// Redirects are followed but every Location must pass the same
		// SSRF guard that gates the initial URL — see assertSafeWebhookUri()
		// and the on_redirect callback below.
		// http_errors stays off because we handle 4xx/5xx manually downstream.
		$clientConfig = [
			'timeout' => 30,
			'connect_timeout' => 10,
			'verify' => true,
			'http_errors' => false,
			'allow_redirects' => [
				'max' => 5,
				'strict' => true,
				'referer' => false,
				'protocols' => ['http', 'https'],
				'track_redirects' => true,
				'on_redirect' => function (
					RequestInterface $request,
					ResponseInterface $response,
					\Psr\Http\Message\UriInterface $uri,
				): void {
					// Re-validate every redirect target through the same SSRF
					// allowlist used for the initial URL. Throwing here aborts
					// the redirect chain and propagates a RequestException to
					// deliverWebhook(), which logs it as a delivery failure.
					$this->assertSafeWebhookUri(uri: (string)$uri);
				},
			],
		];

		$this->client = new GuzzleClient($clientConfig);
	}//end initializeHttpClient()

	/**
	 * Validate a webhook target URI against the SSRF allowlist.
	 *
	 * Webhook URLs are user-controlled, so a tenant could point a webhook at
	 * an internal service (cloud-metadata endpoints, internal IPs, loopback)
	 * and use the side effects (TLS handshake, body echo into logs) as an
	 * exfiltration channel. This guard mirrors the policy that
	 * ConfigurationController::fetchConfigFromUrl applies to inbound
	 * configuration URLs.
	 *
	 * Rules:
	 *  - Only http/https schemes are permitted.
	 *  - The host's resolved IPv4 must not fall in loopback (127.0.0.0/8),
	 *    RFC-1918 (10/8, 172.16/12, 192.168/16) or link-local (169.254/16,
	 *    which includes the cloud metadata endpoint 169.254.169.254).
	 *  - IPv6 literals and AAAA DNS results are checked against loopback
	 *    (::1/128), unique-local (fc00::/7), link-local (fe80::/10),
	 *    documentation (2001:db8::/32), unspecified (::/128) and
	 *    IPv4-mapped (::ffff:0:0/96) ranges; the mapped IPv4 address is
	 *    additionally re-validated against the IPv4 block list.
	 *  - Unresolvable hosts are allowed through so that DNS failures surface
	 *    as the normal Guzzle ConnectException, not a 400 here.
	 *
	 * When $allowPrivate is true (an admin-set, per-hook opt-in stored as
	 * `configuration.allowPrivateTargets`), the IP-range checks are skipped so a
	 * developer can deliver to a local target such as http://localhost:8000. The
	 * scheme (http/https only) and parse/host checks are ALWAYS enforced — only
	 * the loopback/RFC-1918/link-local/IPv6 range checks are bypassed.
	 *
	 * @param string $uri Full request URI to validate.
	 * @param boolean $allowPrivate When true, skip the private/loopback IP-range checks.
	 *
	 * @return void
	 *
	 * @throws \RuntimeException When the URI is blocked.
	 *
	 * @spec openspec/specs/notificatie-engine/spec.md
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) The flag is the per-hook
	 * allowPrivateTargets opt-in; a boolean toggle is the clearest API for a
	 * binary "bypass the IP-range checks" decision threaded from the caller.
	 */
	private function assertSafeWebhookUri(string $uri, bool $allowPrivate = false): void {
		$this->urlGuard()->assertSafe(uri: $uri, allowPrivate: $allowPrivate);
	}//end assertSafeWebhookUri()

	/**
	 * Return the block-list reason for a given IPv6 address, or null if safe.
	 *
	 * Checks the following RFC-defined ranges:
	 *  - ::1/128            IPv6 loopback
	 *  - ::/128             Unspecified address
	 *  - fc00::/7           Unique local (includes fd00::/8)
	 *  - fe80::/10          Link-local unicast
	 *  - 2001:db8::/32      Documentation / example range (RFC 3849)
	 *  - ::ffff:0:0/96      IPv4-mapped IPv6 — the embedded IPv4 is further
	 *                       re-checked against the existing IPv4 block list so
	 *                       ::ffff:127.0.0.1 is also rejected.
	 *
	 * Binary range comparisons use inet_pton() for byte-level accuracy so
	 * no risk of the ip2long() sign-extension quirks that affect IPv4.
	 *
	 * @param string $ip IPv6 address string (no surrounding brackets).
	 *
	 * @return string|null Human-readable range label, or null when the address is safe.
	 *
	 * The rules live in {@see OutboundUrlGuard}; this keeps the webhook-facing
	 * entry point the address-label tests exercise.
	 */
	protected function blockedIpv6Reason(string $ip): ?string {
		return $this->urlGuard()->blockedIpv6Reason(ip: $ip);
	}//end blockedIpv6Reason()

	/**
	 * Detect whether a host resolves to an internal/private address.
	 *
	 * Used to decide whether the captured response body is safe to echo into
	 * the application log. When the target is private we redact rather than
	 * preview, so an SSRF probe cannot tunnel internal data through the log
	 * pipeline.
	 *
	 * Covers both IPv4 and IPv6 (literals and DNS A/AAAA records).
	 *
	 * @param string $host Hostname extracted from the webhook URL.
	 *
	 * @return bool True if the host resolves to a private/loopback/link-local IP.
	 */
	private function isPrivateHost(string $host): bool {
		return $this->urlGuard()->isPrivateHost(host: $host);
	}//end isPrivateHost()

	/**
	 * The shared outbound address guard, created on first use.
	 *
	 * @return OutboundAddressGuard The guard.
	 */
	private function addressGuard(): OutboundAddressGuard {
		$this->addressGuard ??= new OutboundAddressGuard();
		return $this->addressGuard;
	}//end addressGuard()

	/**
	 * The shared outbound URL guard over the current address guard, labelled for webhooks.
	 *
	 * Built per call so a replaced address guard (tests resolve hosts through
	 * one) is the one the rules consult.
	 *
	 * @return OutboundUrlGuard The guard.
	 */
	private function urlGuard(): OutboundUrlGuard {
		return new OutboundUrlGuard(addresses: $this->addressGuard(), label: 'Webhook target URL');
	}//end urlGuard()

	/**
	 * Truncate a response body for log-context use.
	 *
	 * Persistent storage (WebhookLog.responseBody) is capped at
	 * MAX_RESPONSE_BODY_BYTES. The shorter LOG_BODY_PREVIEW_BYTES cap applies
	 * here because log lines flow into shared aggregation and shouldn't carry
	 * full bodies. When the webhook target is internal/private, the preview
	 * is redacted entirely to avoid creating an SSRF exfiltration channel.
	 *
	 * @param string $body Raw response body.
	 * @param string $url Webhook target URL (for private-host detection).
	 *
	 * @return string The truncated/redacted preview.
	 */
	private function previewResponseBody(string $body, string $url): string {
		$parsedHost = parse_url($url, PHP_URL_HOST);
		if (is_string($parsedHost) === false) {
			$parsedHost = '';
		}

		$host = strtolower($parsedHost);
		if ($host !== '' && $this->isPrivateHost(host: $host) === true) {
			return '[redacted: webhook target is on an internal/private network]';
		}

		if (strlen($body) <= self::LOG_BODY_PREVIEW_BYTES) {
			return $body;
		}

		return substr($body, 0, self::LOG_BODY_PREVIEW_BYTES) . '... [truncated]';
	}//end previewResponseBody()

	/**
	 * Cap a response body to the stored size for persistent storage.
	 *
	 * @param string $body Raw response body.
	 *
	 * @return string Body limited to the response-body cap.
	 */
	private function capResponseBody(string $body): string {
		return $this->deliveryRecorder()->capResponseBody(body: $body);
	}//end capResponseBody()

	/**
	 * The recorder of delivery outcomes, built on first use.
	 *
	 * @return WebhookDeliveryRecorder
	 */
	private function deliveryRecorder(): WebhookDeliveryRecorder {
		$this->deliveryRecorder ??= new WebhookDeliveryRecorder(
			logger: $this->logger,
			webhookMapper: $this->stores->webhookMapper,
			webhookLogMapper: $this->stores->webhookLogMapper,
			previewBody: $this->previewResponseBody(...)
		);

		return $this->deliveryRecorder;
	}//end deliveryRecorder()

	/**
	 * Dispatch event to all matching webhooks
	 *
	 * Takes main's (eventName, payload), or the published (event, eventName, payload) whose event is
	 * not read, by name or by position (an Event in the first slot).
	 *
	 * @param string|Event|null $eventName         Event class name; an Event here is the release's positional `$_event`.
	 * @param array|string|null $payload           Event payload data; a string here is the release's positional `$eventName`.
	 * @param array|null        $positionalPayload The release's positional `$payload`.
	 * @param Event|null        $_event            The release's event; not read.
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException When no event name or payload is given.
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) $_event is kept for the published contract.
	 *
	 * @spec openspec/specs/webhook-payload-mapping/spec.md#requirement-request-interception-must-support-pre-event-webhooks
	 * @spec openspec/specs/webhook-payload-mapping/spec.md
	 */
	public function dispatchEvent(
		string|Event|null $eventName = null,
		array|string|null $payload = null,
		?array $positionalPayload = null,
		?Event $_event = null,
	): void {
		if ($eventName instanceof Event) {
			$eventName = $payload;
			$payload   = $positionalPayload;
		}

		if (is_string($eventName) === false || is_array($payload) === false) {
			throw new InvalidArgumentException('dispatchEvent() needs an event name and a payload.');
		}

		$this->dispatch(event: new WebhookEvent(name: $eventName, payload: $payload));
	}//end dispatchEvent()

	/**
	 * Whether any webhook is subscribed to an event.
	 *
	 * Asked before the event's payload is built, so a write on an instance
	 * with no webhook for that event pays no serialisation. A webhook table
	 * that does not exist yet counts as no webhooks, as in dispatch().
	 *
	 * @param string $eventName Event class name.
	 *
	 * @return bool True when at least one webhook is subscribed.
	 *
	 * @spec openspec/changes/object-event-sync-async-split/specs/event-driven-architecture/spec.md#requirement-trigger-flow-and-rule-resolution-must-filter-at-query-time
	 */
	public function hasWebhooksFor(string $eventName): bool {
		return $this->webhooksForEvent(eventName: $eventName) !== [];
	}//end hasWebhooksFor()

	/**
	 * Enqueue one delivery job per webhook subscribed to an event (value-object form of dispatchEvent()).
	 *
	 * @param WebhookEvent $event The event name and payload.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/webhook-payload-mapping/spec.md
	 */
	public function dispatch(WebhookEvent $event): void {
		$eventName = $event->name;
		$payload = $event->payload;
		$webhooks = $this->webhooksForEvent(eventName: $eventName);
		if ($webhooks === []) {
			return;
		}

		$this->logger->info(
			message: '[WebhookService] Dispatching event to webhooks',
			context: [
				'file' => __FILE__,
				'line' => __LINE__,
				'event' => $eventName,
				'webhook_count' => count($webhooks),
			]
		);

		// Enqueue delivery (including the FIRST attempt) as a background job so
		// the object-write response never blocks on an external endpoint — with
		// N webhooks each up to a 30s timeout, synchronous delivery made write
		// latency a function of third-party uptime (async-webhook-delivery /
		// ADR-009 Rule 5). WebhookDeliveryJob performs the same delivery + logging.
		foreach ($webhooks as $webhook) {
			// An owned webhook is filtered before a job is queued, so a busy
			// instance does not queue one job per owned webhook per event only
			// to drop it in the job (webhooks-for-owners D-5).
			if ($this->ownedWebhookSkipsEvent(webhook: $webhook, eventName: $eventName, payload: $payload) === true) {
				continue;
			}

			try {
				$this->dispatch->jobList->add(
					WebhookDeliveryJob::class,
					[
						'webhook_id' => $webhook->getId(),
						'event_name' => $eventName,
						'payload' => $payload,
						'attempt' => 1,
					]
				);
			} catch (Throwable $e) {
				// Nextcloud refuses a job argument over 4000 characters (32 and 33)
				// or 32000 (34 and later), and the payload is the whole event. The
				// refusal must not abort the webhooks after this one, nor surface
				// as an exception in the listener (where it is only a log line);
				// this delivery is lost, and the log says which one and why.
				$this->logger->error(
					message: '[WebhookService] A webhook delivery could not be enqueued and was not sent',
					context: [
						'file' => __FILE__,
						'line' => __LINE__,
						'webhook_id' => $webhook->getId(),
						'event' => $eventName,
						'payload_length' => strlen((string)json_encode($payload)),
						'error' => $e->getMessage(),
					]
				);
			}
		}
	}//end dispatch()

	/**
	 * Whether an owned webhook would refuse this event anyway: an event it never
	 * delivers, or a payload outside its register and schema scope.
	 *
	 * @param Webhook              $webhook   The webhook.
	 * @param string               $eventName The event class.
	 * @param array<string, mixed> $payload   The event payload.
	 *
	 * @return bool True to queue no job; always false for an administrator's webhook.
	 *
	 * @spec openspec/changes/webhooks-for-owners/specs/webhook-payload-mapping/spec.md#requirement-an-owned-webhook-delivers-only-what-its-owner-may-see
	 */
	private function ownedWebhookSkipsEvent(Webhook $webhook, string $eventName, array $payload): bool {
		if (trim((string)$webhook->getOwner()) === '') {
			return false;
		}

		return OwnedWebhookView::mayQueue(webhook: $webhook, eventName: $eventName) === false
			|| $this->passesFilters(webhook: $webhook, payload: $payload) === false;
	}//end ownedWebhookSkipsEvent()

	/**
	 * The webhooks subscribed to an event; none (logged) when there are none or the webhook
	 * table does not exist yet because migrations have not run.
	 *
	 * @param string $eventName Event class name.
	 *
	 * @return array The webhooks.
	 */
	private function webhooksForEvent(string $eventName): array {
		try {
			$webhooks = $this->stores->webhookMapper->findForEvent($eventName);
		} catch (\Exception $e) {
			$this->logger->debug(
				message: '[WebhookService] Webhook table does not exist yet, skipping webhook delivery',
				context: ['file' => __FILE__, 'line' => __LINE__, 'event' => $eventName, 'error' => $e->getMessage()]
			);
			return [];
		}

		if (empty($webhooks) === true) {
			$this->logger->debug(
				message: '[WebhookService] No webhooks configured for event',
				context: ['file' => __FILE__, 'line' => __LINE__, 'event' => $eventName]
			);
			return [];
		}

		return $webhooks;
	}//end webhooksForEvent()

	/**
	 * Deliver one event to one webhook and log the outcome.
	 *
	 * @param WebhookDelivery $delivery The webhook, event, attempt number and optional timeout cap.
	 *
	 * @return bool Success status
	 *
	 * @spec openspec/specs/webhook-payload-mapping/spec.md
	 */
	public function deliverWebhook(WebhookDelivery $delivery): bool {
		$webhook = $delivery->webhook;
		$eventName = $delivery->event->name;
		$skipReason = $this->deliverySkipReason(webhook: $webhook, payload: $delivery->event->payload);
		if ($skipReason !== null) {
			$this->logger->debug(
				message: $skipReason,
				context: ['file' => __FILE__, 'line' => __LINE__, 'webhook_id' => $webhook->getId(), 'event' => $eventName]
			);
			return false;
		}

		$delivery = $this->ownerView(delivery: $delivery);
		if ($delivery === null) {
			return false;
		}

		$webhookPayload = $this->buildPayload(delivery: $delivery);
		$recorder = $this->deliveryRecorder();
		$webhookLog = $recorder->startDeliveryLog(webhook: $webhook, webhookPayload: $webhookPayload);
		$webhookLog->setEventClass($eventName);
		$webhookLog->setAttempt($delivery->attempt);
		$attempt = new WebhookDeliveryAttempt(webhook: $webhook, log: $webhookLog);

		try {
			$response = $this->sendRequest(delivery: $delivery, payload: $webhookPayload);
			return $recorder->recordResponse(delivery: $attempt, response: $response);
		} catch (RequestException $e) {
			return $recorder->recordRequestFailure(delivery: $attempt, exception: $e);
		} catch (\Exception $e) {
			return $recorder->recordUnexpectedFailure(delivery: $attempt, exception: $e);
		}
	}//end deliverWebhook()

	/**
	 * The delivery as an owned webhook may send it, or null when it sends nothing.
	 *
	 * An administrator's webhook passes unchanged. An owned one goes through
	 * {@see OwnedWebhookView} on every attempt, retries and resends included;
	 * without that view it delivers nothing rather than the full event.
	 *
	 * @param WebhookDelivery $delivery The delivery.
	 *
	 * @return WebhookDelivery|null The delivery to send, or null.
	 *
	 * @spec openspec/changes/webhooks-for-owners/specs/webhook-payload-mapping/spec.md#requirement-an-owned-webhook-delivers-only-what-its-owner-may-see
	 */
	private function ownerView(WebhookDelivery $delivery): ?WebhookDelivery {
		if (trim((string)$delivery->webhook->getOwner()) === '') {
			return $delivery;
		}

		$event = $this->dispatch->ownedView?->view(webhook: $delivery->webhook, event: $delivery->event);
		if ($event === null) {
			return null;
		}

		return new WebhookDelivery(
			webhook: $delivery->webhook,
			event: $event,
			attempt: $delivery->attempt,
			timeoutCapSeconds: $delivery->timeoutCapSeconds
		);
	}//end ownerView()

	/**
	 * Why a delivery is skipped: the webhook is disabled or its filters do not match.
	 *
	 * @param Webhook $webhook Webhook configuration
	 * @param array   $payload Event payload
	 *
	 * @return string|null The log message for the skip, or null when the delivery goes ahead
	 */
	private function deliverySkipReason(Webhook $webhook, array $payload): ?string {
		if ($webhook->getEnabled() === false) {
			return '[WebhookService] Webhook is disabled, skipping delivery';
		}

		if ($this->passesFilters(webhook: $webhook, payload: $payload) === false) {
			return '[WebhookService] Webhook filters did not match, skipping delivery';
		}

		return null;
	}//end deliverySkipReason()

	/**
	 * Check if payload passes webhook filters
	 *
	 * @param Webhook $webhook Webhook configuration
	 * @param array $payload Event payload
	 *
	 * @return bool
	 */
	private function passesFilters(Webhook $webhook, array $payload): bool {
		$filters = $webhook->getFiltersArray();

		if (empty($filters) === true) {
			return true;
		}

		foreach ($filters as $key => $value) {
			// Support dot notation for nested keys.
			$actualValue = $this->getNestedValue(array: $payload, key: $key);
			if ($this->filterMatches(expected: $value, actual: $actualValue) === false) {
				return false;
			}
		}

		return true;
	}//end passesFilters()

	/**
	 * Whether one payload value satisfies one filter value.
	 *
	 * @param mixed $expected The filter value; a list means "one of these" (loose comparison).
	 * @param mixed $actual   The payload value.
	 *
	 * @return bool
	 */
	private function filterMatches(mixed $expected, mixed $actual): bool {
		// If filter value is array, check if actual value is in array.
		if (is_array($expected) === true) {
			return in_array($actual, $expected);
		}

		return $actual === $expected;
	}//end filterMatches()

	/**
	 * Get nested value from array using dot notation
	 *
	 * @param array $array Array to search
	 * @param string $key Dot-notated key
	 *
	 * @return mixed
	 *
	 * @spec openspec/specs/webhook-payload-mapping/spec.md
	 */
	private function getNestedValue(array $array, string $key) {
		$keys = explode('.', $key);

		foreach ($keys as $k) {
			if (isset($array[$k]) === false) {
				return null;
			}

			$array = $array[$k];
		}

		return $array;
	}//end getNestedValue()

	/**
	 * The payload a delivery of this webhook would send for one event, without sending it
	 *
	 * The body comes from buildPayload(), the method a delivery uses, so the
	 * preview cannot drift from what is sent. `mapped` says whether the mapping
	 * shaped it: a delivery whose mapping is missing or fails falls back to the
	 * standard payload, and the preview must not show that fallback as mapped.
	 *
	 * @param Webhook      $webhook The webhook, saved or as the dialog holds it.
	 * @param WebhookEvent $event   The event name and payload.
	 *
	 * @return array{payload: array, mapped: bool}
	 *
	 * @spec openspec/specs/webhook-payload-mapping/spec.md
	 */
	public function previewPayload(Webhook $webhook, WebhookEvent $event): array {
		$delivery = new WebhookDelivery(webhook: $webhook, event: $event);
		$mapped = false;
		$mappingId = $webhook->getMapping();
		if ($mappingId !== null) {
			$mapped = $this->applyMappingTransformation(delivery: $delivery, mappingId: $mappingId) !== null;
		}

		return [
			'payload' => $this->buildPayload(delivery: $delivery),
			'mapped' => $mapped,
		];
	}//end previewPayload()

	/**
	 * Build webhook payload
	 *
	 * Builds the webhook payload using one of three strategies (in priority order):
	 * 1. Mapping transformation — if webhook references a Mapping entity
	 * 2. CloudEvents format — if configured via useCloudEvents
	 * 3. Standard format — default
	 *
	 * @param WebhookDelivery $delivery The webhook, the event and the delivery attempt number
	 *
	 * @return array Webhook payload in mapped, CloudEvents, or standard format.
	 *
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity) Three payload format strategies
	 *
	 * @spec openspec/specs/webhook-payload-mapping/spec.md#requirement-webhook-entity-must-support-an-optional-mapping-reference-for-payload-transformation
	 */
	private function buildPayload(WebhookDelivery $delivery): array {
		$webhook = $delivery->webhook;
		$eventName = $delivery->event->name;
		$payload = $delivery->event->payload;
		$attempt = $delivery->attempt;

		// Strategy 1: Apply mapping transformation if webhook references a Mapping entity.
		$mappingId = $webhook->getMapping();
		if ($mappingId !== null) {
			$mappedPayload = $this->applyMappingTransformation(delivery: $delivery, mappingId: $mappingId);

			if ($mappedPayload !== null) {
				return $mappedPayload;
			}

			// Fall through to other formats if mapping failed.
		}

		// Strategy 2: Use CloudEvents format if configured and formatter is available.
		$config = $webhook->getConfigurationArray();
		$useCloudEvents = ($config['useCloudEvents'] ?? false) === true;

		if ($useCloudEvents === true && $this->dispatch->cloudEventFormatter !== null) {
			// Add webhook metadata to payload.
			$enrichedPayload = array_merge(
				$payload,
				[
					'webhook' => [
						'id' => $webhook->getUuid(),
						'name' => $webhook->getName(),
					],
					'attempt' => $attempt,
				]
			);

			return $this->dispatch->cloudEventFormatter->formatAsCloudEvent(
				attributes: new CloudEventAttributes(
					type: $eventName,
					source: $config['cloudEventSource'] ?? null,
					subject: $config['cloudEventSubject'] ?? null
				),
				payload: $enrichedPayload
			);
		}

		// Strategy 3: Use standard format.
		return [
			'event' => $eventName,
			'webhook' => [
				'id' => $webhook->getUuid(),
				'name' => $webhook->getName(),
			],
			'data' => $payload,
			'timestamp' => date('c'),
			'attempt' => $attempt,
		];
	}//end buildPayload()

	/**
	 * Apply mapping transformation to event payload
	 *
	 * Loads the referenced Mapping entity and runs the event payload through
	 * MappingService.executeMapping(). Returns null on failure so the caller
	 * can fall back to other formats.
	 *
	 * @param WebhookDelivery $delivery  The webhook (for context) and the raw event
	 * @param int             $mappingId Mapping entity ID
	 *
	 * @return array|null Transformed payload, or null on failure
	 *
	 * @spec openspec/specs/webhook-payload-mapping/spec.md#requirement-webhook-entity-must-support-an-optional-mapping-reference-for-payload-transformation
	 */
	private function applyMappingTransformation(WebhookDelivery $delivery, int $mappingId): ?array {
		$webhook = $delivery->webhook;
		try {
			$mapping = $this->stores->mappingMapper->find($mappingId);
		} catch (DoesNotExistException $e) {
			$this->logger->warning(
				message: '[WebhookService] Webhook references missing mapping, falling back to raw payload',
				context: [
					'file' => __FILE__,
					'line' => __LINE__,
					'webhook_id' => $webhook->getId(),
					'mapping_id' => $mappingId,
				]
			);
			return null;
		} catch (\Exception $e) {
			$this->logger->warning(
				message: '[WebhookService] Failed to load mapping entity',
				context: [
					'file' => __FILE__,
					'line' => __LINE__,
					'webhook_id' => $webhook->getId(),
					'mapping_id' => $mappingId,
					'error' => $e->getMessage(),
				]
			);
			return null;
		}//end try

		// Build the mapping input with full event context.
		$mappingInput = array_merge(
			$delivery->event->payload,
			[
				'event' => $this->getShortEventName(eventName: $delivery->event->name),
				'timestamp' => date('c'),
			]
		);

		try {
			return $this->stores->mappingService->executeMapping(mapping: $mapping, input: $mappingInput);
		} catch (\Exception $e) {
			$this->logger->warning(
				message: '[WebhookService] Mapping transformation failed, falling back to raw payload',
				context: [
					'file' => __FILE__,
					'line' => __LINE__,
					'webhook_id' => $webhook->getId(),
					'mapping_id' => $mappingId,
					'mapping_name' => $mapping->getName(),
					'error' => $e->getMessage(),
				]
			);
			return null;
		}
	}//end applyMappingTransformation()

	/**
	 * Get short event class name from fully qualified class name
	 *
	 * @param string $eventName Fully qualified event class name
	 *
	 * @return string Short class name (e.g., "ObjectCreatedEvent")
	 *
	 * @spec openspec/specs/webhook-payload-mapping/spec.md
	 */
	private function getShortEventName(string $eventName): string {
		$parts = explode('\\', $eventName);
		return end($parts);
	}//end getShortEventName()

	/**
	 * Send HTTP request to webhook URL
	 *
	 * @param WebhookDelivery $delivery The webhook and the optional hard cap on connect + total timeout
	 *                                  (request-blocking interception deliveries)
	 * @param array           $payload  Payload to send
	 *
	 * @return (int|string)[] Response data
	 *
	 * @throws RequestException
	 *
	 * @psalm-return array{status_code: int, body: string}
	 *
	 * Different handling for GET vs POST/PUT/PATCH/DELETE methods
	 *
	 * @spec openspec/specs/notificatie-engine/spec.md
	 */
	private function sendRequest(WebhookDelivery $delivery, array $payload): array {
		$webhook = $delivery->webhook;
		// Per-hook opt-in (admin-set) to allow private/loopback targets for
		// local testing. Defaults to false (full SSRF blocking) when the key
		// is absent. See configuration.allowPrivateTargets.
		$allowPrivate = (bool)($webhook->getConfigurationArray()['allowPrivateTargets'] ?? false);
		// An owned webhook never reaches the instance's own network, whatever
		// its configuration says (webhooks-for-owners D-4/D-5).
		if (trim((string)$webhook->getOwner()) !== '') {
			$allowPrivate = false;
		}

		// SSRF guard: validate the webhook target before issuing the request.
		// Redirects are re-validated by the per-request on_redirect callback set
		// in $options below, which honours the same per-hook flag.
		$this->assertSafeWebhookUri(uri: $webhook->getUrl(), allowPrivate: $allowPrivate);

		$request = new WebhookRequestOptions(webhook: $webhook, timeoutCapSeconds: $delivery->timeoutCapSeconds);
		$options = ['headers' => $request->headers(payload: $payload)] + $request->timeouts();
		$options['allow_redirects'] = $request->redirects(
			assertSafe: function (string $uri) use ($allowPrivate): void {
				$this->assertSafeWebhookUri(uri: $uri, allowPrivate: $allowPrivate);
			}
		);

		// For GET requests, use query parameters; for others, send JSON body.
		$payloadKey = 'json';
		if (strtoupper($webhook->getMethod()) === 'GET') {
			$payloadKey = 'query';
		}

		$options[$payloadKey] = $payload;

		$response = $this->client->request(
			method: $webhook->getMethod(),
			uri: $webhook->getUrl(),
			options: $options
		);

		// Cap the response body so a misbehaving (or hostile) endpoint cannot
		// exhaust log storage by returning multi-MB responses.
		return [
			'status_code' => $response->getStatusCode(),
			'body' => $this->capResponseBody(body: (string)$response->getBody()),
		];
	}//end sendRequest()

	/**
	 * Intercept request and send to webhooks
	 *
	 * Finds webhooks configured for "before" events matching this request,
	 * sends CloudEvent-formatted payloads, and optionally processes responses
	 * to modify the request.
	 *
	 * This enables pre-request webhook notifications and request modification
	 * based on webhook responses.
	 *
	 * @param IRequest $request The HTTP request
	 * @param string $eventType The event type (e.g., 'object.creating')
	 *
	 * @return array Modified request data or original request data
	 *
	 * @spec openspec/specs/webhook-payload-mapping/spec.md#request-interception-must-support-pre-event-webhooks
	 *   (finds before-event webhooks for the event type, formats the request as a CloudEvent, delivers to each,
	 *   and continues past per-webhook failures, returning the request data)
	 */
	public function interceptRequest(IRequest $request, string $eventType): array {
		// Fast path: a cached (or freshly computed) tenant-agnostic flag tells
		// us whether ANY interception webhook exists for this event type. On
		// installs without interception webhooks — the common case — this
		// avoids the webhook table scan on every object write.
		if ($this->hasInterceptionWebhooks(eventType: $eventType) === false) {
			return $request->getParams();
		}

		// Find webhooks configured for this event type (organisation-filtered).
		$webhooks = $this->findWebhooksForInterception(eventType: $eventType);

		if (empty($webhooks) === true) {
			// No webhooks configured for this organisation, return original request data.
			return $request->getParams();
		}

		$event = $this->interceptionEvent(request: $request, eventType: $eventType);

		// Get original request data.
		$requestData = $request->getParams();

		// Process each webhook; one failing does not stop the others.
		foreach ($webhooks as $webhook) {
			$this->interceptWith(webhook: $webhook, event: $event);
		}

		return $requestData;
	}//end interceptRequest()

	/**
	 * The event an interception webhook receives: the request as a CloudEvent, in the standard webhook payload.
	 *
	 * @param IRequest $request   The HTTP request
	 * @param string   $eventType The event type (e.g., 'object.creating')
	 *
	 * @return WebhookEvent The event.
	 */
	private function interceptionEvent(IRequest $request, string $eventType): WebhookEvent {
		// Format request as CloudEvent if formatter is available.
		// Default to basic request data, override with CloudEvent format if formatter available.
		$cloudEvent = [
			'type' => $eventType,
			'method' => $request->getMethod(),
			'path' => $request->getPathInfo(),
			'body' => $request->getParams(),
		];
		if ($this->dispatch->cloudEventFormatter !== null) {
			$cloudEvent = $this->dispatch->cloudEventFormatter->formatRequestAsCloudEvent(
				request: $request,
				eventType: $eventType
			);
		}

		// Convert CloudEvent to standard webhook payload format.
		return new WebhookEvent(name: $eventType, payload: ['objectType' => 'request', 'action' => $eventType, 'cloudEvent' => $cloudEvent]);
	}//end interceptionEvent()

	/**
	 * Deliver the interception event to one webhook, logging a failure instead of throwing it.
	 *
	 * @param Webhook      $webhook The webhook.
	 * @param WebhookEvent $event   The interception event.
	 *
	 * @return void
	 */
	private function interceptWith(Webhook $webhook, WebhookEvent $event): void {
		try {
			// Interception blocks the object write, so the delivery timeout is capped hard — see INTERCEPTION_TIMEOUT_SECONDS.
			$success = $this->deliverWebhook(
				delivery: new WebhookDelivery(webhook: $webhook, event: $event, timeoutCapSeconds: self::INTERCEPTION_TIMEOUT_SECONDS)
			);
			if ($success === true && $this->shouldProcessResponse(webhook: $webhook) === true) {
				$this->logResponseProcessingPending(webhook: $webhook, eventType: $event->name);
			}
		} catch (\Exception $e) {
			// Log failure but continue processing other webhooks.
			$this->logger->error(
				message: '[WebhookService] Failed to deliver webhook during request interception',
				context: $this->interceptionLogContext(webhook: $webhook, eventType: $event->name) + ['error' => $e->getMessage()]
			);
		}
	}//end interceptWith()

	/**
	 * Note that a delivered webhook wants its response processed, which is not implemented yet.
	 *
	 * Note: This is currently limited as we're using fire-and-forget delivery.
	 * TODO: Implement response handling if needed.
	 *
	 * @param Webhook $webhook   The webhook.
	 * @param string  $eventType The event type.
	 *
	 * @return void
	 */
	private function logResponseProcessingPending(Webhook $webhook, string $eventType): void {
		$this->logger->info(
			message: '[WebhookService] Webhook delivery successful but response processing not yet implemented',
			context: [
				'file' => __FILE__,
				'line' => __LINE__,
				'webhook_id' => $webhook->getId(),
				'event_type' => $eventType,
			]
		);
	}//end logResponseProcessingPending()

	/**
	 * The log context naming the webhook and event of a failed interception.
	 *
	 * @param Webhook $webhook   The webhook.
	 * @param string  $eventType The event type.
	 *
	 * @return array<string, mixed> The context.
	 */
	private function interceptionLogContext(Webhook $webhook, string $eventType): array {
		return [
			'file' => __FILE__,
			'line' => __LINE__,
			'webhook_id' => $webhook->getId(),
			'webhook_name' => $webhook->getName(),
			'event_type' => $eventType,
		];
	}//end interceptionLogContext()

	/**
	 * Find webhooks configured for request interception
	 *
	 * Finds webhooks that are configured for request interception and match
	 * the specified event type.
	 *
	 * @param string $eventType Event type (e.g., 'object.creating')
	 *
	 * @return array List of matching webhooks
	 *
	 * @psalm-return list<\OCA\OpenRegister\Db\Webhook>
	 *
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity) Multiple webhook filtering conditions
	 * @SuppressWarnings(PHPMD.NPathComplexity)      Multiple filter matching paths
	 *
	 * @spec openspec/specs/webhook-payload-mapping/spec.md#requirement-request-interception-must-support-pre-event-webhooks
	 */
	private function findWebhooksForInterception(string $eventType): array {
		// Get all enabled webhooks (organisation-filtered).
		$allWebhooks = $this->stores->webhookMapper->findEnabled();

		// Filter webhooks that match this event type and are configured for interception.
		$matchingWebhooks = [];

		foreach ($allWebhooks as $webhook) {
			if ($this->matchesInterception(webhook: $webhook, eventType: $eventType) === true) {
				$matchingWebhooks[] = $webhook;
			}
		}

		return $matchingWebhooks;
	}//end findWebhooksForInterception()

	/**
	 * Check whether a webhook intercepts requests for an event type
	 *
	 * A webhook intercepts when its configuration opts into interception AND
	 * it either listens to all events or matches the given event type.
	 *
	 * @param Webhook $webhook Webhook to check
	 * @param string $eventType Event type (e.g., 'object.creating')
	 *
	 * @return bool True when the webhook intercepts requests for the event type
	 *
	 * @spec openspec/specs/webhook-payload-mapping/spec.md#request-interception-must-support-pre-event-webhooks
	 */
	private function matchesInterception(Webhook $webhook, string $eventType): bool {
		$config = $webhook->getConfigurationArray();

		// Check if webhook is configured for request interception. An owned
		// webhook never intercepts: blocking writes stays an administrator's.
		if (($config['interceptRequests'] ?? false) !== true || trim((string)$webhook->getOwner()) !== '') {
			return false;
		}

		// Check if webhook listens to this event type (empty events = all events).
		$events = $webhook->getEventsArray();
		if (empty($events) === false) {
			$eventClass = $this->eventTypeToEventClass(eventType: $eventType);
			if ($webhook->matchesEvent($eventClass) === false) {
				return false;
			}
		}

		return true;
	}//end matchesInterception()

	/**
	 * Determine whether ANY interception webhook exists for an event type
	 *
	 * Answers the question globally (tenant-agnostic) and caches the boolean
	 * in the distributed cache so the common zero-webhook case skips the
	 * table scan on every object write. A global "true" is a superset check:
	 * the caller still runs the organisation-filtered lookup to select the
	 * webhooks that actually apply. A per-organisation flag is deliberately
	 * NOT cached — one tenant's "false" must never disable another tenant's
	 * interception hooks. Cache entries are invalidated on webhook CRUD (see
	 * WebhookMapper) and expire via TTL as a safety net.
	 *
	 * @param string $eventType Event type (e.g., 'object.creating')
	 *
	 * @return bool True when at least one enabled interception webhook exists for the event type
	 *
	 * @spec openspec/specs/webhook-payload-mapping/spec.md#request-interception-must-support-pre-event-webhooks
	 */
	private function hasInterceptionWebhooks(string $eventType): bool {
		$cached = $this->dispatch->interceptionCache?->get(eventType: $eventType);
		if ($cached !== null) {
			return $cached;
		}

		// Cache miss (or no cache backend): compute the flag from a
		// tenant-agnostic scan so the cached answer is valid for every
		// organisation.
		$hasWebhooks = false;
		foreach ($this->stores->webhookMapper->findEnabledForInterceptionScan() as $webhook) {
			if ($this->matchesInterception(webhook: $webhook, eventType: $eventType) === true) {
				$hasWebhooks = true;
				break;
			}
		}

		$this->dispatch->interceptionCache?->set(eventType: $eventType, hasWebhooks: $hasWebhooks);

		return $hasWebhooks;
	}//end hasInterceptionWebhooks()

	/**
	 * Check if webhook response should be processed
	 *
	 * Determines if the webhook is configured to have its response processed
	 * to modify the incoming request.
	 *
	 * @param Webhook $webhook Webhook configuration
	 *
	 * @return bool True if response should be processed
	 */
	private function shouldProcessResponse(Webhook $webhook): bool {
		$config = $webhook->getConfigurationArray();

		// Process response if configured to do so and not async.
		return ($config['processResponse'] ?? false) === true
			&& ($config['async'] ?? false) === false;
	}//end shouldProcessResponse()

	/**
	 * Convert event type to event class name
	 *
	 * Converts a dot-notation event type to the corresponding event class name.
	 *
	 * @param string $eventType Event type (e.g., 'object.creating')
	 *
	 * @return string Event class name (e.g., 'OCA\OpenRegister\Event\ObjectCreatingEvent')
	 *
	 * @spec openspec/specs/webhook-payload-mapping/spec.md
	 */
	private function eventTypeToEventClass(string $eventType): string {
		// Convert event type to event class name.
		// Example: 'object.creating' -> 'OCA\OpenRegister\Event\ObjectCreatingEvent'.
		$parts = explode('.', $eventType);
		$entity = ucfirst($parts[0]);
		$action = ucfirst($parts[1] ?? 'created');

		return 'OCA\\OpenRegister\\Event\\' . $entity . $action . 'Event';
	}//end eventTypeToEventClass()
}//end class
