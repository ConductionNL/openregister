<?php

/**
 * OpenRegister LikeOperators.
 *
 * Builds the `like` operator for a database platform.
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
 * Builds the `like` operator for a database platform, so callers outside
 * lib/Db depend on {@see LikeOperatorInterface} only.
 */
final class LikeOperators {

	/**
	 * The `like` operator for this platform.
	 *
	 * @param object $databasePlatform A Doctrine platform, from IDBConnection or a DBAL connection.
	 *
	 * @return LikeOperatorInterface The operator.
	 */
	public static function forPlatform(object $databasePlatform): LikeOperatorInterface {
		return new LikeOperator(databasePlatform: $databasePlatform);
	}//end forPlatform()
}//end class
