<?php

/**
 * OpenRegister DsarStores
 *
 * Where a subject access request finds and resolves data: the relation tables, the objects and the processing activity.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/specs/dsar-case-api/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

use OCA\OpenRegister\Db\MagicMapperInterface;
use OCA\OpenRegister\Db\VerwerkingsactiviteitMapperInterface;
use OCP\IAppConfig;
use OCP\IDBConnection;

/**
 * Where a subject access request finds and resolves data: the relation tables, the objects and the processing activity.
 *
 * @spec openspec/specs/dsar-case-api/spec.md
 */
final readonly class DsarStores {
	/**
	 * Bundle the collaborators.
	 *
	 * @param IDBConnection                        $db           Database connection for the GdprEntity and entity_relations lookup.
	 * @param MagicMapperInterface                 $objectMapper Object-routing mapper used to load found objects.
	 * @param VerwerkingsactiviteitMapperInterface $vrwMapper    Resolves the configured DSAR activity reference.
	 * @param IAppConfig                           $appConfig    App-config reader.
	 */
	public function __construct(
		public IDBConnection $db,
		public MagicMapperInterface $objectMapper,
		public VerwerkingsactiviteitMapperInterface $vrwMapper,
		public IAppConfig $appConfig,
	) {
	}//end __construct()
}//end class
