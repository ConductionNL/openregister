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
class FederatedConfigService implements FederatedConfigServiceInterface {

	/**
	 * The app its config lives under.
	 */
	private const APP_ID = 'openregister';

	/**
	 * App-config key holding the org's comma-separated source allowlist.
	 */
	private const ALLOWLIST_KEY = 'federated_config_source_allowlist';

	/**
	 * App-config key: `true` lets a bundle in that no trusted key vouches for,
	 * while the trusted-keys list is empty. Only an administrator can set it.
	 */
	private const ALLOW_UNVERIFIED_KEY = 'federated_config_allow_unverified_bundles';

	/**
	 * Trust-configuration fields → their app-config keys (for the governance API).
	 *
	 * @var array<string, string>
	 */
	private const TRUST_KEYS = [
		'sourceAllowlist' => 'federated_config_source_allowlist',
		'trustedKeys' => 'federated_config_trusted_keys',
		'publishGroups' => 'federated_config_publish_groups',
		'installGroups' => 'federated_config_install_groups',
		'allowUnverifiedBundles' => self::ALLOW_UNVERIFIED_KEY,
	];

	/**
	 * The GitHub API host for anonymous discovery.
	 */
	private const GITHUB_API = 'https://api.github.com';

	/**
	 * Constructor.
	 *
	 * @param FederatedConfigTransport    $transport The credential, signing and HTTP collaborators federated config sharing talks through.
	 * @param ShareableConfigTypeRegistry $registry  The contributed types.
	 * @param IAppConfig                  $appConfig Reads the org source allowlist.
	 * @param LoggerInterface             $logger    The logger.
	 */
	public function __construct(
		private readonly FederatedConfigTransport $transport,
		private readonly ShareableConfigTypeRegistry $registry,
		private readonly IAppConfig $appConfig,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The registered shareable types, as `{id, name, topic}` rows.
	 *
	 * @return array<int, array{id: string, name: string, topic: string}> The types.
	 *
	 * @spec openspec/changes/federated-config-sharing/specs/federated-config-sharing/spec.md
	 */
	public function types(): array {
		$out = [];
		foreach ($this->registry->all() as $type) {
			$out[] = [
				'id' => $type->getId(),
				'name' => $type->getDisplayName(),
				'topic' => $type->getTopic(),
			];
		}

		return $out;
	}//end types()

	/**
	 * Produce a portable bundle for a selection of a type's configuration.
	 *
	 * @param string $typeId The shareable type id.
	 * @param array $selection What to share, in the type's own terms.
	 *
	 * @return array The portable bundle.
	 *
	 * @throws UnexpectedValueException When no type owns the id.
	 *
	 * @spec openspec/changes/federated-config-sharing/specs/federated-config-sharing/spec.md
	 */
	public function bundle(string $typeId, array $selection): array {
		return $this->typeOrFail(typeId: $typeId)->serialise(selection: $selection);
	}//end bundle()

	/**
	 * Install a bundle, subject to the org's source allowlist.
	 *
	 * @param string $typeId The shareable type id.
	 * @param array $bundle A bundle produced by a type of this id.
	 * @param string $source Where the bundle came from (a repo/source id), for the allowlist.
	 *
	 * @return array The install result.
	 *
	 * @throws UnexpectedValueException When no type owns the id.
	 * @throws RuntimeException When the source is not allowlisted.
	 *
	 * @spec openspec/changes/federated-config-sharing/specs/federated-config-sharing/spec.md
	 */
	public function install(string $typeId, array $bundle, string $source = ''): array {
		$this->assertSourceAllowed(source: $source);
		$this->assertBundleVouchedFor(bundle: $bundle);

		return $this->typeOrFail(typeId: $typeId)->deserialise(bundle: $bundle);
	}//end install()

	/**
	 * Refuse a bundle from a source outside the org's allowlist.
	 *
	 * @param string $source Where the bundle came from (a repo/source id).
	 *
	 * @return void
	 *
	 * @throws RuntimeException When the source is not allowlisted.
	 */
	private function assertSourceAllowed(string $source): void {
		if ($this->isSourceAllowed(source: $source) === false) {
			throw new RuntimeException(sprintf('Source "%s" is not on this organisation\'s allowlist.', $source));
		}
	}//end assertSourceAllowed()

	/**
	 * Refuse a bundle whose provenance does not hold.
	 *
	 * Provenance: a tampered signature is always refused. Anyone can sign with
	 * a key of their own, so a bundle counts as verified only against a
	 * non-empty trusted-keys allowlist; without one, an administrator has to
	 * allow unverified bundles explicitly.
	 *
	 * @param array $bundle The bundle to install.
	 *
	 * @return void
	 *
	 * @throws RuntimeException When the bundle is tampered, untrusted or unverifiable.
	 */
	private function assertBundleVouchedFor(array $bundle): void {
		$this->assertSignatureAccepted(verdict: $this->transport->signer->verify(bundle: $bundle));
		$this->assertVerifiableOrAllowed();
	}//end assertBundleVouchedFor()

	/**
	 * Refuse a tampered signature, then a publisher key nothing trusts.
	 *
	 * @param array<string, mixed> $verdict The signer's verdict on the bundle.
	 *
	 * @return void
	 *
	 * @throws RuntimeException When the bundle is tampered or its publisher untrusted.
	 */
	private function assertSignatureAccepted(array $verdict): void {
		if ($verdict['signed'] === true && $verdict['valid'] === false) {
			throw new RuntimeException('Bundle signature is invalid — refusing to install a tampered bundle.');
		}

		if ($verdict['trusted'] === false) {
			throw new RuntimeException('Bundle publisher key is not trusted by this organisation.');
		}
	}//end assertSignatureAccepted()

	/**
	 * Refuse when no trusted-keys allowlist can verify the bundle and an
	 * administrator has not allowed unverified bundles.
	 *
	 * @return void
	 *
	 * @throws RuntimeException When the bundle cannot be verified and that is not allowed.
	 */
	private function assertVerifiableOrAllowed(): void {
		if ($this->transport->signer->enforced() === false
			&& $this->appConfig->getValueString(self::APP_ID, self::ALLOW_UNVERIFIED_KEY, '') !== 'true'
		) {
			throw new RuntimeException(
				'This organisation has no trusted publisher keys, so this bundle cannot be verified. '
				.'An administrator can add a trusted key or allow unverified bundles.'
			);
		}
	}//end assertVerifiableOrAllowed()

	/**
	 * Publish a bundle to a GitHub repository, with the token supplied by the
	 * broker (Doriath). One file per bundle path via the contents API.
	 *
	 * @param string $typeId The shareable type id.
	 * @param array $selection What to share.
	 * @param ConfigPublishTarget $target Where to publish: repo, path, branch and visibility of a created repo.
	 * @param string $credentialId The broker credential id custodying the GitHub token.
	 *
	 * @return array `{published: true, path, status}`.
	 *
	 * @throws RuntimeException When the push fails.
	 *
	 * @spec openspec/changes/federated-config-sharing/specs/federated-config-sharing/spec.md
	 */
	public function publish(string $typeId, array $selection, ConfigPublishTarget $target, string $credentialId): array {
		$destination = new BrokeredPublishTarget(target: $target, credentialId: $credentialId);

		return $this->publishBundle(request: new ConfigPublishRequest(typeId: $typeId, selection: $selection, destination: $destination));
	}//end publish()

	/**
	 * Publish one bundle as its request describes.
	 *
	 * @param ConfigPublishRequest $request The type, the selection and the brokered target.
	 *
	 * @return array `{published: true, repo, path, topic, status}`.
	 *
	 * @throws RuntimeException When the push fails.
	 */
	private function publishBundle(ConfigPublishRequest $request): array {
		$type = $this->typeOrFail(typeId: $request->typeId);

		// Make the target repo exist and carry the type's discovery topic, so a
		// freshly published config is findable via discover() (both single-config
		// and config-set repos rely on this). Best-effort: a failure here still
		// lets the contents write surface a clear error.
		$this->ensureRepo(destination: $request->destination);
		$this->setTopics(destination: $request->destination, topics: [$type->getTopic()]);

		$bundle = $this->transport->signer->sign(bundle: $type->serialise(selection: $request->selection));
		$status = $this->writeBundle(request: $request, bundle: $bundle);
		$target = $request->destination->target;

		return ['published' => true, 'repo' => $target->repo, 'path' => $target->path, 'topic' => $type->getTopic(), 'status' => $status];
	}//end publishBundle()

	/**
	 * Write the signed bundle into the target repo through the broker.
	 *
	 * The broker makes the call with the token it custodies (Doriath/NC
	 * vault); this service never sees the token. A path, never a full URL.
	 *
	 * @param ConfigPublishRequest $request The type and the brokered target.
	 * @param array                $bundle  The signed bundle.
	 *
	 * @return int The HTTP status of the write.
	 *
	 * @throws RuntimeException When the push fails.
	 */
	private function writeBundle(ConfigPublishRequest $request, array $bundle): int {
		$target = $request->destination->target;
		$result = $this->transport->broker->request(
			credentialId: $request->destination->credentialId,
			appId: self::APP_ID,
			method: 'PUT',
			path: sprintf('/repos/%s/contents/%s', $target->repo, ltrim($target->path, '/')),
			headers: ['Accept' => 'application/vnd.github+json'],
			body: $this->contentsBody(request: $request, bundle: $bundle)
		);

		$status = (int)($result['status'] ?? 0);
		if ($status < 200 || $status >= 300) {
			throw new RuntimeException(sprintf('GitHub publish failed (%d).', $status));
		}

		return $status;
	}//end writeBundle()

	/**
	 * The contents-API request body that commits the bundle file.
	 *
	 * @param ConfigPublishRequest $request The type and the brokered target.
	 * @param array                $bundle  The signed bundle.
	 *
	 * @return string The JSON body.
	 */
	private function contentsBody(ConfigPublishRequest $request, array $bundle): string {
		return (string)json_encode(
			[
				'message' => sprintf('Publish %s configuration', $request->typeId),
				'content' => base64_encode((string)json_encode($bundle, JSON_PRETTY_PRINT)),
				'branch' => $request->destination->target->branch,
			]
		);
	}//end contentsBody()

	/**
	 * Ensure a GitHub repository exists, creating it when absent.
	 *
	 * A config store is often published into a fresh, per-set repo. Creation is
	 * best-effort: when the owner is an organisation the org-repo endpoint is
	 * used, otherwise the authenticated user's; a failure is logged, not fatal
	 * (the subsequent contents write reports a missing repo clearly).
	 *
	 * @param BrokeredPublishTarget $destination The target (its `owner/repo`, and whether a freshly created repo
	 *                                           should be private) and the broker credential.
	 *
	 * @return void
	 */
	private function ensureRepo(BrokeredPublishTarget $destination): void {
		$repo = $destination->target->repo;
		[$owner, $name] = $destination->ownerAndName();
		if ($owner === '' || $name === '') {
			return;
		}

		if ($this->repoExists(destination: $destination) === true
			|| $this->createRepo(destination: $destination) === true
		) {
			return;
		}

		$this->logger->warning(
			message: '[FederatedConfigService] could not ensure repo ' . $repo . ' exists; relying on the write to report it.',
			context: ['file' => __FILE__, 'line' => __LINE__]
		);

	}//end ensureRepo()

	/**
	 * Whether the repo answers 200; a failed lookup counts as absent.
	 *
	 * @param BrokeredPublishTarget $destination The target (`owner/repo`) and the broker credential.
	 *
	 * @return bool
	 */
	private function repoExists(BrokeredPublishTarget $destination): bool {
		[$owner, $name] = $destination->ownerAndName();
		try {
			$existing = $this->transport->broker->request(
				credentialId: $destination->credentialId,
				appId: self::APP_ID,
				method: 'GET',
				path: sprintf('/repos/%s/%s', $owner, $name),
				headers: ['Accept' => 'application/vnd.github+json']
			);
			return (int)($existing['status'] ?? 0) === 200;
		} catch (Throwable $e) {
			// Fall through to creation.
			return false;
		}
	}//end repoExists()

	/**
	 * Create the repo: as an org repo first, then in the authenticated user's namespace.
	 *
	 * @param BrokeredPublishTarget $destination The target (`owner/repo`, privacy) and the broker credential.
	 *
	 * @return bool Whether one of the create calls answered 2xx.
	 */
	private function createRepo(BrokeredPublishTarget $destination): bool {
		$owner = $destination->ownerAndName()[0];
		foreach ([sprintf('/orgs/%s/repos', $owner), '/user/repos'] as $createPath) {
			if ($this->tryCreateRepo(createPath: $createPath, destination: $destination) === true) {
				return true;
			}
		}

		return false;
	}//end createRepo()

	/**
	 * Try one repo-create endpoint; true on a 2xx, false on any other answer or failure.
	 *
	 * @param string                $createPath  The create endpoint path.
	 * @param BrokeredPublishTarget $destination The target (name, privacy) and the broker credential.
	 *
	 * @return bool
	 */
	private function tryCreateRepo(string $createPath, BrokeredPublishTarget $destination): bool {
		try {
			$created = $this->transport->broker->request(
				credentialId: $destination->credentialId,
				appId: self::APP_ID,
				method: 'POST',
				path: $createPath,
				headers: ['Accept' => 'application/vnd.github+json'],
				body: $destination->createBody()
			);
			$status = (int)($created['status'] ?? 0);
			return $status >= 200 && $status < 300;
		} catch (Throwable $e) {
			return false;
		}
	}//end tryCreateRepo()

	/**
	 * Set a repository's discovery topics (best-effort).
	 *
	 * @param BrokeredPublishTarget $destination The target (`owner/repo`) and the broker credential.
	 * @param array<int, string>    $topics      The topics to set.
	 *
	 * @return void
	 */
	private function setTopics(BrokeredPublishTarget $destination, array $topics): void {
		$repo = $destination->target->repo;
		$credentialId = $destination->credentialId;
		$names = array_values(array_filter(array_map('trim', $topics)));
		if ($names === []) {
			return;
		}

		try {
			$this->transport->broker->request(
				credentialId: $credentialId,
				appId: self::APP_ID,
				method: 'PUT',
				path: sprintf('/repos/%s/topics', $repo),
				headers: ['Accept' => 'application/vnd.github+json'],
				body: (string)json_encode(['names' => $names])
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				message: '[FederatedConfigService] could not set topics on ' . $repo . ': ' . $e->getMessage(),
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
	 * @param string $topic The discovery topic to search for.
	 * @param string|null $credentialId Optional broker credential for an authenticated search.
	 *
	 * @return array<int, array<string, mixed>> The discovered repositories as
	 *                                          `{repo, name, description, url, stars, updated, topics}` cards.
	 *
	 * @spec openspec/changes/federated-config-sharing/specs/federated-config-sharing/spec.md
	 */
	public function discover(string $topic, ?string $credentialId = null): array {
		$topic = trim($topic);
		if ($topic === '') {
			return [];
		}

		$path = '/search/repositories?q=' . rawurlencode('topic:' . $topic) . '&sort=updated&per_page=50';

		try {
			$body = $this->githubGet(path: $path, credentialId: $credentialId);
		} catch (Throwable $e) {
			$this->logger->warning(
				message: '[FederatedConfigService] discovery failed for topic ' . $topic . ': ' . $e->getMessage(),
				context: ['file' => __FILE__, 'line' => __LINE__]
			);
			return [];
		}

		return $this->repoCards(body: $body);
	}//end discover()

	/**
	 * The discovery cards of a repository-search response body; non-array items are skipped.
	 *
	 * @param string $body The response body.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function repoCards(string $body): array {
		$cards = [];
		foreach ($this->searchItems(body: $body) as $item) {
			if (is_array($item) === false) {
				continue;
			}

			$cards[] = $this->repoCard(item: $item);
		}

		return $cards;
	}//end repoCards()

	/**
	 * GET a GitHub API path: through the broker with a credential, anonymously otherwise.
	 *
	 * @param string      $path         The API path (with query string).
	 * @param string|null $credentialId The broker credential, or null/'' for anonymous.
	 *
	 * @return string The response body.
	 */
	private function githubGet(string $path, ?string $credentialId): string {
		if ($credentialId !== null && $credentialId !== '') {
			$result = $this->transport->broker->request(
				credentialId: $credentialId,
				appId: self::APP_ID,
				method: 'GET',
				path: $path,
				headers: ['Accept' => 'application/vnd.github+json']
			);
			return (string)($result['body'] ?? '');
		}

		$response = $this->transport->clientService->newClient()->get(
			self::GITHUB_API . $path,
			['headers' => ['Accept' => 'application/vnd.github+json'], 'http_errors' => false]
		);
		return (string)$response->getBody();
	}//end githubGet()

	/**
	 * The `items` list of a repository-search response body, or [].
	 *
	 * @param string $body The response body.
	 *
	 * @return array<mixed>
	 */
	private function searchItems(string $body): array {
		$decoded = json_decode($body, true);
		if (is_array($decoded) === true && isset($decoded['items']) === true && is_array($decoded['items']) === true) {
			return $decoded['items'];
		}

		return [];
	}//end searchItems()

	/**
	 * One discovery card from a repository-search item.
	 *
	 * @param array<string, mixed> $item The search item.
	 *
	 * @return array<string, mixed>
	 */
	private function repoCard(array $item): array {
		return [
			'repo' => (string)($item['full_name'] ?? ''),
			'name' => (string)($item['name'] ?? ''),
			'description' => (string)($item['description'] ?? ''),
			'url' => (string)($item['html_url'] ?? ''),
			'stars' => (int)($item['stargazers_count'] ?? 0),
			'updated' => (string)($item['updated_at'] ?? ''),
			'topics' => array_values(array_filter((array)($item['topics'] ?? []), 'is_string')),
		];
	}//end repoCard()

	/**
	 * Fetch a published bundle file from a GitHub repository.
	 *
	 * The bridge between discover() (which returns repo cards) and install()
	 * (which takes a bundle): given a repo and the path of its bundle file, this
	 * reads and decodes it. Public repos are read anonymously; a broker credential
	 * is used when supplied (rate limit, private repos).
	 *
	 * @param string $repo The `owner/repo`.
	 * @param string $path The bundle file path within the repo.
	 * @param string|null $credentialId Optional broker credential for an authenticated read.
	 *
	 * @return array The decoded bundle.
	 *
	 * @throws RuntimeException When the file cannot be fetched or decoded.
	 *
	 * @spec openspec/changes/federated-config-sharing/specs/federated-config-sharing/spec.md
	 */
	public function fetchBundle(string $repo, string $path, ?string $credentialId = null): array {
		return $this->fetchPublishedBundle(
			request: new BundleFetchRequest(repo: trim($repo), path: ltrim(trim($path), '/'), credentialId: $credentialId)
		);
	}//end fetchBundle()

	/**
	 * Read and decode the bundle file a fetch request names.
	 *
	 * @param BundleFetchRequest $request The repo, the file path and the optional credential.
	 *
	 * @return array The decoded bundle.
	 *
	 * @throws RuntimeException When the file cannot be fetched or decoded.
	 */
	private function fetchPublishedBundle(BundleFetchRequest $request): array {
		if ($request->repo === '' || $request->path === '') {
			throw new RuntimeException('Fetching a bundle needs a repo and a path.');
		}

		try {
			$body = $this->githubGet(path: $request->contentsPath(), credentialId: $request->credentialId);
		} catch (Throwable $e) {
			throw new RuntimeException(sprintf('Could not fetch %s from %s: %s', $request->path, $request->repo, $e->getMessage()));
		}

		return $this->decodeBundle(body: $body, where: $request->where());
	}//end fetchPublishedBundle()

	/**
	 * Decode a contents-API response body into the bundle it carries.
	 *
	 * @param string $body  The response body.
	 * @param string $where The file path and repo, as `<path> in <repo>` (for messages).
	 *
	 * @return array<string, mixed>
	 *
	 * @throws RuntimeException When there is no content, or it is not base64 JSON.
	 */
	private function decodeBundle(string $body, string $where): array {
		$meta = json_decode($body, true);
		if (is_array($meta) === false || isset($meta['content']) === false) {
			throw new RuntimeException(sprintf('No bundle at %s.', $where));
		}

		$decoded = base64_decode((string)$meta['content'], true);
		if ($decoded === false) {
			throw new RuntimeException(sprintf('Bundle at %s is not valid base64.', $where));
		}

		$bundle = json_decode($decoded, true);
		if (is_array($bundle) === false) {
			throw new RuntimeException(sprintf('Bundle at %s is not valid JSON.', $where));
		}

		return $bundle;
	}//end decodeBundle()

	/**
	 * This instance's signing public key (base64) for others to trust.
	 *
	 * @return string The base64 Ed25519 public key.
	 *
	 * @spec openspec/changes/federated-config-sharing/specs/federated-config-sharing/spec.md
	 */
	public function publicKey(): string {
		return $this->transport->signer->publicKey();
	}//end publicKey()

	/**
	 * The organisation's trust configuration (for a governance UI).
	 *
	 * @return array{sourceAllowlist: string, trustedKeys: string, publishGroups: string, installGroups: string}
	 *
	 * @spec openspec/changes/federated-config-sharing/specs/federated-config-sharing/spec.md
	 */
	public function getTrustConfig(): array {
		$out = [];
		foreach (self::TRUST_KEYS as $field => $key) {
			$out[$field] = $this->appConfig->getValueString(self::APP_ID, $key, '');
		}

		return $out;
	}//end getTrustConfig()

	/**
	 * Set one trust-configuration value (the allowlist, trusted keys, or a group list).
	 *
	 * @param string $field One of `sourceAllowlist|trustedKeys|publishGroups|installGroups|allowUnverifiedBundles`.
	 * @param string $value The new value (comma-separated).
	 *
	 * @return void
	 *
	 * @throws UnexpectedValueException When the field is unknown.
	 *
	 * @spec openspec/changes/federated-config-sharing/specs/federated-config-sharing/spec.md
	 */
	public function setTrustValue(string $field, string $value): void {
		if (isset(self::TRUST_KEYS[$field]) === false) {
			throw new UnexpectedValueException(sprintf('Unknown trust setting "%s".', $field));
		}

		$value = trim($value);
		if ($field === 'allowUnverifiedBundles') {
			$value = $this->allowanceValue(value: $value);
		}

		$this->appConfig->setValueString(self::APP_ID, self::TRUST_KEYS[$field], $value);

	}//end setTrustValue()

	/**
	 * The stored form of the unverified-bundle allowance: 'true' or ''.
	 *
	 * A JSON `true` arrives as "1" after the controller's string cast, so
	 * "1" and "true" (any case) both turn it on and anything else turns it off.
	 *
	 * @param string $value The value as sent.
	 *
	 * @return string 'true' when the allowance is on, '' otherwise.
	 */
	private function allowanceValue(string $value): string {
		if (in_array(strtolower($value), ['1', 'true'], true) === true) {
			return 'true';
		}

		return '';
	}//end allowanceValue()

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
	public function trustPublisherKey(string $publicKey): void {
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
	public function isSourceAllowed(string $source): bool {
		$raw = trim($this->appConfig->getValueString(self::APP_ID, self::ALLOWLIST_KEY, ''));
		if ($raw === '') {
			return true;
		}

		$allow = array_filter(array_map('trim', explode(',', $raw)));
		foreach ($allow as $entry) {
			// Exact match, or a prefix match so an org entry covers its repos
			// (e.g. `ConductionNL` allows `ConductionNL/anything`).
			if ($source === $entry || str_starts_with($source, $entry . '/') === true) {
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
	private function typeOrFail(string $typeId): IShareableConfigType {
		$type = $this->registry->get(id: $typeId);
		if ($type === null) {
			throw new UnexpectedValueException(sprintf('No shareable configuration type "%s".', $typeId));
		}

		return $type;
	}//end typeOrFail()
}//end class
