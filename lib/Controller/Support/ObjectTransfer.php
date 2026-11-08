<?php

/**
 * OpenRegister object transfer services
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

use OCA\OpenRegister\Kernel\Contracts\ObjectExporterInterface;
use OCA\OpenRegister\Kernel\Contracts\ObjectImporterInterface;
use OCA\OpenRegister\Service\Object\UpsertOnKeyHandlerInterface;
use OCA\OpenRegister\Service\WebhookService;

/**
 * Export, import, webhook delivery and upsert-on-key for object writes and transfers.
 *
 * @spec openspec/specs/objects-crud/spec.md
 */
final class ObjectTransfer {
	/**
	 * Bundle the services.
	 *
	 * @param ObjectExporterInterface $exportService      The export service.
	 * @param ObjectImporterInterface $importService      The import service.
	 * @param ?WebhookService         $webhookService     The webhook service, null when absent.
	 * @param ?UpsertOnKeyHandlerInterface $upsertOnKeyHandler Upsert on a declared key, null when absent.
	 *
	 * @spec openspec/specs/objects-crud/spec.md
	 */
	public function __construct(
		public readonly ObjectExporterInterface $exportService,
		public readonly ObjectImporterInterface $importService,
		public readonly ?WebhookService $webhookService = null,
		public readonly ?UpsertOnKeyHandlerInterface $upsertOnKeyHandler = null,
	) {
	}//end __construct()
}//end class
