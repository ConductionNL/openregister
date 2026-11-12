<?php

/**
 * OpenRegister file text mappers
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

namespace OCA\OpenRegister\Files\Controller\Support;

use OCA\OpenRegister\Files\Db\EntityRelationMapperInterface;

/**
 * The mappers and repositories of the file text API.
 *
 * @spec openspec/specs/api-test-coverage/spec.md
 */
final class FileTextMappers {
	/**
	 * Bundle the services.
	 *
	 * @param EntityRelationMapperInterface $entityRelationMapper Entity relation mapper
	 *
	 * @spec openspec/specs/api-test-coverage/spec.md
	 */
	public function __construct(
		public readonly EntityRelationMapperInterface $entityRelationMapper,
	) {
	}//end __construct()
}//end class
