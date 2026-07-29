<?php

/**
 * The engine for federated configuration sharing.
 *
 * OpenRegister owns one way for any app to share its configuration over GitHub.
 * A type ({@see IShareableConfigType}) knows how to (de)serialise its own
 * configuration; this service owns everything common: producing a portable
 * bundle, publishing it to GitHub, and installing one — governed by the org's
 * trust rules.
 *
 * Two deliberate choices from the design:
 *  - **Credentials via the broker (Doriath).** Publishing never handles a GitHub
 *    token: it asks the credential broker to make the outbound call, and the
 *    broker injects the token it custodies (in Doriath's vault where installed,
 *    the Nextcloud vault otherwise). The token never enters this service.
 *  - **Trust is an org allowlist.** Installing from a source outside the org's
 *    allowlist is refused. An empty allowlist means "not yet enforced" (an
 *    instance opts in by populating it), so this is safe to ship before every
 *    org has curated its sources.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Config
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/federated-config-sharing/specs/federated-config-sharing/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Config;

use OCA\OpenRegister\Service\Credential\CredentialBrokerService;
use OCP\Http\Client\IClientService;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;
use UnexpectedValueException;

/**
 * Bundles, publishes and installs shareable configuration.
 */
class FederatedConfigService
{

    /**
     * The app its config lives under.
     */
    private const APP_ID = 'openregister';

    /**
     * App-config key holding the org's comma-separated source allowlist.
     */
    private const ALLOWLIST_KEY = 'federated_config_source_allowlist';

    /**
     * Trust-configuration fields → their app-config keys (for the governance API).
     *
     * @var array<string, string>
     */
    private const TRUST_KEYS = [
        'sourceAllowlist' => 'federated_config_source_allowlist',
        'trustedKeys'     => 'federated_config_trusted_keys',
        'publishGroups'   => 'federated_config_publish_groups',
        'installGroups'   => 'federated_config_install_groups',
    ];

    /**
     * The GitHub API host for anonymous discovery.
     */
    private const GITHUB_API = 'https://api.github.com';

    /**
     * Constructor.
     *
     * @param ShareableConfigTypeRegistry $registry      The contributed types.
     * @param CredentialBrokerService     $broker        Custodies the GitHub token (Doriath's vault where installed, the NC vault otherwise).
     * @param BundleSigner                $signer        Signs published bundles and verifies installed ones.
     * @param IClientService              $clientService NC HTTP client factory (anonymous discovery).
     * @param IAppConfig                  $appConfig     Reads the org source allowlist.
     * @param LoggerInterface             $logger        The logger.
     */
    public function __construct(
        private readonly ShareableConfigTypeRegistry $registry,
        private readonly CredentialBrokerService $broker,
        private readonly BundleSigner $signer,
        private readonly IClientService $clientService,
        private readonly IAppConfig $appConfig,
        private readonly LoggerInterface $logger
    ) {

    }//end __construct()

    /**
     * The registered shareable types, as `{id, name, topic}` rows.
     *
     * @return array<int, array{id: string, name: string, topic: string}> The types.
     *
     * @spec openspec/changes/federated-config-sharing/specs/federated-config-sharing/spec.md
     */
    public function types(): array
    {
        $out = [];
        foreach ($this->registry->all() as $type) {
            $out[] = [
                'id'    => $type->getId(),
                'name'  => $type->getDisplayName(),
                'topic' => $type->getTopic(),
            ];
        }

        return $out;

    }//end types()

    /**
     * Produce a portable bundle for a selection of a type's configuration.
     *
     * @param string $typeId    The shareable type id.
     * @param array  $selection What to share, in the type's own terms.
     *
     * @return array The portable bundle.
     *
     * @throws UnexpectedValueException When no type owns the id.
     *
     * @spec openspec/changes/federated-config-sharing/specs/federated-config-sharing/spec.md
     */
    public function bundle(string $typeId, array $selection): array
    {
        return $this->typeOrFail(typeId: $typeId)->serialise(selection: $selection);

    }//end bundle()

