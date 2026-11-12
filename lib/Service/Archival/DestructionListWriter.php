<?php

/**
 * OpenRegister destruction list writer
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Archival
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

namespace OCA\OpenRegister\Service\Archival;

use OCA\OpenRegister\Db\MagicMapperInterface;
use OCA\OpenRegister\Service\Object\SaveObjectWriterInterface;

/**
 * The object reads and the save path a new destruction list goes through.
 *
 * @spec openspec/specs/archival-destruction-workflow/spec.md
 */
final class DestructionListWriter {
	/**
	 * Bundle the collaborators.
	 *
	 * @param MagicMapperInterface      $objectMapper Loads the named objects.
	 * @param SaveObjectWriterInterface $saveObject   Stores the list as the sweep does.
	 */
	public function __construct(
		public readonly MagicMapperInterface $objectMapper,
		public readonly SaveObjectWriterInterface $saveObject,
	) {
	}//end __construct()
}//end class
