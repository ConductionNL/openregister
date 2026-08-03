<?php

/**
 * OpenRegister AppHost — Generic Store Service
 *
 * Engine-owned client for the ADR-080 store plane. A "store" is a remote
 * OpenRegister instance exposing installable items over its objects API; this
 * service is the DISCOVERY half of the contract (configure / search / resolve).
 * INSTALL is deliberately NOT here — cloning an application template, enabling
 * a connector adapter and instantiating an agent template are different
 * operations with different authorization, so each app keeps its own install
 * action and calls resolve() for the payload.
 *
 * Generalised from openbuild's RemoteTemplateStoreService (ADR-080 Context).
 * That implementation reached OpenRegister's SSRF guard through a dynamic
 * class-string with a weaker local fallback, because it lived in the wrong app.
 * Here the guard is a direct call: in OpenRegister the class is always present,
 * so there is no degraded path to get wrong.
 *
 * Security (ADR-005 / ADR-080):
 *   - Every outbound URL is SSRF-guarded by SecurityService::assertSafeFetchUrl
 *     (rejects private/reserved/loopback hosts and non-http(s) schemes),
 *     fail-closed.
 *   - Redirects are NEVER followed. assertSafeFetchUrl validates the URL at one
 *     point in time; following a 3xx would let a public host redirect to a
 *     private/link-local/metadata address (or exploit DNS rebinding between
 *     validation and connect) with the registry Bearer token attached.
 *   - The token is sent only as a Bearer header and is never returned to
 *     callers; upstream errors are logged server-side and mapped to generic
 *     outcomes so a registry's internals never reach the browser.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\AppHost\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 *
 * @spec openspec/specs/apphost-store-plane/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\AppHost\Service;

use OCA\OpenRegister\Service\SecurityService;
use OCP\Http\Client\IClientService;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Read-only client for a remote OpenRegister-backed store (ADR-080).
 *
 * @spec openspec/specs/apphost-store-plane/spec.md
 */
class GenericStoreService
{
    /**
     * Outcome: the request succeeded.
     */
    public const OUTCOME_OK = 'ok';

    /**
     * Outcome: no registry configured — no network call was made.
     */
    public const OUTCOME_NOT_CONFIGURED = 'not_configured';

    /**
     * Outcome: registry unreachable / timed out / non-2xx / redirected.
     */
    public const OUTCOME_UNREACHABLE = 'store_unreachable';

    /**
     * Outcome: registry returned an unparseable / unexpected body.
     */
    public const OUTCOME_INVALID = 'store_invalid_response';

    /**
     * Connect + request timeout (seconds) for every remote fetch.
     */
    private const TIMEOUT = 10;

    /**
     * Maximum cards returned by a single search.
     */
    private const SEARCH_LIMIT = 50;