    /**
     * Install a bundle, subject to the org's source allowlist.
     *
     * @param string $typeId The shareable type id.
     * @param array  $bundle A bundle produced by a type of this id.
     * @param string $source Where the bundle came from (a repo/source id), for the allowlist.
     *
     * @return array The install result.
     *
     * @throws UnexpectedValueException When no type owns the id.
     * @throws RuntimeException         When the source is not allowlisted.
     *
     * @spec openspec/changes/federated-config-sharing/specs/federated-config-sharing/spec.md
     */
    public function install(string $typeId, array $bundle, string $source=''): array
    {
        if ($this->isSourceAllowed(source: $source) === false) {
            throw new RuntimeException(sprintf('Source "%s" is not on this organisation\'s allowlist.', $source));
        }

        // Provenance: a tampered signature is always refused; an unsigned or
        // untrusted-key bundle is refused only once the org enforces signing (a
        // non-empty trusted-keys allowlist).
        $verdict = $this->signer->verify(bundle: $bundle);
        if ($verdict['signed'] === true && $verdict['valid'] === false) {
            throw new RuntimeException('Bundle signature is invalid — refusing to install a tampered bundle.');
        }

        if ($verdict['trusted'] === false) {
            throw new RuntimeException('Bundle publisher key is not trusted by this organisation.');
        }

        return $this->typeOrFail(typeId: $typeId)->deserialise(bundle: $bundle);

    }//end install()

    /**
     * Publish a bundle to a GitHub repository, with the token supplied by the
     * broker (Doriath). One file per bundle path via the contents API.
     *
     * @param string $typeId       The shareable type id.
     * @param array  $selection    What to share.
     * @param string $repo         The `owner/repo` to publish into.
     * @param string $path         The path within the repo for the bundle file.
     * @param string $credentialId The broker credential id custodying the GitHub token.
     * @param string $branch       The branch to publish onto.
     * @param bool   $private      Whether a repo created on demand is private.
     *
     * @return array `{published: true, path, status}`.
     *
     * @throws RuntimeException When the push fails.
     *
     * @spec openspec/changes/federated-config-sharing/specs/federated-config-sharing/spec.md
     */
    public function publish(
        string $typeId,
        array $selection,
        string $repo,
        string $path,
        string $credentialId,
        string $branch='main',
        bool $private=false
    ): array {
        $type = $this->typeOrFail(typeId: $typeId);

        // Make the target repo exist and carry the type's discovery topic, so a
        // freshly published config is findable via discover() (both single-config
        // and config-set repos rely on this). Best-effort: a failure here still
        // lets the contents write surface a clear error.
        $this->ensureRepo(repo: $repo, credentialId: $credentialId, private: $private);
        $this->setTopics(repo: $repo, topics: [$type->getTopic()], credentialId: $credentialId);

        $bundle  = $this->signer->sign(bundle: $type->serialise(selection: $selection));
        $content = base64_encode((string) json_encode($bundle, JSON_PRETTY_PRINT));

        // The broker makes the call with the token it custodies (Doriath/NC
        // vault); this service never sees the token. A path, never a full URL.
        $result = $this->broker->request(
            credentialId: $credentialId,
            appId: self::APP_ID,
            method: 'PUT',
            path: sprintf('/repos/%s/contents/%s', $repo, ltrim($path, '/')),
            headers: ['Accept' => 'application/vnd.github+json'],
            body: (string) json_encode(
                [
                    'message' => sprintf('Publish %s configuration', $typeId),
                    'content' => $content,
                    'branch'  => $branch,
                ]
            )
        );

        $status = (int) ($result['status'] ?? 0);
        if ($status < 200 || $status >= 300) {
            throw new RuntimeException(sprintf('GitHub publish failed (%d).', $status));
        }

        return [
            'published' => true,
            'repo'      => $repo,
            'path'      => $path,
            'topic'     => $type->getTopic(),
            'status'    => $status,
        ];

    }//end publish()

