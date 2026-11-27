<?php

/**
 * Add `sample_count` and `sample_sum_ms` to `openregister_metrics` on a fresh install.
 *
 * @category Migration
 * @package  OCA\OpenRegister\Migration
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/production-observability
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Migration;

/**
 * The request metrics columns again, in a step that runs after the table exists.
 *
 * Nextcloud orders migration steps by the number before `Date` first
 * (`MigrationService::sortMigrations()`), so every `Version1Date…` step runs
 * before `Version002005000Date20251013000000`, which creates
 * `openregister_metrics`. On a fresh install {@see Version1Date20261116230000}
 * therefore finds no table, does nothing and is recorded as done; the table is
 * created afterwards without the two columns, and every request metrics flush
 * fails with "Unknown column 'sample_count'". This step carries version
 * 002006000 and a later date, so it sorts after the table is created. Its work
 * is the same idempotent change: on an upgraded instance, where the earlier
 * step already added both columns, it does nothing.
 *
 * @spec openspec/changes/production-observability
 */
class Version002006000Date20261126010000 extends Version1Date20261116230000 {
}//end class
