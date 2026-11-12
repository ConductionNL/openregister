<?php

/**
 * The register and schema mappers a service resolves registers and schemas through.
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
 * The register and schema mappers a service resolves registers and schemas through.
 *
 * @spec openspec/specs/register-i18n/spec.md
 */
final class RegisterSchemaMappers {
	/**
	 * Bundle the collaborators.
	 *
	 * @param RegisterMapperInterface $registerMapper Register mapper.
	 * @param SchemaMapperInterface   $schemaMapper   Schema mapper.
	 *
	 * @spec openspec/specs/register-i18n/spec.md
	 */
	public function __construct(
		public readonly RegisterMapperInterface $registerMapper,
		public readonly SchemaMapperInterface $schemaMapper,
	) {
	}//end __construct()
}//end class
