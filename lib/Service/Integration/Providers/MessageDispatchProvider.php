<?php

/**
 * MessageDispatchProvider — outbound-messaging dispatch leaf, exposed through
 * the IntegrationProvider contract. The OR/OpenConnector half of pipelinq's
 * SMS + WhatsApp outbound groundwork.
 *
 * Storage strategy is `external` (AD-4 / AD-22): there is no local link table.
 * This is a stateless, side-effecting **send** leaf — it POSTs one message to
 * one named OpenConnector source (the provider's credentials + base URL live
 * on that source, not here) and round-trips the raw provider response. Every
 * upstream call is delegated to {@see ExternalIntegrationRouter}, which
 * resolves the declared source, makes the call, and surfaces structured
 * failures via {@see ProviderUnavailableException} (AD-23). The leaf never
 * carries an HTTP client and never handles a vendor credential.
 *
 * Unlike the KvK / BRP read leaves, the target source is **chosen per call**
 * by the caller (cmcom-sms / messagebird-sms / twilio-sms /
 * whatsapp-cloud-api / whatsapp-bsp) — the seeded sources from the paired
 * OpenConnector `seed-messaging-sources` change. The source slug is validated
 * against that fixed allow-list before each dispatch, so a caller can never
 * point the leaf at an arbitrary source (no SSRF / source-confusion surface).
 *
 * SCOPE — this leaf is the raw transport only. Everything that makes outbound
 * messaging an orchestration STAYS in the consuming app (pipelinq's
 * `SmsAdapter` / `WhatsAppAdapter`): provider selection + failover ordering,
 * the STOP / opt-out webhook receiver, WhatsApp template-approval, the 24h
 * customer-service session window, consent + budget gating, dedupe, and
 * delivery-status reconciliation. This is the deliberate split mirrored on the
 * read side — BRP kept its BSN elfproef + masking app-side, KvK kept its
 * Dutch→prospect mapping app-side; this leaf keeps only the centralised
 * dispatch + credentials (ADR-022).
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Integration\Providers
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/messaging-dispatch-leaf/tasks.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Integration\Providers;

use OCA\OpenRegister\Exception\ProviderUnavailableException;
use OCA\OpenRegister\Service\Integration\AbstractIntegrationProvider;
use OCA\OpenRegister\Service\Integration\ExternalIntegrationRouter;
use OCP\App\IAppManager;
use OCP\IL10N;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Outbound-messaging dispatch integration provider — external,
 * OpenConnector-backed, side-effecting send.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) Composes the external
 *   router + app manager + l10n + logger + the ProviderUnavailableException
 *   cause vocabulary; each is required for the degrade-don't-throw dispatch
 *   surface (AD-23).
 */
class MessageDispatchProvider extends AbstractIntegrationProvider {

	/**
	 * Default OpenConnector source id this provider advertises (the
	 * IntegrationProvider contract expects a single declared source; the real
	 * target is chosen per call from {@see ALLOWED_SOURCES}).
	 *
	 * @var string
	 */
	public const SOURCE_ID = 'whatsapp-cloud-api';

	/**
	 * NC app that must be installed for this integration to function (it
	 * carries the messaging sources + credentials).
	 *
	 * @var string
	 */
	private const REQUIRED_APP = 'openconnector';

	/**
	 * The fixed allow-list of seeded messaging source slugs a caller may
	 * dispatch through. A dispatch to any other source is rejected before the
	 * router is touched, so the leaf can never be pointed at an arbitrary
	 * source.
	 *
	 * @var array<int,string>
	 */
	public const ALLOWED_SOURCES = [
		'cmcom-sms',
		'messagebird-sms',
		'twilio-sms',
		'whatsapp-cloud-api',
		'whatsapp-bsp',
	];

	/**
	 * The source id resolved for the current dispatch. Mutated by
	 * {@see dispatch()} immediately before each {@see ExternalIntegrationRouter::call}
	 * so the router resolves the caller-chosen source via
	 * {@see getOpenConnectorSource()}. Defaults to {@see SOURCE_ID}.
	 *
	 * @var string
	 */
	private string $targetSource = self::SOURCE_ID;

