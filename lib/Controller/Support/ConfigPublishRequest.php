<?php

/**
 * OpenRegister ConfigPublishRequest
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller\Support
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Controller\Support;

use OCA\OpenRegister\Service\Config\ConfigPublishTarget;

/**
 * One publish of shared configuration: the type it publishes and where it goes.
 *
 * @spec openspec/changes/federated-config-sharing/specs/federated-config-sharing/spec.md
 */
final class ConfigPublishRequest {
	/**
	 * Hold the publish.
	 *
	 * @param string              $type   The shareable configuration type id.
	 * @param ConfigPublishTarget $target The repository, path, branch and visibility.
	 *
	 * @spec openspec/changes/federated-config-sharing/specs/federated-config-sharing/spec.md
	 */
	public function __construct(
		public readonly string $type,
		public readonly ConfigPublishTarget $target,
	) {
	}//end __construct()

	/**
	 * Whether the type, the repository or the path is missing.
	 *
	 * @return boolean True when any of the three is empty.
	 *
	 * @spec openspec/changes/federated-config-sharing/specs/federated-config-sharing/spec.md
	 */
	public function isIncomplete(): bool {
		return $this->type === '' || $this->target->repo === '' || $this->target->path === '';
	}//end isIncomplete()
}//end class
