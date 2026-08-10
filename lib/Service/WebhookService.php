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

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Exception\RequestException;
use DateTime;
use OCA\OpenRegister\Db\Mapping;
use OCA\OpenRegister\Db\MappingMapper;
use OCA\OpenRegister\Db\Webhook;
use OCA\OpenRegister\Db\WebhookLog;
use OCA\OpenRegister\Db\WebhookLogMapper;
use OCA\OpenRegister\Db\WebhookMapper;
use OCA\OpenRegister\Service\Webhook\CloudEventFormatter;
use OCA\OpenRegister\Service\Webhook\WebhookInterceptionCache;
use OCP\AppFramework\Db\DoesNotExistException;
use OCA\OpenRegister\BackgroundJob\WebhookDeliveryJob;
use OCP\BackgroundJob\IJobList;
use OCP\EventDispatcher\Event;
use OCP\IRequest;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

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
class WebhookService
{

    /**
     * Webhook mapper
     *
     * @var WebhookMapper
     */
    private WebhookMapper $webhookMapper;

    /**
     * HTTP client
     *
     * @var GuzzleClient
     */
    private GuzzleClient $client;

    /**
     * Logger
     *
     * @var LoggerInterface
     */
    private LoggerInterface $logger;

    /**
     * Webhook log mapper
     *
     * @var WebhookLogMapper
     */
    private WebhookLogMapper $webhookLogMapper;

    /**
     * CloudEvent formatter
     *
     * @var CloudEventFormatter|null
     */
    private ?CloudEventFormatter $cloudEventFormatter;

    /**
     * Job list used to enqueue asynchronous webhook delivery.
     *
     * @var IJobList
     */
    private IJobList $jobList;

    /**
     * Mapping service for payload transformation
     *
     * @var MappingService
     */
    private MappingService $mappingService;

    /**
     * Mapping mapper for loading mapping entities
     *
     * @var MappingMapper
     */
    private MappingMapper $mappingMapper;

    /**
     * Cache of the "has interception webhooks" flag per event type.
     *
     * Nullable so the service stays constructible without a cache backend
     * (unit tests, degraded environments); the fast path then falls back to
     * the direct lookup.
     *
     * @var WebhookInterceptionCache|null
     */
    private ?WebhookInterceptionCache $interceptionCache;

    /**
     * Constructor
     *
     * @param WebhookMapper                 $webhookMapper       Webhook mapper
     * @param LoggerInterface               $logger              Logger
     * @param WebhookLogMapper              $webhookLogMapper    Webhook log mapper
     * @param MappingService                $mappingService      Mapping service
     * @param MappingMapper                 $mappingMapper       Mapping mapper
     * @param IJobList                      $jobList             Background job list for async delivery
     * @param CloudEventFormatter|null      $cloudEventFormatter CloudEvent formatter (optional)
     * @param WebhookInterceptionCache|null $interceptionCache   Interception-flag cache (optional)
     *
     * @return void
     */
    public function __construct(
        WebhookMapper $webhookMapper,
        LoggerInterface $logger,
        WebhookLogMapper $webhookLogMapper,
        MappingService $mappingService,
        MappingMapper $mappingMapper,
        IJobList $jobList,
        ?CloudEventFormatter $cloudEventFormatter=null,
        ?WebhookInterceptionCache $interceptionCache=null
    ) {
        $this->webhookMapper    = $webhookMapper;
        $this->logger           = $logger;
        $this->webhookLogMapper = $webhookLogMapper;
        $this->mappingService   = $mappingService;
        $this->mappingMapper    = $mappingMapper;
        $this->jobList          = $jobList;
        $this->cloudEventFormatter = $cloudEventFormatter;
        $this->interceptionCache   = $interceptionCache;
        $this->initializeHttpClient();
    }//end __construct()