	/**
	 * Constructor.
	 *
	 * @param ExternalIntegrationRouter $router External-call router.
	 * @param IAppManager $appManager NC app manager (isEnabled check).
	 * @param IL10N $l10n Localisation.
	 * @param LoggerInterface $logger Logger for degraded paths.
	 *
	 * @return void
	 */
	public function __construct(
		private ExternalIntegrationRouter $router,
		private IAppManager $appManager,
		private IL10N $l10n,
		private LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Stable provider id.
	 *
	 * @return string
	 */
	public function getId(): string {
		return 'message-dispatch';
	}//end getId()

	/**
	 * Human-readable label shown in the admin UI.
	 *
	 * @return string
	 */
	public function getLabel(): string {
		return $this->l10n->t('Outbound messaging (SMS / WhatsApp)');
	}//end getLabel()

	/**
	 * MDI icon name for the tab / widget.
	 *
	 * @return string
	 */
	public function getIcon(): string {
		return 'MessageText';
	}//end getIcon()

	/**
	 * Named group this integration belongs to (AD-16).
	 *
	 * @return string|null
	 */
	public function getGroup(): ?string {
		return 'external';
	}//end getGroup()

	/**
	 * Nextcloud app that must be installed for this integration to function —
	 * OpenConnector carries the messaging sources + credentials.
	 *
	 * @return string|null
	 */
	public function getRequiredApp(): ?string {
		return self::REQUIRED_APP;
	}//end getRequiredApp()

	/**
	 * Storage strategy (AD-22) — `external`: no local link table; this is a
	 * stateless send leaf routed through OpenConnector.
	 *
	 * @return string
	 */
	public function getStorageStrategy(): string {
		return 'external';
	}//end getStorageStrategy()

	/**
	 * OpenConnector source id this provider routes the current call through
	 * (AD-4). Returns the per-call {@see $targetSource}, set by
	 * {@see dispatch()} from the validated allow-list.
	 *
	 * @return string|null
	 */
	public function getOpenConnectorSource(): ?string {
		return $this->targetSource;
	}//end getOpenConnectorSource()

	/**
	 * Whether the integration is available — true iff OpenConnector is
	 * installed (it owns the messaging sources + credentials). The router
	 * still degrades gracefully if a source itself is missing or down.
	 *
	 * @return bool
	 */
	public function isEnabled(): bool {
		return $this->appManager->isInstalled(self::REQUIRED_APP);
	}//end isEnabled()

	/**
	 * Auth requirements descriptor. `type: 'external'` — the provider
	 * credential (CM.com product token / MessageBird access key / Twilio
	 * Basic / Meta bearer / BSP token) is configured on the matching
	 * OpenConnector source, not here.
	 *
	 * @return array<string,mixed>
	 *
	 * @spec openspec/changes/messaging-dispatch-leaf/tasks.md
	 */
	public function authRequirements(): array {
		return [
			'type' => 'external',
			'configuredVia' => 'openconnector',
			'sources' => self::ALLOWED_SOURCES,
			'supports' => ['apikey'],
		];
	}//end authRequirements()

	/**
	 * The IntegrationProvider read-path. This leaf is a send-only surface and
	 * exposes no listable linked things, so `list()` always returns an empty
	 * set (the registry contract requires the method; the dispatch surface is
	 * {@see dispatch()}).
	 *
	 * @param string $register Ignored (send-only leaf).
	 * @param string $schema Ignored (send-only leaf).
	 * @param string $objectId Ignored (send-only leaf).
	 * @param array<string,mixed> $filters Ignored (send-only leaf).
	 *
	 * @return array<int,array<string,mixed>>
	 *
	 * @spec openspec/changes/messaging-dispatch-leaf/tasks.md
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) register/schema/objectId/
	 *   filters are mandated by the IntegrationProvider contract; this leaf is
	 *   send-only so they are intentionally unused.
	 */
	public function list(string $register, string $schema, string $objectId, array $filters = []): array {
		return [];
	}//end list()

	/**
	 * Dispatch one outbound message via a named OpenConnector source.
	 *
	 * POSTs `$body` to `$path` (relative to the source's admin-owned base URL)
	 * through the {@see ExternalIntegrationRouter}, returning the raw provider
	 * response. The leaf does NOT interpret the body shape — the consuming app
	 * composes the vendor-specific payload (Twilio form fields, Meta
	 * `messaging_product` JSON, CM.com message envelope, …) so the same leaf
	 * serves every provider. Degrades null-safely to `{ unavailable, cause }`
	 * rather than throwing, so a consuming app never fatals on a missing/down
	 * source (AD-23).
	 *
	 * @param string $source The seeded source slug to send through
	 *                       (MUST be in {@see ALLOWED_SOURCES}).
	 * @param array<string,mixed> $body The provider-shaped request body the
	 *                                  consuming app composed.
	 * @param string $path Path relative to the source base URL
	 *                     (e.g. `2010-04-01/Accounts/{SID}/Messages.json`,
	 *                     `{PhoneNumberID}/messages`, `message`).
	 * @param array<string,string> $headers Optional extra request headers
	 *                                      (e.g. a per-call Content-Type).
	 *
	 * @return array<string,mixed> `{ status: 'sent', response }` on success, or
	 *                             `{ unavailable: true, cause }` when the source
	 *                             is unconfigured/down or the slug is rejected.
	 *
	 * @spec openspec/changes/messaging-dispatch-leaf/tasks.md
	 */
	public function dispatch(string $source, array $body, string $path, array $headers = []): array {
		$source = trim($source);
		if (in_array($source, self::ALLOWED_SOURCES, true) === false) {
			$this->logger->warning(
				'MessageDispatchProvider::dispatch rejected an unknown source slug.'
			);
			return $this->degraded(cause: ProviderUnavailableException::CAUSE_OPENCONNECTOR_SOURCE_MISSING);
		}

		$this->targetSource = $source;

		$requestHeaders = $headers;
		if (isset($requestHeaders['Accept']) === false) {
			$requestHeaders['Accept'] = 'application/json';
		}

		try {
			$response = $this->router->call(
				provider: $this,
				method: 'POST',
				path: ltrim($path, '/'),
				options: [
					'body' => $body,
					'headers' => $requestHeaders,
				]
			);
		} catch (ProviderUnavailableException $e) {
			return $this->degraded(cause: $e->getCause());
		} catch (Throwable $e) {
			// Never log the body — a message body may carry PII.
			$this->logger->warning('MessageDispatchProvider::dispatch failed (transport).');
			return $this->degraded(cause: ProviderUnavailableException::CAUSE_UPSTREAM_SERVICE_DOWN);
		} finally {
			// Reset to the advertised default so a later contract caller
			// (health/probe) never inherits a stale per-call source.
			$this->targetSource = self::SOURCE_ID;
		}//end try

		return [
			'status' => 'sent',
			'source' => $source,
			'response' => $response,
		];
	}//end dispatch()

	/**
	 * Health descriptor — defers to the router's probe so admin UI / OCS
	 * capabilities report the same status runtime callers would see (probes
	 * the advertised default source).
	 *
	 * @return array{status: string, authStatus: string, message: ?string}
	 *
	 * @spec exclude Thin delegation to ExternalIntegrationRouter::probe
	 *              (annotated to pluggable-integration-registry task-4);
	 *              carries no provider-specific health behaviour.
	 */
	public function health(): array {
		return $this->router->probe(provider: $this);
	}//end health()

	/**
	 * Build the degraded envelope so the dispatch shape stays stable across
	 * success + degraded paths (AD-23).
	 *
	 * @param string $cause One of the ProviderUnavailableException CAUSE_* values.
	 *
	 * @return array<string,mixed>
	 */
	private function degraded(string $cause): array {
		return [
			'unavailable' => true,
			'cause' => $cause,
		];
	}//end degraded()
}//end class