    /**
     * Ensure a GitHub repository exists, creating it when absent.
     *
     * A config store is often published into a fresh, per-set repo. Creation is
     * best-effort: when the owner is an organisation the org-repo endpoint is
     * used, otherwise the authenticated user's; a failure is logged, not fatal
     * (the subsequent contents write reports a missing repo clearly).
     *
     * @param string  $repo         The `owner/repo`.
     * @param string  $credentialId The broker credential.
     * @param boolean $private      Whether a freshly created repo should be private.
     *
     * @return void
     */
    private function ensureRepo(string $repo, string $credentialId, bool $private=false): void
    {
        [$owner, $name] = array_pad(explode('/', $repo, 2), 2, '');
        if ($owner === '' || $name === '') {
            return;
        }

        try {
            $existing = $this->broker->request(
                credentialId: $credentialId,
                appId: self::APP_ID,
                method: 'GET',
                path: sprintf('/repos/%s/%s', $owner, $name),
                headers: ['Accept' => 'application/vnd.github+json']
            );
            if ((int) ($existing['status'] ?? 0) === 200) {
                return;
            }
        } catch (Throwable $e) {
            // Fall through to creation.
        }

        // Try an org repo first, then the authenticated user's namespace.
        foreach ([sprintf('/orgs/%s/repos', $owner), '/user/repos'] as $createPath) {
            try {
                $created = $this->broker->request(
                    credentialId: $credentialId,
                    appId: self::APP_ID,
                    method: 'POST',
                    path: $createPath,
                    headers: ['Accept' => 'application/vnd.github+json'],
                    body: (string) json_encode(['name' => $name, 'private' => $private, 'auto_init' => true])
                );
                if ((int) ($created['status'] ?? 0) >= 200 && (int) ($created['status'] ?? 0) < 300) {
                    return;
                }
            } catch (Throwable $e) {
                continue;
            }
        }

        $this->logger->warning(
            message: '[FederatedConfigService] could not ensure repo '.$repo.' exists; relying on the write to report it.',
            context: ['file' => __FILE__, 'line' => __LINE__]
        );

    }//end ensureRepo()

    /**
     * Set a repository's discovery topics (best-effort).
     *
     * @param string             $repo         The `owner/repo`.
     * @param array<int, string> $topics       The topics to set.
     * @param string             $credentialId The broker credential.
     *
     * @return void
     */
    private function setTopics(string $repo, array $topics, string $credentialId): void
    {
        $names = array_values(array_filter(array_map('trim', $topics)));
        if ($names === []) {
            return;
        }

        try {
            $this->broker->request(
                credentialId: $credentialId,
                appId: self::APP_ID,
                method: 'PUT',
                path: sprintf('/repos/%s/topics', $repo),
                headers: ['Accept' => 'application/vnd.github+json'],
                body: (string) json_encode(['names' => $names])
            );
        } catch (Throwable $e) {
            $this->logger->warning(
                message: '[FederatedConfigService] could not set topics on '.$repo.': '.$e->getMessage(),
                context: ['file' => __FILE__, 'line' => __LINE__]
            );
        }

    }//end setTopics()

