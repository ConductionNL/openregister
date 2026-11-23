<?php

/**
 * OpenRegister RecordTemplatesController services
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

use OCA\OpenRegister\Service\Rbac\ViewerReachResolverInterface;
use OCA\OpenRegister\Service\RecordTemplate\RecordTemplateStoreInterface;
use Psr\Log\LoggerInterface;

/**
 * The collaborators of RecordTemplatesController, wired by DI autowiring.
 *
 * @spec openspec/changes/records-saved-templates/specs/record-templates/spec.md#requirement-a-user-can-save-and-share-a-named-record-template
 */
final class RecordTemplatesControllerServices {
	/**
	 * Bundle the services.
	 *
	 * @param RecordTemplateStoreInterface $templates Decides and stores.
	 * @param ViewerReachResolverInterface $viewers   Names the caller and their groups.
	 * @param LoggerInterface              $logger    Logger.
	 */
	public function __construct(
		public readonly RecordTemplateStoreInterface $templates,
		public readonly ViewerReachResolverInterface $viewers,
		public readonly LoggerInterface $logger,
	) {
	}//end __construct()
}//end class
