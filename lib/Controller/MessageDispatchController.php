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

use DateTimeImmutable;
use InvalidArgumentException;
use OCA\OpenRegister\AppInfo\Application;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCA\OpenRegister\Notifications\Controller\Support\ReadableObject;
use OCA\OpenRegister\Notifications\Service\Notification\ScheduledMessageScheduler;
use OCA\OpenRegister\Service\Integration\Providers\OutboundMessage;
use OCA\OpenRegister\Service\Integration\Providers\MessageDispatcherInterface;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * Outbound-messaging send controller (SMS + WhatsApp dispatch leaf).
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller
 */
class MessageDispatchController extends Controller {
	/**
	 * Constructor.
	 *
	 * @param IRequest $request HTTP request.
	 * @param MessageDispatcherInterface $provider Outbound-messaging dispatch leaf.
	 * @param ScheduledMessageScheduler|null $scheduler Stores a send with `sendAt` for the sweep.
	 * @param ObjectOperationsInterface|null $objects   Resolves the `object` a scheduled send is bound to, under the caller's read access.
	 */
	public function __construct(
		IRequest $request,
		private readonly MessageDispatcherInterface $provider,
		private readonly ?ScheduledMessageScheduler $scheduler = null,
		private readonly ?ObjectOperationsInterface $objects = null,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
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
	 * @spec openspec/specs/integration-message-dispatch/spec.md
	 */
	#[NoAdminRequired]
	public function smsSend(): JSONResponse {
		return $this->relaySend(allowedKinds: ['cmcom-sms', 'messagebird-sms', 'twilio-sms'], channel: 'sms');
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
	 * @spec openspec/specs/integration-message-dispatch/spec.md
	 */
	#[NoAdminRequired]
	public function whatsappSend(): JSONResponse {
		return $this->relaySend(allowedKinds: ['whatsapp-cloud-api', 'whatsapp-bsp'], channel: 'whatsapp');
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
	 * @param string            $channel      The channel the endpoint serves, stored on a scheduled send.
	 *
	 * @return JSONResponse
	 *
	 * @spec exclude Private helper: validates + relays to MessageDispatchProvider::dispatch;
	 *   the REST contract is owned by
	 *   messaging-dispatch-leaf/specs/integration-message-dispatch/spec.md.
	 */
	private function relaySend(array $allowedKinds, string $channel): JSONResponse {
		$source = trim((string)$this->request->getParam('source', ''));
		$path = trim((string)$this->request->getParam('path', ''));

		$refusal = $this->refuseMissingTarget(source: $source, path: $path)
			?? $this->refuseWrongChannel(source: $source, allowedKinds: $allowedKinds);
		if ($refusal !== null) {
			return $refusal;
		}

		if (trim((string)$this->request->getParam('sendAt', '')) !== '') {
			return $this->scheduleSend(channel: $channel, source: $source, path: $path);
		}

		$result = $this->sendThroughProvider(source: $source, path: $path);

		return $this->relayResult(source: $source, result: $result);
	}//end relaySend()

	/**
	 * Store a send with `sendAt` for the sweep and answer 202 with its id.
	 *
	 * A past or unreadable `sendAt` is a 400, an `object` the caller may not read
	 * is a 404, and without the scheduler the send is refused with 503 rather
	 * than sent now.
	 *
	 * @param string $channel The channel.
	 * @param string $source  The messaging source.
	 * @param string $path    The provider path.
	 *
	 * @return JSONResponse The scheduled message, or the refusal.
	 *
	 * @spec openspec/changes/send-at-on-the-messaging-leaf/specs/integration-message-dispatch/spec.md#requirement-a-send-may-be-scheduled-and-bound-to-an-object
	 */
	private function scheduleSend(string $channel, string $source, string $path): JSONResponse {
		if ($this->scheduler === null) {
			return new JSONResponse(['error' => 'scheduled sending is not available'], 503);
		}

		$object = $this->boundObject();
		if ($object instanceof JSONResponse) {
			return $object;
		}

		try {
			$scheduled = $this->scheduler->schedule(
				message: [
					'channel' => $channel,
					'source' => $source,
					'path' => $path,
					'body' => $this->arrayParam(name: 'body'),
					'headers' => $this->stringifyHeaders(headers: $this->arrayParam(name: 'headers')),
					'sendAt' => (string)$this->request->getParam('sendAt', ''),
				],
				object: $object,
				now: new DateTimeImmutable()
			);
		} catch (InvalidArgumentException $e) {
			return new JSONResponse(['error' => $e->getMessage()], 400);
		}

		return new JSONResponse($scheduled, 202);
	}//end scheduleSend()

	/**
	 * The object named by `object: {register, schema, id}`, null when none is
	 * named, or a 400/404 refusal.
	 *
	 * @return ObjectEntity|JSONResponse|null
	 *
	 * @spec openspec/changes/send-at-on-the-messaging-leaf/specs/integration-message-dispatch/spec.md#requirement-a-send-may-be-scheduled-and-bound-to-an-object
	 */
	private function boundObject(): ObjectEntity|JSONResponse|null {
		$named = $this->request->getParam('object', null);
		if ($named === null || $named === '' || $named === []) {
			return null;
		}

		$path = [];
		foreach (['register', 'schema', 'id'] as $key) {
			$value = null;
			if (is_array($named) === true) {
				$value = ($named[$key] ?? null);
			}

			if (is_scalar($value) === false || trim((string)$value) === '') {
				return new JSONResponse(['error' => 'object must name register, schema and id'], 400);
			}

			$path[$key] = (string)$value;
		}

		if ($this->objects === null) {
			return new JSONResponse(['error' => 'Object not found'], 404);
		}

		return (new ReadableObject(objects: $this->objects))->resolve(path: $path);
	}//end boundObject()

	/**
	 * Refuse a send without a source or a path.
	 *
	 * @param string $source The trimmed source slug.
	 * @param string $path The trimmed send path.
	 *
	 * @return JSONResponse|null A 400, or null when both are present.
	 *
	 * @spec exclude Private input validation; the dispatch contract is owned by the spec.
	 */
	private function refuseMissingTarget(string $source, string $path): ?JSONResponse {
		if ($source !== '' && $path !== '') {
			return null;
		}

		return new JSONResponse(['error' => 'source and path are required'], 400);
	}//end refuseMissingTarget()

	/**
	 * Refuse a source that is not valid for this channel.
	 *
	 * @param string $source The trimmed source slug.
	 * @param array<int,string> $allowedKinds The source slugs valid for this channel.
	 *
	 * @return JSONResponse|null A 400, or null when the source fits the channel.
	 *
	 * @spec exclude Private input validation; the dispatch contract is owned by the spec.
	 */
	private function refuseWrongChannel(string $source, array $allowedKinds): ?JSONResponse {
		if (in_array($source, $allowedKinds, true) === true) {
			return null;
		}

		return new JSONResponse(
			['error' => sprintf('source "%s" is not valid for this channel', $source)],
			400
		);
	}//end refuseWrongChannel()

	/**
	 * Dispatch the request's body and headers through the provider.
	 *
	 * @param string $source The validated source slug.
	 * @param string $path The send path.
	 *
	 * @return array<string,mixed> The provider's result descriptor.
	 *
	 * @spec exclude Private relay to MessageDispatchProvider::dispatch.
	 */
	private function sendThroughProvider(string $source, string $path): array {
		return $this->provider->dispatch(
			source: $source,
			message: new OutboundMessage(
				path: $path,
				body: $this->arrayParam(name: 'body'),
				headers: $this->stringifyHeaders(headers: $this->arrayParam(name: 'headers'))
			),
		);
	}//end sendThroughProvider()

	/**
	 * A request parameter that must be an array, empty when it is not.
	 *
	 * @param string $name The parameter name.
	 *
	 * @return array<mixed> The parameter, or an empty array.
	 *
	 * @spec exclude Private input coercion.
	 */
	private function arrayParam(string $name): array {
		$value = $this->request->getParam($name, []);
		if (is_array($value) === false) {
			return [];
		}

		return $value;
	}//end arrayParam()

	/**
	 * Map a degraded descriptor to a 503-with-cause, or relay the result.
	 *
	 * @param string $source The source slug.
	 * @param array<string,mixed> $result The provider's result descriptor.
	 *
	 * @return JSONResponse The relayed result or the 503.
	 *
	 * @spec exclude Private response mapping (AD-23).
	 */
	private function relayResult(string $source, array $result): JSONResponse {
		if (($result['unavailable'] ?? false) !== true) {
			return new JSONResponse($result);
		}

		return new JSONResponse(
			[
				'error' => sprintf('messaging source "%s" is not available', $source),
				'details' => ['cause' => $result['cause']],
			],
			503
		);
	}//end relayResult()

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
	private function stringifyHeaders(array $headers): array {
		$out = [];
		foreach ($headers as $key => $value) {
			if (is_scalar($value) === true) {
				$out[(string)$key] = (string)$value;
			}
		}

		return $out;
	}//end stringifyHeaders()
}//end class