    /**
     * Discover published bundles across GitHub by their type's discovery topic.
     *
     * Discovery is public data (GitHub topic search), so it runs anonymously by
     * default; when a broker credential is supplied it goes through the broker
     * (higher rate limit, private-visible repos). Returns lightweight cards for a
     * browse UI — never installs.
     *
     * @param string      $topic        The discovery topic to search for.
     * @param string|null $credentialId Optional broker credential for an authenticated search.
     *
     * @return array<int, array<string, mixed>> The discovered repositories as
     *         `{repo, name, description, url, stars, updated, topics}` cards.
     *
     * @spec openspec/changes/federated-config-sharing/specs/federated-config-sharing/spec.md
     */
    public function discover(string $topic, ?string $credentialId=null): array
    {
        $topic = trim($topic);
        if ($topic === '') {
            return [];
        }

        $path = '/search/repositories?q='.rawurlencode('topic:'.$topic).'&sort=updated&per_page=50';

        try {
            if ($credentialId !== null && $credentialId !== '') {
                $result = $this->broker->request(
                    credentialId: $credentialId,
                    appId: self::APP_ID,
                    method: 'GET',
                    path: $path,
                    headers: ['Accept' => 'application/vnd.github+json']
                );
                $body   = (string) ($result['body'] ?? '');
            } else {
                $response = $this->clientService->newClient()->get(
                    self::GITHUB_API.$path,
                    ['headers' => ['Accept' => 'application/vnd.github+json'], 'http_errors' => false]
                );
                $body     = (string) $response->getBody();
            }
        } catch (Throwable $e) {
            $this->logger->warning(
                message: '[FederatedConfigService] discovery failed for topic '.$topic.': '.$e->getMessage(),
                context: ['file' => __FILE__, 'line' => __LINE__]
            );
            return [];
        }//end try

        $decoded = json_decode($body, true);
        $items   = [];
        if (is_array($decoded) === true && isset($decoded['items']) === true && is_array($decoded['items']) === true) {
            $items = $decoded['items'];
        }

        $cards = [];
        foreach ($items as $item) {
            if (is_array($item) === false) {
                continue;
            }

            $cards[] = [
                'repo'        => (string) ($item['full_name'] ?? ''),
                'name'        => (string) ($item['name'] ?? ''),
                'description' => (string) ($item['description'] ?? ''),
                'url'         => (string) ($item['html_url'] ?? ''),
                'stars'       => (int) ($item['stargazers_count'] ?? 0),
                'updated'     => (string) ($item['updated_at'] ?? ''),
                'topics'      => array_values(array_filter((array) ($item['topics'] ?? []), 'is_string')),
            ];
        }//end foreach

        return $cards;

    }//end discover()

    /**
     * Fetch a published bundle file from a GitHub repository.
     *
     * The bridge between discover() (which returns repo cards) and install()
     * (which takes a bundle): given a repo and the path of its bundle file, this
     * reads and decodes it. Public repos are read anonymously; a broker credential
     * is used when supplied (rate limit, private repos).
     *
     * @param string      $repo         The `owner/repo`.
     * @param string      $path         The bundle file path within the repo.
     * @param string|null $credentialId Optional broker credential for an authenticated read.
     *
     * @return array The decoded bundle.
     *
     * @throws RuntimeException When the file cannot be fetched or decoded.
     *
     * @spec openspec/changes/federated-config-sharing/specs/federated-config-sharing/spec.md
     */
    public function fetchBundle(string $repo, string $path, ?string $credentialId=null): array
    {
        $repo = trim($repo);
        $path = ltrim(trim($path), '/');
        if ($repo === '' || $path === '') {
            throw new RuntimeException('Fetching a bundle needs a repo and a path.');
        }

        $apiPath = sprintf('/repos/%s/contents/%s', $repo, $path);

        try {
            if ($credentialId !== null && $credentialId !== '') {
                $result = $this->broker->request(
                    credentialId: $credentialId,
                    appId: self::APP_ID,
                    method: 'GET',
                    path: $apiPath,
                    headers: ['Accept' => 'application/vnd.github+json']
                );
                $body   = (string) ($result['body'] ?? '');
            } else {
                $response = $this->clientService->newClient()->get(
                    self::GITHUB_API.$apiPath,
                    ['headers' => ['Accept' => 'application/vnd.github+json'], 'http_errors' => false]
                );
                $body     = (string) $response->getBody();
            }
        } catch (Throwable $e) {
            throw new RuntimeException(sprintf('Could not fetch %s from %s: %s', $path, $repo, $e->getMessage()));
        }//end try

        $meta = json_decode($body, true);
        if (is_array($meta) === false || isset($meta['content']) === false) {
            throw new RuntimeException(sprintf('No bundle at %s in %s.', $path, $repo));
        }

        $decoded = base64_decode((string) $meta['content'], true);
        if ($decoded === false) {
            throw new RuntimeException(sprintf('Bundle at %s in %s is not valid base64.', $path, $repo));
        }

        $bundle = json_decode($decoded, true);
        if (is_array($bundle) === false) {
            throw new RuntimeException(sprintf('Bundle at %s in %s is not valid JSON.', $path, $repo));
        }

        return $bundle;

    }//end fetchBundle()

