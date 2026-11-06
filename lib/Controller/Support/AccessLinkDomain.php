<?php

/**
 * OpenRegister access link domain
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

use OCA\OpenRegister\Service\Sharing\AccessLinkActs;
use OCA\OpenRegister\Service\Sharing\AccessLinkMintGuard;
use OCA\OpenRegister\Kernel\Contracts\AccessLinkReaderInterface;
use OCA\OpenRegister\Service\Sharing\AccessLinkService;

/**
 * The domain services of the access link API.
 *
 * @spec openspec/changes/access-by-link-not-by-account/specs/public-access-links/spec.md
 */
final class AccessLinkDomain {
	/**
	 * Bundle the services.
	 *
	 * @param AccessLinkService   $links     The link lifecycle.
	 * @param AccessLinkReaderInterface    $reader    Serves what a link opens.
	 * @param AccessLinkMintGuard $mintGuard Decides whether a caller may publish a subject.
	 * @param AccessLinkActs      $acts      Performs the comment and the upload, as the link.
	 *
	 * @spec openspec/changes/access-by-link-not-by-account/specs/public-access-links/spec.md
	 */
	public function __construct(
		public readonly AccessLinkService $links,
		public readonly AccessLinkReaderInterface $reader,
		public readonly AccessLinkMintGuard $mintGuard,
		public readonly AccessLinkActs $acts,
	) {
	}//end __construct()
}//end class
