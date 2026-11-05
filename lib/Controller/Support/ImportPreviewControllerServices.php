<?php

/**
 * OpenRegister import preview controller services
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

/**
 * The service bundles of the import preview API.
 *
 * @spec openspec/changes/import-preview-and-conflict-policy/specs/data-import-export/spec.md
 */
final class ImportPreviewControllerServices {
	/**
	 * Bundle the services.
	 *
	 * @param ImportPreviewMappers  $mappers  The mappers bundle.
	 * @param ImportPreviewDomain   $domain   The domain bundle.
	 * @param ImportPreviewPlatform $platform The platform bundle.
	 *
	 * @spec openspec/changes/import-preview-and-conflict-policy/specs/data-import-export/spec.md
	 */
	public function __construct(
		public readonly ImportPreviewMappers $mappers,
		public readonly ImportPreviewDomain $domain,
		public readonly ImportPreviewPlatform $platform,
	) {
	}//end __construct()
}//end class
