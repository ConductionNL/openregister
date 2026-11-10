<?php

/**
 * ExternalGrantLapseNotifierInterface: what callers outside the Service component ask of ExternalGrantLapseNotifier.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Rbac
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Rbac;

use OCA\OpenRegister\Db\Schema;

/**
 * Warning the holders of a lapsing external grant, as the lapse job does. Only the method that caller calls.
 */
interface ExternalGrantLapseNotifierInterface {

	/**
	 * Warn every holder of one lapsing grant on a schema.
	 *
	 * @param Schema               $schema The schema the grant is written on.
	 * @param array<string, mixed> $grant  One entry of ExternalGrantGuard::lapsingWithin().
	 *
	 * @return int How many people were warned.
	 *
	 * @spec openspec/specs/authorization-rbac/spec.md
	 */
	public function warn(Schema $schema, array $grant): int;
}//end interface
