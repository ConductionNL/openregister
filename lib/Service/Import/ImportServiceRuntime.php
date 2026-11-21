<?php

/**
 * The runtime surroundings of ImportService: the container, the push batch and the logger.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Import
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/data-import-export/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Import;

use OCA\OpenRegister\Listener\NotifyPushBatchInterface;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * The container ImportService resolves its optional collaborators from lazily
 * (the configuration import handler, the push queue), the notify_push batch a
 * bulk save runs in, and the logger.
 *
 * @spec openspec/specs/data-import-export/spec.md
 */
final class ImportServiceRuntime {

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface       $container Resolves optional collaborators lazily.
	 * @param NotifyPushBatchInterface $pushBatch The notify_push batch mode a bulk save runs in.
	 * @param LoggerInterface          $logger    The logger.
	 */
	public function __construct(
		public readonly ContainerInterface $container,
		public readonly NotifyPushBatchInterface $pushBatch,
		public readonly LoggerInterface $logger,
	) {
	}//end __construct()
}//end class
