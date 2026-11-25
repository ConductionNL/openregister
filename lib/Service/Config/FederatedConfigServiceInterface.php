<?php

/**
 * FederatedConfigServiceInterface: what callers outside the component use of FederatedConfigService.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Config
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Config;

use RuntimeException;
use UnexpectedValueException;

/**
 * Share configuration between instances: bundle, publish, discover, install and trust.
 *
 * Declares only what callers outside the component use; the rest of the
 * class stays its own.
 */
interface FederatedConfigServiceInterface {

	/**
	 * The registered shareable types, as `{id, name, topic}` rows.
	 *
	 * @return array<int, array{id: string, name: string, topic: string}> The types.
	 *
	 * @spec openspec/changes/federated-config-sharing/specs/federated-config-sharing/spec.md
	 */
	public function types(): array;

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
	public function bundle(string $typeId, array $selection): array;

	/**
	 * Install a bundle, subject to the org's source allowlist.
	 *
	 * @param string $typeId The shareable type id.
	 * @param array $bundle A bundle produced by a type of this id.
	 * @param string $source Where the bundle came from (a repo/source id), for the allowlist.
	 * @param (callable(array): array)|null $prepare Runs on the verified bundle before it is imported, such as stamping an install target.
	 *
	 * @return array The install result.
	 *
	 * @throws UnexpectedValueException When no type owns the id.
	 * @throws RuntimeException When the source is not allowlisted.
	 *
	 * @spec openspec/changes/federated-config-sharing/specs/federated-config-sharing/spec.md
	 * @spec openspec/changes/store-install-target/specs/apphost-store-plane/spec.md#requirement-an-install-can-go-into-a-target-the-app-declares
	 */
	public function install(string $typeId, array $bundle, string $source = '', ?callable $prepare = null): array;

	/**
	 * Publish a bundle to a GitHub repository, with the token supplied by the
	 * broker (Doriath). One file per bundle path via the contents API.
	 *
	 * @param string $typeId The shareable type id.
	 * @param array $selection What to share.
	 * @param ConfigPublishTarget $target Where to publish: repo, path, branch and visibility of a created repo.
	 * @param string $credentialId The broker credential id custodying the GitHub token.
	 * @param string|null $appId The app the broker calls are made for; OpenRegister when null.
	 * @param string|null $actingUserId The acting user for a sessionless call, or null.
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
		ConfigPublishTarget $target,
		string $credentialId,
		?string $appId = null,
		?string $actingUserId = null
	): array;

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
	 * @param string|null $appId The app the broker calls are made for; OpenRegister when null.
	 * @param string|null $actingUserId The acting user for a sessionless call, or null.
	 *
	 * @return array<int, array<string, mixed>> The discovered repositories as
	 *                                          `{repo, name, description, url, stars, updated, topics}` cards.
	 *
	 * @spec openspec/changes/federated-config-sharing/specs/federated-config-sharing/spec.md
	 */
	public function discover(string $topic, ?string $credentialId = null, ?string $appId = null, ?string $actingUserId = null): array;

	/**
	 * Discover published bundles and say how the search went.
	 *
	 * Like discover(), with a free-text term searched together with the topic,
	 * and the outcome instead of an empty list on failure: `ok`, `rate_limited`
	 * (GitHub answered 403 or 429) or `unreachable` (any other failure). A read
	 * the broker refuses is retried anonymously; `brokerUsed` says which one
	 * GitHub answered.
	 *
	 * @param string              $topic        The discovery topic to search for.
	 * @param string              $term         A free-text term searched together with the topic; '' for none.
	 * @param string|null         $credentialId Optional broker credential for an authenticated search.
	 * @param BrokerIdentity|null $identity     The app and acting user the broker call is made for; OpenRegister when null.
	 *
	 * @return array{outcome: string, cards: array<int, array<string, mixed>>, brokerUsed: bool, rateLimited: bool, status: int}
	 *
	 * @spec openspec/changes/store-through-federated-config
	 */
	public function discoverOutcome(string $topic, string $term = '', ?string $credentialId = null, ?BrokerIdentity $identity = null): array;

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
	 * @param string|null $appId The app the broker calls are made for; OpenRegister when null.
	 * @param string|null $actingUserId The acting user for a sessionless call, or null.
	 *
	 * @return array The decoded bundle.
	 *
	 * @throws RuntimeException When the file cannot be fetched or decoded.
	 *
	 * @spec openspec/changes/federated-config-sharing/specs/federated-config-sharing/spec.md
	 */
	public function fetchBundle(
		string $repo,
		string $path,
		?string $credentialId = null,
		?string $appId = null,
		?string $actingUserId = null
	): array;

	/**
	 * Read a package's files out of a GitHub repository at a ref: one tarball
	 * download where possible, else the tree and one read per file; a brokered
	 * read the broker refuses is retried anonymously.
	 *
	 * @param string              $repo         The `owner/repo`.
	 * @param string|null         $ref          A branch, tag or commit; the default branch when null.
	 * @param array<int, string>  $paths        Exact paths, and prefixes ending in `/`; [] reads every file.
	 * @param string|null         $credentialId Optional broker credential.
	 * @param BrokerIdentity|null $identity     The app and acting user a brokered read names.
	 *
	 * @return array{files: array<string, string>, truncated: bool, source: string}
	 *
	 * @throws RuntimeException When the repository or the ref is not a safe GitHub coordinate.
	 */
	public function fetchPackage(
		string $repo,
		?string $ref = null,
		array $paths = [],
		?string $credentialId = null,
		?BrokerIdentity $identity = null
	): array;

	/**
	 * Publish many files into a GitHub repository as one Git Data commit on the
	 * branch tip's tree, creating the repository when absent, and return the
	 * commit sha. A request made only-into-a-new-repository refuses one that exists.
	 *
	 * @param RepoFilesPublish $request The files, the message, the target and the broker credential.
	 *
	 * @return array{published: true, repo: string, branch: string, commit: string, files: int}
	 *
	 * @throws RuntimeException When the publish cannot be made.
	 */
	public function publishFiles(RepoFilesPublish $request): array;

	/**
	 * This instance's signing public key (base64) for others to trust.
	 *
	 * @return string The base64 Ed25519 public key.
	 *
	 * @spec openspec/changes/federated-config-sharing/specs/federated-config-sharing/spec.md
	 */
	public function publicKey(): string;

	/**
	 * The organisation's trust configuration (for a governance UI).
	 *
	 * @return array{sourceAllowlist: string, trustedKeys: string, publishGroups: string, installGroups: string}
	 *
	 * @spec openspec/changes/federated-config-sharing/specs/federated-config-sharing/spec.md
	 */
	public function getTrustConfig(): array;

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
	public function setTrustValue(string $field, string $value): void;

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
	public function trustPublisherKey(string $publicKey): void;

	/**
	 * Whether a source may be installed from.
	 *
	 * An empty allowlist allows nothing, and an empty source is never allowed.
	 *
	 * @param string $source The source id (a repo, an org).
	 *
	 * @return boolean Whether it is allowed.
	 *
	 * @spec openspec/changes/federated-config-sharing/specs/federated-config-sharing/spec.md
	 */
	public function isSourceAllowed(string $source): bool;
}//end interface