    /**
     * Maximum response body size accepted from a webhook target, in bytes (1 MB).
     *
     * Larger bodies are truncated before being persisted in the webhook log to
     * prevent log-storage exhaustion when a misconfigured endpoint returns a
     * huge response.
     *
     * @var integer
     */
    private const MAX_RESPONSE_BODY_BYTES = 1048576;

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
    private function initializeHttpClient(): void
    {
        // Prepare Guzzle client configuration with TLS verification ON.
        // Redirects are followed but every Location must pass the same
        // SSRF guard that gates the initial URL — see assertSafeWebhookUri()
        // and the on_redirect callback below.
        // http_errors stays off because we handle 4xx/5xx manually downstream.
        $clientConfig = [
            'timeout'         => 30,
            'connect_timeout' => 10,
            'verify'          => true,
            'http_errors'     => false,
            'allow_redirects' => [
                'max'             => 5,
                'strict'          => true,
                'referer'         => false,
                'protocols'       => ['http', 'https'],
                'track_redirects' => true,
                'on_redirect'     => function (
                    RequestInterface $request,
                    ResponseInterface $response,
                    \Psr\Http\Message\UriInterface $uri
                ): void {
                    // Re-validate every redirect target through the same SSRF
                    // allowlist used for the initial URL. Throwing here aborts
                    // the redirect chain and propagates a RequestException to
                    // deliverWebhook(), which logs it as a delivery failure.
                    $this->assertSafeWebhookUri(uri: (string) $uri);
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
     * @param string  $uri          Full request URI to validate.
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
    private function assertSafeWebhookUri(string $uri, bool $allowPrivate=false): void
    {
        $parsed = parse_url($uri);
        if ($parsed === false) {
            throw new RuntimeException('Webhook target URL is not parseable');
        }

        $scheme = strtolower($parsed['scheme'] ?? '');
        if (in_array($scheme, ['http', 'https'], true) === false) {
            throw new RuntimeException(
                'Webhook target URL must use http or https scheme; got "'.$scheme.'"'
            );
        }

        $host = strtolower($parsed['host'] ?? '');
        if ($host === '') {
            throw new RuntimeException('Webhook target URL is missing a host');
        }

        // Per-hook opt-in: the admin deliberately allowed private/loopback
        // targets for this webhook. Scheme + host/parse checks above stay
        // enforced; only the IP-range checks below are bypassed.
        if ($allowPrivate === true) {
            return;
        }

        // ── IPv6 literal detection ──────────────────────────────────────
        // parse_url() keeps the surrounding brackets when the host is an IPv6
        // literal (e.g. "http://[::1]/" yields $host = "[::1]"). Strip them
        // before validation. A colon anywhere in $host (brackets or not) is
        // the reliable heuristic that we have an IPv6 address rather than a
        // plain hostname.
        $bareHost      = trim($host, '[]');
        $isIpv6Literal = filter_var($bareHost, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false
            || strpos($host, ':') !== false;

        if ($isIpv6Literal === true) {
            $reason = $this->blockedIpv6Reason(ip: $bareHost);
            if ($reason !== null) {
                throw new RuntimeException(
                    'Webhook target URL resolves to a blocked IP range ('.$reason.')'
                );
            }

            // Valid, non-blocked IPv6 literal — nothing more to check.
            return;
        }

        // ── IPv6 DNS (AAAA) resolution ──────────────────────────────────
        // gethostbyname() / gethostbynamel() only return A records.  Use
        // dns_get_record() to pick up AAAA answers as well.
        $aaaaRecords = @dns_get_record($host, DNS_AAAA);
        if (is_array($aaaaRecords) === true) {
            foreach ($aaaaRecords as $record) {
                $ipv6 = $record['ipv6'] ?? '';
                if ($ipv6 === '') {
                    continue;
                }

                $reason = $this->blockedIpv6Reason(ip: $ipv6);
                if ($reason !== null) {
                    throw new RuntimeException(
                        'Webhook target URL resolves to a blocked IP range ('.$reason.')'
                    );
                }
            }
        }

        // ── IPv4 resolution and range checks ───────────────────────────
        // Resolve to IPv4 for range checks. gethostbyname() returns the input
        // host unchanged on failure or when given an IP literal. To handle
        // raw IP literals (the classic SSRF case), fall back to validating
        // the host string directly when it looks like an IPv4 address.
        $resolvedIp = gethostbyname($host);
        $longIp     = ip2long($resolvedIp);
        if ($longIp === false) {
            // The gethostbyname() call returned a non-IP (real DNS failure);
            // try the host string directly so http://127.0.0.1/ still
            // resolves to a long-IP we can range-check.
            $longIp = ip2long($host);
        }

        if ($longIp === false) {
            // Couldn't resolve at all — let the request through so a real
            // DNS error surfaces as a delivery failure rather than a guard
            // rejection.
            return;
        }

        // Loopback 127.0.0.0/8.
        if (($longIp & 0xFF000000) === 0x7F000000) {
            throw new RuntimeException(
                'Webhook target URL resolves to a blocked IP range (loopback)'
            );
        }

        // RFC-1918 10.0.0.0/8.
        if (($longIp & 0xFF000000) === 0x0A000000) {
            throw new RuntimeException(
                'Webhook target URL resolves to a blocked IP range (RFC-1918)'
            );
        }

        // RFC-1918 172.16.0.0/12.
        if (($longIp & 0xFFF00000) === 0xAC100000) {
            throw new RuntimeException(
                'Webhook target URL resolves to a blocked IP range (RFC-1918)'
            );
        }

        // RFC-1918 192.168.0.0/16.
        if (($longIp & 0xFFFF0000) === 0xC0A80000) {
            throw new RuntimeException(
                'Webhook target URL resolves to a blocked IP range (RFC-1918)'
            );
        }

        // Link-local 169.254.0.0/16 — includes cloud metadata 169.254.169.254.
        if (($longIp & 0xFFFF0000) === 0xA9FE0000) {
            throw new RuntimeException(
                'Webhook target URL resolves to a blocked IP range (link-local/metadata)'
            );
        }
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
     * @SuppressWarnings(PHPMD.CyclomaticComplexity) Sequential CIDR checks require multiple branches.
     */
    private function blockedIpv6Reason(string $ip): ?string
    {
        $bin = @inet_pton($ip);
        if ($bin === false || strlen($bin) !== 16) {
            // Not a valid IPv6 address; let caller decide whether to block.
            return null;
        }

        // ::1/128 — loopback.
        if ($bin === "\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x01") {
            return 'loopback';
        }

        // ::/128 — unspecified.
        if ($bin === "\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00") {
            return 'unspecified';
        }

        // Fc00::/7 — unique local (covers fc00:: and fd00:: blocks).
        // First byte with mask 0xFE must equal 0xFC.
        if ((ord($bin[0]) & 0xFE) === 0xFC) {
            return 'unique-local';
        }

        // Fe80::/10 — link-local unicast.
        // First byte 0xFE, second byte upper 6 bits = 0x80 → mask 0xC0.
        if (ord($bin[0]) === 0xFE && (ord($bin[1]) & 0xC0) === 0x80) {
            return 'link-local';
        }

        // 2001:db8::/32 — documentation/example range (RFC 3849).
        if (substr($bin, 0, 4) === "\x20\x01\x0d\xb8") {
            return 'documentation';
        }

        // ::ffff:0:0/96 — IPv4-mapped IPv6.
        // Bytes 0-9 are zero, bytes 10-11 are 0xFFFF.
        if (substr($bin, 0, 10) === "\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00"
            && substr($bin, 10, 2) === "\xff\xff"
        ) {
            // Extract the embedded IPv4 address (bytes 12-15) and re-validate
            // it against the IPv4 block list so ::ffff:127.0.0.1 is also caught.
            $embeddedIpv4 = inet_ntop(substr($bin, 12, 4));
            if ($embeddedIpv4 !== false) {
                $longIp = ip2long($embeddedIpv4);
                if ($longIp !== false) {
                    if (($longIp & 0xFF000000) === 0x7F000000) {
                        return 'IPv4-mapped loopback';
                    }

                    if (($longIp & 0xFF000000) === 0x0A000000
                        || ($longIp & 0xFFF00000) === 0xAC100000
                        || ($longIp & 0xFFFF0000) === 0xC0A80000
                    ) {
                        return 'IPv4-mapped RFC-1918';
                    }

                    if (($longIp & 0xFFFF0000) === 0xA9FE0000) {
                        return 'IPv4-mapped link-local/metadata';
                    }
                }
            }

            // The mapped address is itself a public IPv4 — still block the
            // IPv4-mapped form to prevent protocol-confusion attacks.
            return 'IPv4-mapped';
        }//end if

        return null;
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
    private function isPrivateHost(string $host): bool
    {
        $host = strtolower($host);
        if ($host === '') {
            return false;
        }

        // ── IPv6 literal ────────────────────────────────────────────────
        // Strip brackets that parse_url() may leave around IPv6 literals.
        $bareHost      = trim($host, '[]');
        $isIpv6Literal = filter_var($bareHost, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false
            || strpos($host, ':') !== false;

        if ($isIpv6Literal === true) {
            return $this->blockedIpv6Reason(ip: $bareHost) !== null;
        }

        // ── IPv6 DNS (AAAA) ─────────────────────────────────────────────
        $aaaaRecords = @dns_get_record($host, DNS_AAAA);
        if (is_array($aaaaRecords) === true) {
            foreach ($aaaaRecords as $record) {
                $ipv6 = $record['ipv6'] ?? '';
                if ($ipv6 !== '' && $this->blockedIpv6Reason(ip: $ipv6) !== null) {
                    return true;
                }
            }
        }

        // ── IPv4 resolution ─────────────────────────────────────────────
        // Same dual-path as assertSafeWebhookUri(): resolve via DNS first,
        // then fall back to validating the host string directly so IP
        // literals (127.0.0.1, 192.168.x.y …) are matched too.
        $resolvedIp = gethostbyname($host);
        $longIp     = ip2long($resolvedIp);
        if ($longIp === false) {
            $longIp = ip2long($host);
        }

        if ($longIp === false) {
            return false;
        }

        // Match each blocked CIDR range against the resolved IP.
        // 127/8 loopback, 10/8, 172.16/12, 192.168/16, 169.254/16 link-local.
        return (($longIp & 0xFF000000) === 0x7F000000)
            || (($longIp & 0xFF000000) === 0x0A000000)
            || (($longIp & 0xFFF00000) === 0xAC100000)
            || (($longIp & 0xFFFF0000) === 0xC0A80000)
            || (($longIp & 0xFFFF0000) === 0xA9FE0000);
    }//end isPrivateHost()

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
     * @param string $url  Webhook target URL (for private-host detection).
     *
     * @return string The truncated/redacted preview.
     */
    private function previewResponseBody(string $body, string $url): string
    {
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

        return substr($body, 0, self::LOG_BODY_PREVIEW_BYTES).'... [truncated]';
    }//end previewResponseBody()

    /**
     * Cap a response body to MAX_RESPONSE_BODY_BYTES for persistent storage.
     *
     * @param string $body Raw response body.
     *
     * @return string Body limited to the response-body cap.
     */
    private function capResponseBody(string $body): string
    {
        if (strlen($body) <= self::MAX_RESPONSE_BODY_BYTES) {
            return $body;
        }

        return substr($body, 0, self::MAX_RESPONSE_BODY_BYTES).'... [truncated]';
    }//end capResponseBody()

    /**
     * Dispatch event to all matching webhooks
     *
     * @param Event  $_event    The event to dispatch (unused but provided by event system)
     * @param string $eventName Event class name
     * @param array  $payload   Event payload data
     *
     * @return void
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity) Multiple webhook dispatch conditions
     *
     * @spec openspec/specs/webhook-payload-mapping/spec.md#requirement-request-interception-must-support-pre-event-webhooks
     * @spec openspec/specs/webhook-payload-mapping/spec.md
     */
    public function dispatchEvent(Event $_event, string $eventName, array $payload): void
    {
        try {
            // Find all webhooks matching this event.
            $webhooks = $this->webhookMapper->findForEvent($eventName);
        } catch (\Exception $e) {
            // If table doesn't exist yet (migrations haven't run), silently skip webhook delivery.
            $this->logger->debug(
                message: '[WebhookService] Webhook table does not exist yet, skipping webhook delivery',
                context: [
                    'file'  => __FILE__,
                    'line'  => __LINE__,
                    'event' => $eventName,
                    'error' => $e->getMessage(),
                ]
            );
            return;
        }

        if (empty($webhooks) === true) {
            $this->logger->debug(
                message: '[WebhookService] No webhooks configured for event',
                context: [
                    'file'  => __FILE__,
                    'line'  => __LINE__,
                    'event' => $eventName,
                ]
            );
            return;
        }

        $this->logger->info(
            message: '[WebhookService] Dispatching event to webhooks',
            context: [
                'file'          => __FILE__,
                'line'          => __LINE__,
                'event'         => $eventName,
                'webhook_count' => count($webhooks),
            ]
        );

        // Enqueue delivery (including the FIRST attempt) as a background job so
        // the object-write response never blocks on an external endpoint — with
        // N webhooks each up to a 30s timeout, synchronous delivery made write
        // latency a function of third-party uptime (async-webhook-delivery /
        // ADR-009 Rule 5). WebhookDeliveryJob performs the same delivery + logging.
        foreach ($webhooks as $webhook) {
            $this->jobList->add(
                WebhookDeliveryJob::class,
                [
                    'webhook_id' => $webhook->getId(),
                    'event_name' => $eventName,
                    'payload'    => $payload,
                    'attempt'    => 1,
                ]
            );
        }
    }//end dispatchEvent()

    /**
     * Deliver webhook to target URL
     *
     * @param Webhook  $webhook           Webhook configuration
     * @param string   $eventName         Event name
     * @param array    $payload           Payload data
     * @param int      $attempt           Current attempt number (for retries)
     * @param int|null $timeoutCapSeconds Optional hard cap on connect + total timeout;
     *                                    used by the synchronous interception path
     *                                    (INTERCEPTION_TIMEOUT_SECONDS). Null keeps the
     *                                    per-webhook timeout (async deliveries).
     *
     * @return bool Success status
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)  Complex delivery with retry and error handling
     * @SuppressWarnings(PHPMD.NPathComplexity)       Multiple exception handling paths
     * @SuppressWarnings(PHPMD.ExcessiveMethodLength) Comprehensive webhook delivery with logging
     * Fallback for connection errors without response
     *
     * @spec openspec/specs/webhook-payload-mapping/spec.md
     */
    public function deliverWebhook(
        Webhook $webhook,
        string $eventName,
        array $payload,
        int $attempt=1,
        ?int $timeoutCapSeconds=null
    ): bool {
        if ($webhook->getEnabled() === false) {
            $this->logger->debug(
                message: '[WebhookService] Webhook is disabled, skipping delivery',
                context: [
                    'file'       => __FILE__,
                    'line'       => __LINE__,
                    'webhook_id' => $webhook->getId(),
                    'event'      => $eventName,
                ]
            );
            return false;
        }

        // Apply filters if configured.
        if ($this->passesFilters(webhook: $webhook, payload: $payload) === false) {
            $this->logger->debug(
                message: '[WebhookService] Webhook filters did not match, skipping delivery',
                context: [
                    'file'       => __FILE__,
                    'line'       => __LINE__,
                    'webhook_id' => $webhook->getId(),
                    'event'      => $eventName,
                ]
            );
            return false;
        }

        $webhookPayload = $this->buildPayload(
            webhook: $webhook,
            eventName: $eventName,
            payload: $payload,
            attempt: $attempt
        );

        // Create webhook log entry.
        $webhookLog = new WebhookLog();
        $webhookLog->setWebhook($webhook->getId());
        $webhookLog->setEventClass($eventName);
        $webhookLog->setPayloadArray($webhookPayload);
        $webhookLog->setUrl($webhook->getUrl());
        $webhookLog->setMethod($webhook->getMethod());
        $webhookLog->setAttempt($attempt);

        try {
            $response = $this->sendRequest(
                webhook: $webhook,
                payload: $webhookPayload,
                timeoutCapSeconds: $timeoutCapSeconds
            );

            // BUG-SVC-1: http_errors is disabled on the Guzzle client, so a 4xx/5xx
            // response does NOT throw — it lands here. Treat any non-2xx status as a
            // delivery failure (log it, record failed statistics, schedule a retry)
            // instead of silently recording it as a successful delivery.
            $statusCode = (int) $response['status_code'];
            if ($statusCode < 200 || $statusCode >= 300) {
                $webhookLog->setSuccess(false);
                $webhookLog->setStatusCode($statusCode);
                $webhookLog->setResponseBody($this->capResponseBody(body: (string) $response['body']));
                $webhookLog->setErrorMessage('Webhook endpoint returned non-2xx status: '.$statusCode);
                $webhookLog->setRequestBody(json_encode($webhookPayload));

                $this->logger->error(
                    message: '[WebhookService] Webhook delivery failed with non-2xx status',
                    context: [
                        'file'         => __FILE__,
                        'line'         => __LINE__,
                        'webhook_id'   => $webhook->getId(),
                        'webhook_name' => $webhook->getName(),
                        'event'        => $eventName,
                        'status_code'  => $statusCode,
                        'attempt'      => $attempt,
                        'max_retries'  => $webhook->getMaxRetries(),
                    ]
                );

                $this->webhookMapper->updateStatistics(webhook: $webhook, success: false);

                // Schedule retry if within retry limit.
                if ($attempt < $webhook->getMaxRetries()) {
                    $nextRetryAt = $this->calculateNextRetryTime(webhook: $webhook, attempt: $attempt);
                    $webhookLog->setNextRetryAt($nextRetryAt);
                    $this->scheduleRetry(webhook: $webhook, eventName: $eventName, _payload: $payload, attempt: $attempt + 1);
                }

                $this->webhookLogMapper->insert($webhookLog);

                return false;
            }//end if

            // Log success.
            $webhookLog->setSuccess(true);
            $webhookLog->setStatusCode($response['status_code']);
            $webhookLog->setResponseBody($response['body']);

            $this->logger->info(
                message: '[WebhookService] Webhook delivered successfully',
                context: [
                    'file'         => __FILE__,
                    'line'         => __LINE__,
                    'webhook_id'   => $webhook->getId(),
                    'webhook_name' => $webhook->getName(),
                    'event'        => $eventName,
                    'status_code'  => $response['status_code'],
                    'attempt'      => $attempt,
                ]
            );

            $this->webhookMapper->updateStatistics(webhook: $webhook, success: true);
            $this->webhookLogMapper->insert($webhookLog);

            return true;
        } catch (RequestException $e) {
            // Build detailed error message from Guzzle exception.
            $errorMessage = $e->getMessage();
            $errorDetails = [];

            // Get status code from exception if available.
            if ($e->hasResponse() !== true) {
                // Connection error or timeout.
                $errorDetails['connection_error'] = true;
                if ($e->getCode() !== 0) {
                    $errorDetails['error_code'] = $e->getCode();
                }
            }

            if ($e->hasResponse() === true) {
                $response   = $e->getResponse();
                $statusCode = $response->getStatusCode();
                $webhookLog->setStatusCode($statusCode);
                $errorDetails['status_code'] = $statusCode;

                try {
                    $responseBody = (string) $response->getBody();
                    // Cap the body before persisting it in the webhook log so
                    // a hostile endpoint can't bloat log storage. The version
                    // we echo into structured logs is shorter still and is
                    // redacted when the target is an internal/private IP — see
                    // previewResponseBody().
                    $webhookLog->setResponseBody($this->capResponseBody(body: $responseBody));
                    $errorDetails['response_body'] = $this->previewResponseBody(
                        body: $responseBody,
                        url: $webhook->getUrl()
                    );

                    // Try to parse JSON response for better error message.
                    $jsonResponse = json_decode($responseBody, true);
                    if ($jsonResponse !== null && (($jsonResponse['message'] ?? null) !== null)) {
                        $errorMessage .= ': '.$jsonResponse['message'];
                    } else if ($jsonResponse !== null && (($jsonResponse['error'] ?? null) !== null)) {
                        $errorMessage .= ': '.$jsonResponse['error'];
                    }
                } catch (\Exception $bodyException) {
                    // Ignore body reading errors.
                }//end try
            }//end if

            // Add request details to error message.
            $errorDetails['request_url']    = $webhook->getUrl();
            $errorDetails['request_method'] = $webhook->getMethod();
            $errorDetails['timeout']        = $webhook->getTimeout();

            // Store request body as JSON for retry purposes (only on failure).
            $webhookLog->setRequestBody(json_encode($webhookPayload));

            // Log failure with detailed context.
            $webhookLog->setSuccess(false);
            $webhookLog->setErrorMessage($errorMessage);

            $this->logger->error(
                message: '[WebhookService] Webhook delivery failed',
                context: [
                    'file'            => __FILE__,
                    'line'            => __LINE__,
                    'webhook_id'      => $webhook->getId(),
                    'webhook_name'    => $webhook->getName(),
                    'event'           => $eventName,
                    'error'           => $errorMessage,
                    'error_details'   => $errorDetails,
                    'attempt'         => $attempt,
                    'max_retries'     => $webhook->getMaxRetries(),
                    'exception_class' => get_class($e),
                    'exception_code'  => $e->getCode(),
                    'trace'           => $e->getTraceAsString(),
                ]
            );

            $this->webhookMapper->updateStatistics(webhook: $webhook, success: false);

            // Schedule retry if within retry limit.
            if ($attempt < $webhook->getMaxRetries()) {
                $nextRetryAt = $this->calculateNextRetryTime(webhook: $webhook, attempt: $attempt);
                $webhookLog->setNextRetryAt($nextRetryAt);
                $this->scheduleRetry(webhook: $webhook, eventName: $eventName, _payload: $payload, attempt: $attempt + 1);
            }

            // Save log entry.
            $this->webhookLogMapper->insert($webhookLog);

            return false;
        } catch (\Exception $e) {
            // Log unexpected errors.
            $webhookLog->setSuccess(false);
            $webhookLog->setErrorMessage($e->getMessage());
            $this->webhookLogMapper->insert($webhookLog);

            $this->logger->error(
                message: '[WebhookService] Unexpected error during webhook delivery',
                context: [
                    'file'       => __FILE__,
                    'line'       => __LINE__,
                    'webhook_id' => $webhook->getId(),
                    'event'      => $eventName,
                    'error'      => $e->getMessage(),
                ]
            );

            return false;
        }//end try
    }//end deliverWebhook()

    /**
     * Check if payload passes webhook filters
     *
     * @param Webhook $webhook Webhook configuration
     * @param array   $payload Event payload
     *
     * @return bool
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity) Multiple filter condition checks
     */
    private function passesFilters(Webhook $webhook, array $payload): bool
    {
        $filters = $webhook->getFiltersArray();

        if (empty($filters) === true) {
            return true;
        }

        foreach ($filters as $key => $value) {
            // Support dot notation for nested keys.
            $actualValue = $this->getNestedValue(array: $payload, key: $key);

            // If filter value is array, check if actual value is in array.
            if (is_array($value) === true) {
                if (in_array($actualValue, $value) === false) {
                    return false;
                }
            } else if ($actualValue !== $value) {
                return false;
            }
        }

        return true;
    }//end passesFilters()

    /**
     * Get nested value from array using dot notation
     *
     * @param array  $array Array to search
     * @param string $key   Dot-notated key
     *
     * @return mixed
     *
     * @spec openspec/specs/webhook-payload-mapping/spec.md
     */
    private function getNestedValue(array $array, string $key)
    {
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
     * Build webhook payload
     *
     * Builds the webhook payload using one of three strategies (in priority order):
     * 1. Mapping transformation — if webhook references a Mapping entity
     * 2. CloudEvents format — if configured via useCloudEvents
     * 3. Standard format — default
     *
     * @param Webhook $webhook   Webhook configuration
     * @param string  $eventName Event name
     * @param array   $payload   Event payload
     * @param int     $attempt   Delivery attempt number
     *
     * @return array Webhook payload in mapped, CloudEvents, or standard format.
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity) Three payload format strategies
     *
     * @spec openspec/specs/webhook-payload-mapping/spec.md#requirement-webhook-entity-must-support-an-optional-mapping-reference-for-payload-transformation
     */
    private function buildPayload(Webhook $webhook, string $eventName, array $payload, int $attempt): array
    {
        // Strategy 1: Apply mapping transformation if webhook references a Mapping entity.
        $mappingId = $webhook->getMapping();
        if ($mappingId !== null) {
            $mappedPayload = $this->applyMappingTransformation(
                mappingId: $mappingId,
                eventName: $eventName,
                payload: $payload,
                webhook: $webhook
            );

            if ($mappedPayload !== null) {
                return $mappedPayload;
            }

            // Fall through to other formats if mapping failed.
        }

        // Strategy 2: Use CloudEvents format if configured and formatter is available.
        $config         = $webhook->getConfigurationArray();
        $useCloudEvents = ($config['useCloudEvents'] ?? false) === true;

        if ($useCloudEvents === true && $this->cloudEventFormatter !== null) {
            // Add webhook metadata to payload.
            $enrichedPayload = array_merge(
                $payload,
                [
                    'webhook' => [
                        'id'   => $webhook->getUuid(),
                        'name' => $webhook->getName(),
                    ],
                    'attempt' => $attempt,
                ]
            );

            return $this->cloudEventFormatter->formatAsCloudEvent(
                eventType: $eventName,
                payload: $enrichedPayload,
                source: $config['cloudEventSource'] ?? null,
                subject: $config['cloudEventSubject'] ?? null
            );
        }

        // Strategy 3: Use standard format.
        return [
            'event'     => $eventName,
            'webhook'   => [
                'id'   => $webhook->getUuid(),
                'name' => $webhook->getName(),
            ],
            'data'      => $payload,
            'timestamp' => date('c'),
            'attempt'   => $attempt,
        ];
    }//end buildPayload()

    /**
     * Apply mapping transformation to event payload
     *
     * Loads the referenced Mapping entity and runs the event payload through
     * MappingService.executeMapping(). Returns null on failure so the caller
     * can fall back to other formats.
     *
     * @param int     $mappingId Mapping entity ID
     * @param string  $eventName Event class name
     * @param array   $payload   Raw event payload
     * @param Webhook $webhook   Webhook entity (for context)
     *
     * @return array|null Transformed payload, or null on failure
     *
     * @spec openspec/specs/webhook-payload-mapping/spec.md#requirement-webhook-entity-must-support-an-optional-mapping-reference-for-payload-transformation
     */
    private function applyMappingTransformation(
        int $mappingId,
        string $eventName,
        array $payload,
        Webhook $webhook
    ): ?array {
        try {
            $mapping = $this->mappingMapper->find($mappingId);
        } catch (DoesNotExistException $e) {
            $this->logger->warning(
                message: '[WebhookService] Webhook references missing mapping, falling back to raw payload',
                context: [
                    'file'       => __FILE__,
                    'line'       => __LINE__,
                    'webhook_id' => $webhook->getId(),
                    'mapping_id' => $mappingId,
                ]
            );
            return null;
        } catch (\Exception $e) {
            $this->logger->warning(
                message: '[WebhookService] Failed to load mapping entity',
                context: [
                    'file'       => __FILE__,
                    'line'       => __LINE__,
                    'webhook_id' => $webhook->getId(),
                    'mapping_id' => $mappingId,
                    'error'      => $e->getMessage(),
                ]
            );
            return null;
        }//end try

        // Build the mapping input with full event context.
        $mappingInput = array_merge(
            $payload,
            [
                'event'     => $this->getShortEventName(eventName: $eventName),
                'timestamp' => date('c'),
            ]
        );

        try {
            return $this->mappingService->executeMapping(mapping: $mapping, input: $mappingInput);
        } catch (\Exception $e) {
            $this->logger->warning(
                message: '[WebhookService] Mapping transformation failed, falling back to raw payload',
                context: [
                    'file'         => __FILE__,
                    'line'         => __LINE__,
                    'webhook_id'   => $webhook->getId(),
                    'mapping_id'   => $mappingId,
                    'mapping_name' => $mapping->getName(),
                    'error'        => $e->getMessage(),
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
    private function getShortEventName(string $eventName): string
    {
        $parts = explode('\\', $eventName);
        return end($parts);
    }//end getShortEventName()

    /**
     * Send HTTP request to webhook URL
     *
     * @param Webhook  $webhook           Webhook configuration
     * @param array    $payload           Payload to send
     * @param int|null $timeoutCapSeconds Optional hard cap on connect + total timeout
     *                                    (request-blocking interception deliveries)
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
    private function sendRequest(Webhook $webhook, array $payload, ?int $timeoutCapSeconds=null): array
    {
        // Per-hook opt-in (admin-set) to allow private/loopback targets for
        // local testing. Defaults to false (full SSRF blocking) when the key
        // is absent. See configuration.allowPrivateTargets.
        $allowPrivate = (bool) ($webhook->getConfigurationArray()['allowPrivateTargets'] ?? false);

        // SSRF guard: validate the webhook target before issuing the request.
        // Redirects are re-validated by the per-request on_redirect callback set
        // in $options below, which honours the same per-hook flag.
        $this->assertSafeWebhookUri(uri: $webhook->getUrl(), allowPrivate: $allowPrivate);

        $headers = array_merge(
            [
                'Content-Type' => 'application/json',
                'User-Agent'   => 'OpenRegister-Webhooks/1.0',
            ],
            $webhook->getHeadersArray()
        );

        // Add signature if secret is configured.
        if ($webhook->getSecret() !== null) {
            $signature = $this->generateSignature(payload: $payload, secret: $webhook->getSecret());
            $headers['X-Webhook-Signature'] = $signature;
        }

        $options = [
            'headers' => $headers,
            'timeout' => $webhook->getTimeout(),
        ];

        // Hard-cap connect + total timeout for request-blocking deliveries
        // (interception). The cap never RAISES a webhook's own shorter timeout,
        // but a non-positive per-webhook timeout (Guzzle: 0 = wait forever)
        // must not escape the cap either.
        if ($timeoutCapSeconds !== null) {
            $webhookTimeout     = (int) $webhook->getTimeout();
            $options['timeout'] = $timeoutCapSeconds;
            if ($webhookTimeout > 0 && $webhookTimeout < $timeoutCapSeconds) {
                $options['timeout'] = $webhookTimeout;
            }

            $options['connect_timeout'] = $timeoutCapSeconds;
        }

        // Override the shared client's redirect guard per-request so the
        // on_redirect callback can see this hook's allowPrivate flag. Mirrors
        // the client default (max/strict/protocols) from initializeHttpClient();
        // the only difference is the flag-aware re-validation. The shared client
        // default keeps allowPrivate = false as the safe fallback.
        $options['allow_redirects'] = [
            'max'             => 5,
            'strict'          => true,
            'referer'         => false,
            'protocols'       => ['http', 'https'],
            'track_redirects' => true,
            'on_redirect'     => function (
                RequestInterface $request,
                ResponseInterface $response,
                \Psr\Http\Message\UriInterface $uri
            ) use ($allowPrivate): void {
                $this->assertSafeWebhookUri(uri: (string) $uri, allowPrivate: $allowPrivate);
            },
        ];

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
            'body'        => $this->capResponseBody(body: (string) $response->getBody()),
        ];
    }//end sendRequest()

    /**
     * Generate HMAC signature for payload
     *
     * @param array  $payload Payload to sign
     * @param string $secret  Secret key
     *
     * @return string
     */
    private function generateSignature(array $payload, string $secret): string
    {
        return hash_hmac('sha256', json_encode($payload), $secret);
    }//end generateSignature()

    /**
     * Schedule retry for failed webhook delivery
     *
     * The retry is handled by the WebhookRetryJob cron job which runs every 5 minutes
     * and checks for failed webhook logs with next_retry_at timestamps that have passed.
     * The retry delay is stored in the webhook log's next_retry_at field using
     * exponential backoff based on the webhook's retry policy.
     *
     * @param Webhook $webhook   Webhook configuration
     * @param string  $eventName Event name
     * @param array   $_payload  Payload data (unused but required for retry tracking)
     * @param int     $attempt   Next attempt number
     *
     * @return void
     *
     * @spec openspec/specs/webhook-payload-mapping/spec.md#requirement-webhook-testing-must-support-dry-run-delivery
     */
    private function scheduleRetry(Webhook $webhook, string $eventName, array $_payload, int $attempt): void
    {
        $delay = $this->calculateRetryDelay(webhook: $webhook, attempt: $attempt);

        $this->logger->info(
            message: '[WebhookService] Scheduling webhook retry',
            context: [
                'file'         => __FILE__,
                'line'         => __LINE__,
                'webhook_id'   => $webhook->getId(),
                'webhook_name' => $webhook->getName(),
                'event'        => $eventName,
                'attempt'      => $attempt,
                'delay'        => $delay,
            ]
        );

        // Note: Retry is handled by WebhookRetryJob cron job.
        // The next_retry_at timestamp is already set in the webhook log entry.
        // No need to schedule a job here - the cron job will pick it up.
    }//end scheduleRetry()

    /**
     * Calculate next retry timestamp
     *
     * @param Webhook $webhook Webhook configuration
     * @param int     $attempt Current attempt number
     *
     * @return DateTime Next retry timestamp
     */
    private function calculateNextRetryTime(Webhook $webhook, int $attempt): DateTime
    {
        $delay     = $this->calculateRetryDelay(webhook: $webhook, attempt: $attempt);
        $nextRetry = new DateTime();
        $nextRetry->modify('+'.$delay.' seconds');

        return $nextRetry;
    }//end calculateNextRetryTime()

    /**
     * Calculate retry delay based on retry policy
     *
     * @param Webhook $webhook Webhook configuration
     * @param int     $attempt Attempt number
     *
     * @return int Delay in seconds
     *
     * @spec openspec/specs/webhook-payload-mapping/spec.md#requirement-webhook-testing-must-support-dry-run-delivery
     */
    private function calculateRetryDelay(Webhook $webhook, int $attempt): int
    {
        switch ($webhook->getRetryPolicy()) {
            case 'exponential':
                // Exponential backoff: 2^attempt minutes.
                return pow(2, $attempt) * 60;

            case 'linear':
                // Linear backoff: attempt * 5 minutes.
                return $attempt * 300;

            case 'fixed':
                // Fixed delay: 5 minutes.
                return 300;

            default:
                return 300;
        }//end switch
    }//end calculateRetryDelay()

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
     * @param IRequest $request   The HTTP request
     * @param string   $eventType The event type (e.g., 'object.creating')
     *
     * @return array Modified request data or original request data
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity) Complex request interception logic
     * @SuppressWarnings(PHPMD.NPathComplexity)      Multiple webhook processing paths
     * Fallback when formatter is unavailable
     *
     * @spec openspec/specs/webhook-payload-mapping/spec.md#request-interception-must-support-pre-event-webhooks
     *   (finds before-event webhooks for the event type, formats the request as a CloudEvent, delivers to each,
     *   and continues past per-webhook failures, returning the request data)
     */
    public function interceptRequest(IRequest $request, string $eventType): array
    {
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

        // Format request as CloudEvent if formatter is available.
        // Default to basic request data, override with CloudEvent format if formatter available.
        $cloudEvent = [
            'type'   => $eventType,
            'method' => $request->getMethod(),
            'path'   => $request->getPathInfo(),
            'body'   => $request->getParams(),
        ];
        if ($this->cloudEventFormatter !== null) {
            $cloudEvent = $this->cloudEventFormatter->formatRequestAsCloudEvent(
                request: $request,
                eventType: $eventType
            );
        }

        // Get original request data.
        $requestData  = $request->getParams();
        $modifiedData = $requestData;

        // Process each webhook.
        foreach ($webhooks as $webhook) {
            try {
                // Convert CloudEvent to standard webhook payload format.
                $webhookPayload = [
                    'objectType' => 'request',
                    'action'     => $eventType,
                    'cloudEvent' => $cloudEvent,
                ];

                // Deliver webhook. Interception blocks the object write, so the
                // delivery timeout is capped hard — see INTERCEPTION_TIMEOUT_SECONDS.
                $success = $this->deliverWebhook(
                    webhook: $webhook,
                    eventName: $eventType,
                    payload: $webhookPayload,
                    attempt: 1,
                    timeoutCapSeconds: self::INTERCEPTION_TIMEOUT_SECONDS
                );

                // Process response if webhook is configured to handle responses.
                // Note: This is currently limited as we're using fire-and-forget delivery.
                // TODO: Implement response handling if needed.
                if ($success === true && $this->shouldProcessResponse(webhook: $webhook) === true) {
                    $this->logger->info(
                        message: '[WebhookService] Webhook delivery successful but response processing not yet implemented',
                        context: [
                            'file'       => __FILE__,
                            'line'       => __LINE__,
                            'webhook_id' => $webhook->getId(),
                            'event_type' => $eventType,
                        ]
                    );
                }
            } catch (\Exception $e) {
                // Log failure but continue processing other webhooks.
                $this->logger->error(
                    message: '[WebhookService] Failed to deliver webhook during request interception',
                    context: [
                        'file'         => __FILE__,
                        'line'         => __LINE__,
                        'webhook_id'   => $webhook->getId(),
                        'webhook_name' => $webhook->getName(),
                        'event_type'   => $eventType,
                        'error'        => $e->getMessage(),
                    ]
                );

                // Continue processing other webhooks even if one fails.
                continue;
            }//end try
        }//end foreach

        return $modifiedData;
    }//end interceptRequest()

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
    private function findWebhooksForInterception(string $eventType): array
    {
        // Get all enabled webhooks (organisation-filtered).
        $allWebhooks = $this->webhookMapper->findEnabled();

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
     * @param Webhook $webhook   Webhook to check
     * @param string  $eventType Event type (e.g., 'object.creating')
     *
     * @return bool True when the webhook intercepts requests for the event type
     *
     * @spec openspec/specs/webhook-payload-mapping/spec.md#request-interception-must-support-pre-event-webhooks
     */
    private function matchesInterception(Webhook $webhook, string $eventType): bool
    {
        $config = $webhook->getConfigurationArray();

        // Check if webhook is configured for request interception.
        if (($config['interceptRequests'] ?? false) !== true) {
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
    private function hasInterceptionWebhooks(string $eventType): bool
    {
        $cached = $this->interceptionCache?->get(eventType: $eventType);
        if ($cached !== null) {
            return $cached;
        }

        // Cache miss (or no cache backend): compute the flag from a
        // tenant-agnostic scan so the cached answer is valid for every
        // organisation.
        $hasWebhooks = false;
        foreach ($this->webhookMapper->findEnabledForInterceptionScan() as $webhook) {
            if ($this->matchesInterception(webhook: $webhook, eventType: $eventType) === true) {
                $hasWebhooks = true;
                break;
            }
        }

        $this->interceptionCache?->set(eventType: $eventType, hasWebhooks: $hasWebhooks);

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
    private function shouldProcessResponse(Webhook $webhook): bool
    {
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
    private function eventTypeToEventClass(string $eventType): string
    {
        // Convert event type to event class name.
        // Example: 'object.creating' -> 'OCA\OpenRegister\Event\ObjectCreatingEvent'.
        $parts  = explode('.', $eventType);
        $entity = ucfirst($parts[0]);
        $action = ucfirst($parts[1] ?? 'created');

        return 'OCA\\OpenRegister\\Event\\'.$entity.$action.'Event';
    }//end eventTypeToEventClass()
}//end class
