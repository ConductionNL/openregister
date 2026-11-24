<?php

/**
 * ConfigPublishTarget: where a configuration bundle is published.
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

/**
 * Where a configuration bundle is published: the GitHub repository, the file
 * path within it, the branch, and whether a repository created on demand is
 * private.
 *
 * @spec openspec/changes/federated-config-sharing/specs/federated-config-sharing/spec.md
 */
final class ConfigPublishTarget {

	/**
	 * Whether a missing repository may be created under `/user/repos` when the organisation create fails.
	 *
	 * @var bool
	 */
	private bool $userNamespaceFallback = true;

	/**
	 * The description a repository created on demand gets; '' sets none.
	 *
	 * @var string
	 */
	private string $description = '';

	/**
	 * Name the target.
	 *
	 * @param string $repo    The `owner/repo` to publish into.
	 * @param string $path    The path within the repo for the bundle file.
	 * @param string $branch  The branch to publish onto.
	 * @param bool   $private Whether a repo created on demand is private.
	 *
	 * @spec openspec/changes/federated-config-sharing/specs/federated-config-sharing/spec.md
	 */
	public function __construct(
		public readonly string $repo,
		public readonly string $path,
		public readonly string $branch='main',
		public readonly bool $private=false,
	) {
	}//end __construct()

	/**
	 * This target with the fallback to the authenticated user's namespace switched off.
	 *
	 * Creating a missing repository tries the owner organisation first and then
	 * `/user/repos`. A publisher that must land in the named owner, or nowhere,
	 * turns the second attempt off: the repository is then created under the
	 * organisation or not at all.
	 *
	 * @return self A copy of this target without the fallback.
	 *
	 * @spec openspec/specs/federated-config-sharing/spec.md
	 */
	public function withoutUserNamespaceFallback(): self {
		$copy = clone $this;
		$copy->userNamespaceFallback = false;

		return $copy;
	}//end withoutUserNamespaceFallback()

	/**
	 * Whether creating a missing repository may fall back to the authenticated user's namespace.
	 *
	 * @return bool True unless withoutUserNamespaceFallback() switched it off.
	 *
	 * @spec openspec/specs/federated-config-sharing/spec.md
	 */
	public function allowsUserNamespaceFallback(): bool {
		return $this->userNamespaceFallback;
	}//end allowsUserNamespaceFallback()

	/**
	 * This target with a description for a repository created on demand.
	 *
	 * An existing repository keeps its own description.
	 *
	 * @param string $description The repository description.
	 *
	 * @return self A copy of this target with the description.
	 *
	 * @spec openspec/changes/store-through-federated-config
	 */
	public function withDescription(string $description): self {
		$copy = clone $this;
		$copy->description = trim($description);

		return $copy;
	}//end withDescription()

	/**
	 * The description a repository created on demand gets; '' for none.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/store-through-federated-config
	 */
	public function description(): string {
		return $this->description;
	}//end description()
}//end class
