<?php

/**
 * LinkAct: One act of an access link on one object.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Sharing
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/access-by-link-not-by-account/specs/public-access-links/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Sharing;

use OCA\OpenRegister\Db\AccessLink;
use OCA\OpenRegister\Db\ObjectEntity;

/**
 * The link acting and the object it acts on, as {@see AccessLinkActs} takes them.
 *
 * @spec openspec/changes/access-by-link-not-by-account/specs/public-access-links/spec.md
 */
final class LinkAct {

	/**
	 * Constructor.
	 *
	 * @param AccessLink   $link   The link acting.
	 * @param ObjectEntity $object The object acted on.
	 */
	public function __construct(
		public readonly AccessLink $link,
		public readonly ObjectEntity $object,
	) {
	}//end __construct()
}//end class