    /**
     * Constructor.
     *
     * @param IClientService  $clientService Nextcloud HTTP client factory.
     * @param IAppConfig      $appConfig     App config store (registry url / token / register).
     * @param LoggerInterface $logger        PSR logger — server-side diagnostics only.
     *
     * @return void
     */
    public function __construct(
        private readonly IClientService $clientService,
        private readonly IAppConfig $appConfig,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Whether a remote registry is configured for this store (non-empty base URL).
     *
     * @param StoreDescriptor $descriptor The calling app's store parameters.
     *
     * @return bool
     *
     * @spec openspec/specs/apphost-store-plane/spec.md
     */
    public function isConfigured(StoreDescriptor $descriptor): bool
    {
        return trim($this->registryUrl(descriptor: $descriptor)) !== '';

    }//end isConfigured()

    /**
     * Search the remote store.
     *
     * Returns `not_configured` with an empty card list and makes NO network
     * call when no registry is set — the ADR-080 Decision 4 fallback that lets
     * a store page render the app's built-in items instead.
     *
     * @param StoreDescriptor $descriptor The calling app's store parameters.
     * @param string|null     $query      Optional free-text search term.
     * @param string|null     $kind       Optional `kind` discriminator filter (ADR-080 Decision 5).
     *
     * @return array{outcome: string, cards: array<int, array<string, mixed>>}
     *
     * @spec openspec/specs/apphost-store-plane/spec.md
     */
    public function search(StoreDescriptor $descriptor, ?string $query=null, ?string $kind=null): array
    {
        if ($this->isConfigured(descriptor: $descriptor) === false) {
            return ['outcome' => self::OUTCOME_NOT_CONFIGURED, 'cards' => []];
        }

        $params = ['_limit' => self::SEARCH_LIMIT];
        if ($query !== null && trim($query) !== '') {
            $params['_search'] = trim($query);
        }

        if ($kind !== null && trim($kind) !== '') {
            $params['kind'] = trim($kind);
        }

        $result = $this->fetch(descriptor: $descriptor, params: $params);
        if ($result['outcome'] !== self::OUTCOME_OK) {
            return ['outcome' => $result['outcome'], 'cards' => []];
        }

        $cards = [];
        foreach ($result['results'] as $object) {
            if (is_array($object) === true) {
                $cards[] = $this->normaliseCard(descriptor: $descriptor, object: $object);
            }
        }

        return ['outcome' => self::OUTCOME_OK, 'cards' => $cards];

    }//end search()

    /**
     * Resolve a single remote item by slug, returning its FULL payload for the
     * calling app's install action.
     *
     * @param StoreDescriptor $descriptor The calling app's store parameters.
     * @param string          $slug       The item slug.
     *
     * @return array<string, mixed>|null The full remote object, or null when unresolved / on error.
     *
     * @spec openspec/specs/apphost-store-plane/spec.md
     */
    public function resolve(StoreDescriptor $descriptor, string $slug): ?array
    {
        if ($this->isConfigured(descriptor: $descriptor) === false) {
            return null;
        }

        $result = $this->fetch(descriptor: $descriptor, params: ['slug' => $slug, '_limit' => 1]);
        if ($result['outcome'] !== self::OUTCOME_OK) {
            return null;
        }

        foreach ($result['results'] as $object) {
            // Compare the slug the registry actually returned rather than
            // trusting the filter: a registry that ignores an unknown query
            // param would otherwise hand back an arbitrary first row.
            if (is_array($object) === true && (string) ($object['slug'] ?? '') === $slug) {
                return $object;
            }
        }

        return null;

    }//end resolve()

    /**
     * Perform the SSRF-guarded, redirect-refusing GET against the remote
     * store's objects API.
     *
     * @param StoreDescriptor      $descriptor The calling app's store parameters.
     * @param array<string, mixed> $params     Query params merged into the request.
     *
     * @return array{outcome: string, results: array<int, mixed>}
     */
    private function fetch(StoreDescriptor $descriptor, array $params): array
    {
        try {
            $url = $this->buildUrl(descriptor: $descriptor);
            SecurityService::assertSafeFetchUrl($url);
        } catch (Throwable $e) {
            $this->logger->warning(
                'AppHost store ('.$descriptor->appId.'): rejected unsafe/invalid registry URL: '.$e->getMessage()
            );
            return ['outcome' => self::OUTCOME_UNREACHABLE, 'results' => []];
        }

        $options = [
            'timeout'         => self::TIMEOUT,
            'connect_timeout' => self::TIMEOUT,
            'query'           => $params,
            'allow_redirects' => false,
        ];

        $token = trim($this->appConfig->getValueString($descriptor->appId, 'registry_token', ''));
        if ($token !== '') {
            $options['headers'] = ['Authorization' => 'Bearer '.$token];
        }

        try {
            $response = $this->clientService->newClient()->get($url, $options);
        } catch (Throwable $e) {
            $this->logger->warning(
                'AppHost store ('.$descriptor->appId.'): registry fetch failed: '.$e->getMessage()
            );
            return ['outcome' => self::OUTCOME_UNREACHABLE, 'results' => []];
        }

        $status = $response->getStatusCode();
        if ($status < 200 || $status >= 300) {
            $this->logger->warning(
                'AppHost store ('.$descriptor->appId.'): registry returned HTTP '.$status
            );
            return ['outcome' => self::OUTCOME_UNREACHABLE, 'results' => []];
        }

        $decoded = json_decode((string) $response->getBody(), true);
        if (json_last_error() !== JSON_ERROR_NONE || is_array($decoded) === false) {
            $this->logger->warning(
                'AppHost store ('.$descriptor->appId.'): registry returned an unparseable body'
            );
            return ['outcome' => self::OUTCOME_INVALID, 'results' => []];
        }

        $results = ($decoded['results'] ?? null);
        if (is_array($results) === false) {
            // Some OpenRegister responses are a bare list; accept that too.
            $results = [];
            if (array_is_list($decoded) === true) {
                $results = $decoded;
            }
        }

        return ['outcome' => self::OUTCOME_OK, 'results' => $results];

    }//end fetch()

    /**
     * Build the remote objects-API URL for this store's schema.
     *
     * @param StoreDescriptor $descriptor The calling app's store parameters.
     *
     * @return string
     */
    private function buildUrl(StoreDescriptor $descriptor): string
    {
        $base     = rtrim(trim($this->registryUrl(descriptor: $descriptor)), '/');
        $register = trim(
            $this->appConfig->getValueString($descriptor->appId, 'registry_register', $descriptor->defaultRegister)
        );
        if ($register === '') {
            $register = $descriptor->defaultRegister;
        }

        return $base
            .'/index.php/apps/openregister/api/objects/'
            .rawurlencode($register).'/'
            .rawurlencode($descriptor->schema);

    }//end buildUrl()

    /**
     * Flatten a remote object to a search card using the descriptor's field
     * map. Never carries the install payload, and never a credential.
     *
     * @param StoreDescriptor      $descriptor The calling app's store parameters.
     * @param array<string, mixed> $object     The remote object.
     *
     * @return array<string, mixed>
     */
    private function normaliseCard(StoreDescriptor $descriptor, array $object): array
    {
        $card = [];
        foreach ($descriptor->cardFields as $field => $property) {
            $card[$field] = (string) ($object[$property] ?? '');
        }

        // `kind` drives the store page's quick-filters (ADR-080 Decision 5) and
        // is always present so the frontend can group without a null check.
        $card['kind'] = (string) ($object['kind'] ?? '');

        return $card;

    }//end normaliseCard()

    /**
     * The configured registry base URL for this store.
     *
     * @param StoreDescriptor $descriptor The calling app's store parameters.
     *
     * @return string
     */
    private function registryUrl(StoreDescriptor $descriptor): string
    {
        return $this->appConfig->getValueString($descriptor->appId, 'registry_url', '');

    }//end registryUrl()
}//end class
