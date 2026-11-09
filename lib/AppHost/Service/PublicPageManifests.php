<?php

/**
 * OpenRegister public page manifests
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\AppHost\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 *
 * @spec openspec/changes/public-pages-open-without-a-session/specs/apphost-public-pages/spec.md#requirement-a-page-opens-without-a-session-only-when-the-app-declares-it-public-req-pub-001
 */

declare(strict_types=1);

namespace OCA\OpenRegister\AppHost\Service;

use OCP\App\IAppManager;
use Psr\Log\LoggerInterface;

/**
 * Finds a leaf app's manifest, and records one that cannot be read.
 *
 * @spec openspec/changes/public-pages-open-without-a-session/specs/apphost-public-pages/spec.md#requirement-a-page-opens-without-a-session-only-when-the-app-declares-it-public-req-pub-001
 */
final class PublicPageManifests {
	/**
	 * Bundle the manifest lookups.
	 *
	 * @param IAppManager     $appManager Resolves a leaf app's install path.
	 * @param LoggerInterface $logger     PSR logger.
	 *
	 * @spec openspec/changes/public-pages-open-without-a-session/specs/apphost-public-pages/spec.md#requirement-a-page-opens-without-a-session-only-when-the-app-declares-it-public-req-pub-001
	 */
	public function __construct(
		public readonly IAppManager $appManager,
		public readonly LoggerInterface $logger,
	) {
	}//end __construct()
}//end class
