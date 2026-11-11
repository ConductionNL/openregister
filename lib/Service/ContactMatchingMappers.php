<?php

/**
 * The schema and register mappers contact matching searches through.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
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

namespace OCA\OpenRegister\Service;

use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Db\SchemaMapperInterface;

/**
 * The schema and register mappers contact matching searches through.
 *
 * @spec openspec/specs/integration-contacts/spec.md
 */
final class ContactMatchingMappers {
	/**
	 * Bundle the collaborators.
	 *
	 * @param SchemaMapperInterface   $schemaMapper   Schema mapper.
	 * @param RegisterMapperInterface $registerMapper Register mapper.
	 *
	 * @spec openspec/specs/integration-contacts/spec.md
	 */
	public function __construct(
		public readonly SchemaMapperInterface $schemaMapper,
		public readonly RegisterMapperInterface $registerMapper,
	) {
	}//end __construct()
}//end class
