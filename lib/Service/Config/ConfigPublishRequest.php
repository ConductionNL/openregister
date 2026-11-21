<?php

/**
 * ConfigPublishRequest — one configuration bundle to publish, and where to.
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
 * A request to publish a selection of a shareable type's configuration to a brokered GitHub target.
 *
 * @spec openspec/changes/federated-config-sharing/specs/federated-config-sharing/spec.md
 */
final class ConfigPublishRequest {
	/**
	 * Name the publication.
	 *
	 * @param string                $typeId      The shareable type id.
	 * @param array                 $selection   What to share, in the type's own terms.
	 * @param BrokeredPublishTarget $destination The repo, path, branch and visibility, with the broker credential.
	 *
	 * @spec openspec/changes/federated-config-sharing/specs/federated-config-sharing/spec.md
	 */
	public function __construct(
		public readonly string $typeId,
		public readonly array $selection,
		public readonly BrokeredPublishTarget $destination,
	) {
	}//end __construct()
}//end class
