<?php

/**
 * The readers the mint guard resolves a view, and what it names, through.
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

use OCA\OpenRegister\Db\SchemaCatalogInterface;
use OCA\OpenRegister\Kernel\Contracts\Core\ViewFinderInterface;
use OCA\OpenRegister\Service\Rbac\ViewerReachCheckInterface;

/**
 * The view, register and schema mappers, each asked under the caller's own rules.
 *
 * @spec openspec/changes/access-by-link-not-by-account/specs/public-access-links/spec.md#requirement-a-publication-link-opens-one-object-view-or-file-as-its-own-principal-req-abl-001
 */
final readonly class AccessLinkViewReaders {

	/**
	 * Bundle the collaborators.
	 *
	 * @param ViewFinderInterface            $views   Resolves a view under the caller's own rules.
	 * @param SchemaCatalogInterface         $catalog Resolves the registers and schemas a view names, same rules.
	 * @param ViewerReachCheckInterface|null $reach   Whether a view reaches the caller (owned, shared with one of
	 *                                                their groups, public, or administrator), the rule the views
	 *                                                endpoint answers a read with.
	 *
	 * @spec openspec/changes/access-links-managed-per-subject/specs/public-access-links/spec.md#requirement-whoever-may-publish-a-subject-sees-and-revokes-every-link-on-it-req-abl-005
	 */
	public function __construct(
		public ViewFinderInterface $views,
		public SchemaCatalogInterface $catalog,
		public ?ViewerReachCheckInterface $reach = null,
	) {
	}//end __construct()
}//end class
