<?php

/**
 * OpenRegister PartialObjectEntity
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Database
 * @package  OCA\OpenRegister\Db
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

/**
 * An object read with only its name, register, schema and data.
 *
 * It may be read, never written back: a write replaces the whole row, so the
 * columns this entity never read would be emptied.
 *
 * @spec openspec/specs/magic-table-reads/spec.md
 */
final class PartialObjectEntity extends ObjectEntity {
}//end class
