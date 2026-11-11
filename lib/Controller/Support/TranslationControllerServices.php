<?php

/**
 * OpenRegister translation controller services
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

use OCA\OpenRegister\Service\BulkTranslationServiceInterface;
use OCA\OpenRegister\Service\TranslationStatusServiceInterface;

/**
 * The services of the translation API.
 *
 * @spec openspec/changes/i18n-source-of-truth/tasks.md#phase-3
 */
final class TranslationControllerServices {
	/**
	 * Bundle the services.
	 *
	 * @param TranslationStatusServiceInterface $statusService The translation status service.
	 * @param BulkTranslationServiceInterface   $bulkService   The bulk translation service.
	 * @param TranslationMappers                $mappers       The translation, schema and object mappers.
	 *
	 * @spec openspec/changes/i18n-source-of-truth/tasks.md#phase-3
	 */
	public function __construct(
		public readonly TranslationStatusServiceInterface $statusService,
		public readonly BulkTranslationServiceInterface $bulkService,
		public readonly TranslationMappers $mappers,
	) {
	}//end __construct()
}//end class
