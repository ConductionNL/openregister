<?php

/**
 * OpenRegister SyncRecordInputs.
 *
 * Builds the values the sync record mapper takes.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Db
 * @package  OCA\OpenRegister\Db
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

/**
 * Builds the execution and failure values {@see SyncRecordMapperInterface}
 * takes, so callers outside lib/Db do not construct them by name.
 *
 * @spec openspec/specs/data-sync-harvesting/spec.md
 */
final class SyncRecordInputs {

	/**
	 * The execution a batch of pending records belongs to.
	 *
	 * @param Source $source      The source being synced.
	 * @param string $executionId Unique id of this run.
	 *
	 * @return SyncExecution The execution.
	 *
	 * @spec openspec/specs/data-sync-harvesting/spec.md
	 */
	public static function execution(Source $source, string $executionId): SyncExecution {
		return new SyncExecution(source: $source, executionId: $executionId);
	}//end execution()

	/**
	 * The failure to record against one sync record.
	 *
	 * @param string $status  The failure status (a SyncRecordStatus constant).
	 * @param string $message The error message.
	 *
	 * @return SyncFailure The failure.
	 *
	 * @spec openspec/specs/data-sync-harvesting/spec.md
	 */
	public static function failure(string $status, string $message): SyncFailure {
		return new SyncFailure(status: $status, message: $message);
	}//end failure()
}//end class
