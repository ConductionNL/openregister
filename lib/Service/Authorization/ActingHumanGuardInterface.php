<?php

/**
 * ActingHumanGuardInterface: what the object write path asks of ActingHumanGuard.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Authorization
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/scoped-api-tokens-machine-callers/specs/auth-system/spec.md#requirement-a-machine-write-names-its-acting-human-when-the-instance-or-schema-requires-it-req-sat-006
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Authorization;

use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Exception\ActingHumanRefusedException;

/**
 * Refuses a machine write that does not name a human it may act for.
 *
 * @spec openspec/changes/scoped-api-tokens-machine-callers/specs/auth-system/spec.md#requirement-a-machine-write-names-its-acting-human-when-the-instance-or-schema-requires-it-req-sat-006
 */
interface ActingHumanGuardInterface {

	/**
	 * Refuse a create, update or delete by a machine caller that names no delegating human.
	 *
	 * @param Schema|null $schema The schema written to, read for its annotation.
	 *
	 * @return void
	 *
	 * @throws ActingHumanRefusedException When the write must name a human and does not, or may not act for them.
	 *
	 * @spec openspec/changes/scoped-api-tokens-machine-callers/specs/auth-system/spec.md#requirement-a-machine-write-names-its-acting-human-when-the-instance-or-schema-requires-it-req-sat-006
	 */
	public function assertMayWrite(?Schema $schema): void;
}//end interface