    /**
     * This instance's signing public key (base64) for others to trust.
     *
     * @return string The base64 Ed25519 public key.
     *
     * @spec openspec/changes/federated-config-sharing/specs/federated-config-sharing/spec.md
     */
    public function publicKey(): string
    {
        return $this->signer->publicKey();

    }//end publicKey()

    /**
     * The organisation's trust configuration (for a governance UI).
     *
     * @return array{sourceAllowlist: string, trustedKeys: string, publishGroups: string, installGroups: string}
     *
     * @spec openspec/changes/federated-config-sharing/specs/federated-config-sharing/spec.md
     */
    public function getTrustConfig(): array
    {
        $out = [];
        foreach (self::TRUST_KEYS as $field => $key) {
            $out[$field] = $this->appConfig->getValueString(self::APP_ID, $key, '');
        }

        return $out;

    }//end getTrustConfig()

    /**
     * Set one trust-configuration value (the allowlist, trusted keys, or a group list).
     *
     * @param string $field One of `sourceAllowlist|trustedKeys|publishGroups|installGroups`.
     * @param string $value The new value (comma-separated).
     *
     * @return void
     *
     * @throws UnexpectedValueException When the field is unknown.
     *
     * @spec openspec/changes/federated-config-sharing/specs/federated-config-sharing/spec.md
     */
    public function setTrustValue(string $field, string $value): void
    {
        if (isset(self::TRUST_KEYS[$field]) === false) {
            throw new UnexpectedValueException(sprintf('Unknown trust setting "%s".', $field));
        }

        $this->appConfig->setValueString(self::APP_ID, self::TRUST_KEYS[$field], trim($value));

    }//end setTrustValue()

    /**
     * Add a base64 public key to the trusted-keys allowlist (a convenience over
     * hand-editing the comma-separated list).
     *
     * @param string $publicKey The base64 Ed25519 public key to trust.
     *
     * @return void
     *
     * @spec openspec/changes/federated-config-sharing/specs/federated-config-sharing/spec.md
     */
    public function trustPublisherKey(string $publicKey): void
    {
        $publicKey = trim($publicKey);
        if ($publicKey === '') {
            return;
        }

        $current = array_filter(array_map('trim', explode(',', $this->appConfig->getValueString(self::APP_ID, self::TRUST_KEYS['trustedKeys'], ''))));
        if (in_array($publicKey, $current, true) === false) {
            $current[] = $publicKey;
        }

        $this->appConfig->setValueString(self::APP_ID, self::TRUST_KEYS['trustedKeys'], implode(',', $current));

    }//end trustPublisherKey()

    /**
     * Whether a source may be installed from.
     *
     * An empty allowlist is "not yet enforced" — an org opts into enforcement by
     * populating it — so this is safe to ship before every org curates sources.
     *
     * @param string $source The source id (a repo, an org).
     *
     * @return boolean Whether it is allowed.
     *
     * @spec openspec/changes/federated-config-sharing/specs/federated-config-sharing/spec.md
     */
    public function isSourceAllowed(string $source): bool
    {
        $raw = trim($this->appConfig->getValueString(self::APP_ID, self::ALLOWLIST_KEY, ''));
        if ($raw === '') {
            return true;
        }

        $allow = array_filter(array_map('trim', explode(',', $raw)));
        foreach ($allow as $entry) {
            // Exact match, or a prefix match so an org entry covers its repos
            // (e.g. `ConductionNL` allows `ConductionNL/anything`).
            if ($source === $entry || str_starts_with($source, $entry.'/') === true) {
                return true;
            }
        }

        return false;

    }//end isSourceAllowed()

    /**
     * Resolve a type or fail loudly.
     *
     * @param string $typeId The type id.
     *
     * @return IShareableConfigType The type.
     *
     * @throws UnexpectedValueException When no type owns the id.
     */
    private function typeOrFail(string $typeId): IShareableConfigType
    {
        $type = $this->registry->get(id: $typeId);
        if ($type === null) {
            throw new UnexpectedValueException(sprintf('No shareable configuration type "%s".', $typeId));
        }

        return $type;

    }//end typeOrFail()
}//end class
