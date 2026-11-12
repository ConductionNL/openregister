<?php

/**
 * OpenRegister file extraction controller services
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

namespace OCA\OpenRegister\Files\Controller\Support;

/**
 * The service bundles of the file extraction API.
 *
 * @spec openspec/specs/object-lifecycle/spec.md
 */
final class FileExtractionControllerServices {
	/**
	 * Bundle the services.
	 *
	 * @param FileExtractionMappers  $mappers  The mappers bundle.
	 * @param FileExtractionDomain   $domain   The domain bundle.
	 * @param FileExtractionPlatform $platform The platform bundle.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function __construct(
		public readonly FileExtractionMappers $mappers,
		public readonly FileExtractionDomain $domain,
		public readonly FileExtractionPlatform $platform,
	) {
	}//end __construct()
}//end class
