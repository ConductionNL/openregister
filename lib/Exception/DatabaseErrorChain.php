<?php

/**
 * Finds a database error in an exception's chain.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Exception
 * @package  OCA\OpenRegister\Exception
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Exception;

use Doctrine\DBAL\Exception as DbalException;
use OCP\DB\Exception as DBException;
use PDOException;
use Throwable;

/**
 * A database error's text names tables, columns and SQL, so it must not reach a client.
 */
final class DatabaseErrorChain {

	/**
	 * The first database error in the failure's chain, or null.
	 *
	 * @param Throwable $failure The failure.
	 *
	 * @return \Exception|null The database error.
	 */
	public static function causeOf(Throwable $failure): ?\Exception {
		for ($cause = $failure; $cause !== null; $cause = $cause->getPrevious()) {
			if ($cause instanceof DBException || $cause instanceof DbalException || $cause instanceof PDOException) {
				return $cause;
			}
		}

		return null;
	}//end causeOf()
}//end class
