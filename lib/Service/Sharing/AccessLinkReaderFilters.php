<?php

/**
 * OpenRegister access link reader filters
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Sharing
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

namespace OCA\OpenRegister\Service\Sharing;

use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Notifications\Service\Timeline\PublicTimelineInterface;
use OCA\OpenRegister\Service\PropertyRbacHandlerInterface;

/**
 * The rules an access link reader applies: schema resolution, property RBAC and the public timeline.
 *
 * @spec openspec/changes/access-by-link-not-by-account/specs/public-access-links/spec.md
 */
final class AccessLinkReaderFilters {
	/**
	 * Bundle the collaborators.
	 *
	 * @param SchemaMapperInterface        $schemas    Resolves the schema whose rules apply.
	 * @param PropertyRbacHandlerInterface $properties Strips write-only and unreadable properties.
	 * @param PublicTimelineInterface      $timeline   Reads and projects the public half of the timeline.
	 *
	 * @spec openspec/changes/access-by-link-not-by-account/specs/public-access-links/spec.md
	 */
	public function __construct(
		public readonly SchemaMapperInterface $schemas,
		public readonly PropertyRbacHandlerInterface $properties,
		public readonly PublicTimelineInterface $timeline,
	) {
	}//end __construct()
}//end class
