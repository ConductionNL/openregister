<?php

/**
 * MessageDispatchController — outbound-messaging send endpoints backed by the
 * MessageDispatchProvider integration leaf (external, OpenConnector-routed).
 *
 * Surface:
 *   - POST /api/integrations/sms/send       — send one SMS via a named source
 *   - POST /api/integrations/whatsapp/send  — send one WhatsApp message via a named source
 *
 * Both endpoints are thin transport relays. The request body carries
 * `{ source, path, body, headers? }` — the consuming app (pipelinq's
 * `SmsAdapter` / `WhatsAppAdapter`) composes the vendor-shaped `body` + the
 * relative `path`, picks the `source` (one of the seeded
 * cmcom-sms / messagebird-sms / twilio-sms / whatsapp-cloud-api /
 * whatsapp-bsp sources), and owns ALL orchestration: provider selection +
 * failover, the STOP / opt-out webhook receiver, WhatsApp template-approval,
 * the 24h session window, consent + budget gating, dedupe, and delivery-status
 * reconciliation. This controller only POSTs the message through the
 * admin-owned OpenConnector source and round-trips the raw provider response.
 *
 * Like the KvK / OpenCorporates / BRP leaves, the OpenConnector app carries
 * the source + credentials; when a source (or its credential) is missing the
 * provider returns the 4-state cause (`openconnector-down` /
 * `openconnector-source-missing` / `provider-auth` / `upstream-service-down`)
 * which this controller relays as a 503 with `details.cause` so a consuming
 * app renders the right banner (AD-23) — never a fatal.
 *
 * SSRF: the base URL is admin-owned on the source; the `source` slug is
 * validated against a fixed allow-list in the provider; only the
 * operator-composed `path` (e.g. an account-id / phone-number-id segment) and
 * the message `body` come from the caller. No end-user input reaches the base
 * URL.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 * @link    https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Controller;

use OCA\OpenRegister\Service\Integration\Providers\MessageDispatchProvider;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * Outbound-messaging send controller (SMS + WhatsApp dispatch leaf).
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller
 */
class MessageDispatchController extends Controller
{
    /**
     * Constructor.
     *
     * @param string                  $appName  App id.
     * @param IRequest                $request  HTTP request.
     * @param MessageDispatchProvider $provider Outbound-messaging dispatch leaf.
     */
    public function __construct(
        string $appName,
        IRequest $request,
        private readonly MessageDispatchProvider $provider,
    ) {
        parent::__construct(appName: $appName, request: $request);
    }//end __construct()

    /**
     * Send one SMS via a named OpenConnector source.
     *
     * Body params:
     *   - `source`  (required) — one of `cmcom-sms` / `messagebird-sms` / `twilio-sms`.
     *   - `body`    (required) — the vendor-shaped request payload.
     *   - `path`    (required) — the send path relative to the source base URL.
     *   - `headers` (optional) — extra request headers.
     *
     * @return JSONResponse `{ status: 'sent', source, response }` on success,
     *                      a 400 when `source`/`path` are missing, or a 503
     *                      with `details.cause` when the source is
     *                      unconfigured/down (AD-23).
     *
     * @no-admin-idor-exempt No per-object resource: dispatches via the admin-owned source (base URL admin-configured,
     *   source checked against a fixed per-channel allowlist); no OpenRegister object id.
     *
     * @spec openspec/changes/messaging-dispatch-leaf/specs/integration-message-dispatch/spec.md
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function smsSend(): JSONResponse
    {
        return $this->relaySend(allowedKinds: ['cmcom-sms', 'messagebird-sms', 'twilio-sms']);
    }//end smsSend()

    /**
     * Send one WhatsApp message via a named OpenConnector source.
     *
     * Body params:
     *   - `source`  (required) — one of `whatsapp-cloud-api` / `whatsapp-bsp`.
     *   - `body`    (required) — the Meta-shaped request payload (template or free-form).
     *   - `path`    (required) — the send path relative to the source base URL.
     *   - `headers` (optional) — extra request headers.
     *
     * @return JSONResponse `{ status: 'sent', source, response }` on success,
     *                      a 400 when `source`/`path` are missing, or a 503
     *                      with `details.cause` when the source is
     *                      unconfigured/down (AD-23).
     *
     * @no-admin-idor-exempt No per-object resource: dispatches via the admin-owned source (base URL admin-configured,
     *   source checked against a fixed per-channel allowlist); no OpenRegister object id.
     *
     * @spec openspec/changes/messaging-dispatch-leaf/specs/integration-message-dispatch/spec.md
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function whatsappSend(): JSONResponse
    {
        return $this->relaySend(allowedKinds: ['whatsapp-cloud-api', 'whatsapp-bsp']);
    }//end whatsappSend()

    /**
     * Shared send relay — validates the request, gates the `source` to the
     * channel's allowed kinds, dispatches through the provider, and maps the
     * degraded descriptor to a 503-with-cause.
     *
     * @param array<int,string> $allowedKinds The source slugs valid for this
     *                                        channel (SMS vs WhatsApp), so an
     *                                        SMS endpoint can't be used to send
     *                                        a WhatsApp message and vice versa.
     *
     * @return JSONResponse
     *
     * @spec exclude Private helper: validates + relays to MessageDispatchProvider::dispatch;
     *   the REST contract is owned by
     *   messaging-dispatch-leaf/specs/integration-message-dispatch/spec.md.
     */
    private function relaySend(array $allowedKinds): JSONResponse
    {
        $source  = trim((string) $this->request->getParam('source', ''));
        $path    = trim((string) $this->request->getParam('path', ''));
        $body    = $this->request->getParam('body', []);
        $headers = $this->request->getParam('headers', []);

        if ($source === '' || $path === '') {
            return new JSONResponse(['error' => 'source and path are required'], 400);
        }

        if (in_array($source, $allowedKinds, true) === false) {
            return new JSONResponse(
                ['error' => sprintf('source "%s" is not valid for this channel', $source)],
                400
            );
        }

        if (is_array($body) === false) {
            $body = [];
        }

        if (is_array($headers) === false) {
            $headers = [];
        }

        $result = $this->provider->dispatch(
            source: $source,
            body: $body,
            path: $path,
            headers: $this->stringifyHeaders(headers: $headers),
        );

        if (($result['unavailable'] ?? false) === true) {
            return new JSONResponse(
                [
                    'error'   => sprintf('messaging source "%s" is not available', $source),
                    'details' => ['cause' => $result['cause']],
                ],
                503
            );
        }

        return new JSONResponse($result);
    }//end relaySend()

    /**
     * Coerce a headers bag to `array<string,string>` — the dispatch contract
     * requires string header values.
     *
     * @param array<mixed> $headers Raw headers from the request.
     *
     * @return array<string,string>
     *
     * @spec exclude Private input coercion; the dispatch contract is owned by the spec.
     */
    private function stringifyHeaders(array $headers): array
    {
        $out = [];
        foreach ($headers as $key => $value) {
            if (is_scalar($value) === true) {
                $out[(string) $key] = (string) $value;
            }
        }

        return $out;
    }//end stringifyHeaders()
}//end class
