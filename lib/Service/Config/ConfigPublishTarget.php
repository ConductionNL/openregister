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
}//end class
