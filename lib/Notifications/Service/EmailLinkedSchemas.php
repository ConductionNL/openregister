<?php

/**
 * Which schemas take mail links, and the objects behind them.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Notifications\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Notifications\Service;

use OCA\OpenRegister\Db\MagicMapperInterface;
use OCA\OpenRegister\Db\SchemaMapperInterface;

/**
 * Which schemas take mail links, and the objects behind them.
 *
 * @spec openspec/specs/mail-sidebar/spec.md
 */
final readonly class EmailLinkedSchemas {
	/**
	 * Bundle the services.
	 *
	 * @param SchemaMapperInterface $schemaMapper Schema mapper.
	 * @param MagicMapperInterface $magicMapper Magic object mapper.
	 */
	public function __construct(
		public SchemaMapperInterface $schemaMapper,
		public MagicMapperInterface $magicMapper,
	) {
	}//end __construct()
}//end class
