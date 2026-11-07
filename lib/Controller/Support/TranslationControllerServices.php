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

use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Db\TranslationMapper;
use OCA\OpenRegister\Service\BulkTranslationService;
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
	 * @param TranslationStatusServiceInterface $statusService     The translation status service.
	 * @param TranslationMapper                 $translationMapper The translation mapper.
	 * @param SchemaMapperInterface             $schemaMapper      The schema mapper.
	 * @param BulkTranslationService            $bulkService       The bulk translation service.
	 *
	 * @spec openspec/changes/i18n-source-of-truth/tasks.md#phase-3
	 */
	public function __construct(
		public readonly TranslationStatusServiceInterface $statusService,
		public readonly TranslationMapper $translationMapper,
		public readonly SchemaMapperInterface $schemaMapper,
		public readonly BulkTranslationService $bulkService,
	) {
	}//end __construct()
}//end class
