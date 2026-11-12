<?php

/**
 * BundleFetchRequest — the published bundle file to fetch from a repository.
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
 * A request to fetch a published bundle file: the repo, the path inside it and an optional broker credential.
 *
 * @spec openspec/changes/federated-config-sharing/specs/federated-config-sharing/spec.md
 */
final class BundleFetchRequest {
	/**
	 * Name the file.
	 *
	 * @param string      $repo         The `owner/repo`, trimmed.
	 * @param string      $path         The bundle file path within the repo, trimmed, without a leading slash.
	 * @param string|null $credentialId Optional broker credential for an authenticated read.
	 *
	 * @spec openspec/changes/federated-config-sharing/specs/federated-config-sharing/spec.md
	 */
	public function __construct(
		public readonly string $repo,
		public readonly string $path,
		public readonly ?string $credentialId,
	) {
	}//end __construct()

	/**
	 * The contents-API path of the file.
	 *
	 * @return string `/repos/<repo>/contents/<path>`.
	 *
	 * @spec openspec/changes/federated-config-sharing/specs/federated-config-sharing/spec.md
	 */
	public function contentsPath(): string {
		return sprintf('/repos/%s/contents/%s', $this->repo, $this->path);
	}//end contentsPath()

	/**
	 * The file and repo, for messages.
	 *
	 * @return string `<path> in <repo>`.
	 *
	 * @spec openspec/changes/federated-config-sharing/specs/federated-config-sharing/spec.md
	 */
	public function where(): string {
		return sprintf('%s in %s', $this->path, $this->repo);
	}//end where()
}//end class
