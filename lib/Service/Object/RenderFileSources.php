<?php

/**
 * OpenRegister render file sources
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Object
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

namespace OCA\OpenRegister\Service\Object;

use OCA\OpenRegister\Kernel\Contracts\Core\FileNodeLookupInterface;
use OCA\OpenRegister\Kernel\Contracts\Core\ObjectFileRecordsInterface;
use OCP\SystemTag\ISystemTagManager;
use OCP\SystemTag\ISystemTagObjectMapper;

/**
 * Where a render reads an object's files and their tags.
 *
 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
 */
final class RenderFileSources {
	/**
	 * Bundle the services.
	 *
	 * @param ObjectFileRecordsInterface  $fileMapper       The file metadata store.
	 * @param FileNodeLookupInterface     $fileService      The file reader.
	 * @param ISystemTagManager           $systemTagManager The system tag manager.
	 * @param ISystemTagObjectMapper      $systemTagMapper  The system tag object mapper.
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	public function __construct(
		public readonly ObjectFileRecordsInterface $fileMapper,
		public readonly FileNodeLookupInterface $fileService,
		public readonly ISystemTagManager $systemTagManager,
		public readonly ISystemTagObjectMapper $systemTagMapper,
	) {
	}//end __construct()
}//end class
