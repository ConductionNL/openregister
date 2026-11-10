<?php

/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Service
 * @package   OCA\OpenRegister\Service
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://www.OpenRegister.app
 *
 * @spec openspec/specs/generic-integrations/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

use OCP\Files\Node;

/**
 * A validated request to share one file of an object: the node, the supported share type and the draft.
 *
 * @spec openspec/specs/generic-integrations/spec.md
 */
final class ShareRequest {
	/**
	 * Constructor.
	 *
	 * @param Node                                    $node      The file inside the object's folder.
	 * @param value-of<ShareLinkService::SHARE_TYPES> $shareType A supported share type.
	 * @param ShareDraft                              $draft     The recipient, permissions, password and expiry.
	 */
	public function __construct(
		public readonly Node $node,
		public readonly int $shareType,
		public readonly ShareDraft $draft,
	) {
	}//end __construct()
}//end class
