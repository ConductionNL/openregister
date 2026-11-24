<?php

/**
 * What one multi-file publish writes, where, and as whom.
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

/**
 * The files of one commit, the target and the broker credential, for
 * {@see FederatedConfigService::publishFiles()}.
 *
 * The target's path is not used: every file carries its own path. A request
 * that must create its repository, and refuse one that already exists, says so
 * with {@see onlyIntoANewRepository()}.
 *
 * @spec openspec/changes/store-through-federated-config
 */
final class RepoFilesPublish {

	/**
	 * Whether an existing repository is refused instead of written into.
	 *
	 * @var bool
	 */
	private bool $newRepositoryOnly = false;

	/**
	 * Constructor.
	 *
	 * @param ConfigPublishTarget   $target       The repository, branch, visibility and description of a created repo.
	 * @param array<string, string> $files        Repository path => file contents.
	 * @param string                $message      The commit message.
	 * @param string                $credentialId The broker credential custodying the GitHub token.
	 * @param BrokerIdentity|null   $identity     The app and acting user the calls name; OpenRegister when null.
	 * @param array<int, string>    $topics       Topics to add to the repository, keeping its own.
	 */
	public function __construct(
		public readonly ConfigPublishTarget $target,
		public readonly array $files,
		public readonly string $message,
		public readonly string $credentialId,
		public readonly ?BrokerIdentity $identity = null,
		public readonly array $topics = [],
	) {
	}//end __construct()

	/**
	 * This request, refusing a repository that already exists.
	 *
	 * @return self A copy that only publishes into a repository it creates.
	 *
	 * @spec openspec/changes/store-through-federated-config
	 */
	public function onlyIntoANewRepository(): self {
		$copy = clone $this;
		$copy->newRepositoryOnly = true;

		return $copy;
	}//end onlyIntoANewRepository()

	/**
	 * Whether an existing repository is refused.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/store-through-federated-config
	 */
	public function requiresNewRepository(): bool {
		return $this->newRepositoryOnly;
	}//end requiresNewRepository()

	/**
	 * The brokered target the calls are made against.
	 *
	 * @return BrokeredPublishTarget
	 *
	 * @spec openspec/changes/store-through-federated-config
	 */
	public function destination(): BrokeredPublishTarget {
		return new BrokeredPublishTarget(target: $this->target, credentialId: $this->credentialId, identity: $this->identity);
	}//end destination()
}//end class
