<?php

/**
 * One read of a GitHub repository: where, at which ref, and as whom.
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
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/store-through-federated-config
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Config;

use RuntimeException;

/**
 * A validated `owner/repo`, an optional ref and the broker identity a read is made with.
 *
 * The coordinates are checked against safe patterns before they are put in a
 * GitHub API path, so a caller-supplied value can never reach another
 * endpoint or another host.
 *
 * @spec openspec/changes/store-through-federated-config
 */
final class GitHubRepoRead {

	/**
	 * A safe owner or repository name.
	 */
	private const NAME_PATTERN = '/^[A-Za-z0-9._-]{1,100}$/';

	/**
	 * A safe ref: a branch, tag or sha; no spaces, no `..`.
	 */
	private const REF_PATTERN = '/^[A-Za-z0-9._\/-]{1,255}$/';

	/**
	 * Constructor.
	 *
	 * @param string         $owner        The repository owner.
	 * @param string         $name         The repository name.
	 * @param string|null    $ref          The ref, or null for the default branch.
	 * @param string|null    $credentialId The broker credential, or null for an anonymous read.
	 * @param BrokerIdentity $identity     Who a brokered read is made for.
	 */
	private function __construct(
		public readonly string $owner,
		public readonly string $name,
		public readonly ?string $ref,
		public readonly ?string $credentialId,
		public readonly BrokerIdentity $identity,
	) {
	}//end __construct()

	/**
	 * A read of `owner/repo` at a ref, validated.
	 *
	 * @param string              $repo         The `owner/repo`.
	 * @param string|null         $ref          The ref; null or '' for the default branch.
	 * @param string|null         $credentialId The broker credential; null or '' reads anonymously.
	 * @param BrokerIdentity|null $identity     Who a brokered read is made for; OpenRegister when null.
	 *
	 * @return self
	 *
	 * @throws RuntimeException When the repository or the ref is not a safe GitHub coordinate.
	 *
	 * @spec openspec/changes/store-through-federated-config
	 */
	public static function of(string $repo, ?string $ref = null, ?string $credentialId = null, ?BrokerIdentity $identity = null): self {
		[$owner, $name] = array_pad(explode('/', trim($repo), 2), 2, '');
		if (preg_match(self::NAME_PATTERN, $owner) !== 1 || preg_match(self::NAME_PATTERN, $name) !== 1
			|| $owner === '.' || $owner === '..' || $name === '.' || $name === '..'
		) {
			throw new RuntimeException('Reading a package needs a repository as owner/name.');
		}

		$ref = trim((string)$ref);
		if ($ref !== '' && (preg_match(self::REF_PATTERN, $ref) !== 1 || str_contains($ref, '..') === true)) {
			throw new RuntimeException('The ref is not a branch, tag or commit name.');
		}

		$credential = trim((string)$credentialId);
		$refOrNull  = null;
		if ($ref !== '') {
			$refOrNull = $ref;
		}

		$credentialOrNull = null;
		if ($credential !== '') {
			$credentialOrNull = $credential;
		}

		return new self(
			owner: $owner,
			name: $name,
			ref: $refOrNull,
			credentialId: $credentialOrNull,
			identity: ($identity ?? BrokerIdentity::of())
		);
	}//end of()

	/**
	 * The API path of the repository, `/repos/<owner>/<name>`.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/store-through-federated-config
	 */
	public function apiPath(): string {
		return '/repos/' . $this->owner . '/' . $this->name;
	}//end apiPath()

	/**
	 * The ref to read the tree or archive at: the given one, else `HEAD`.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/store-through-federated-config
	 */
	public function treeRef(): string {
		return ($this->ref ?? 'HEAD');
	}//end treeRef()
}//end class
