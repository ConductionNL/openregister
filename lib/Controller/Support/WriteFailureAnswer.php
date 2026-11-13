<?php

/**
 * The answer to an object write that failed for a reason no write route recognises.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller\Support
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/objects-crud/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Controller\Support;

use OCA\OpenRegister\Exception\DatabaseConstraintException;
use OCA\OpenRegister\Exception\DatabaseErrorChain;
use OCP\AppFramework\Http\JSONResponse;
use Psr\Log\LoggerInterface;

/**
 * A 403 with the refusal's own message; with a database error in its chain, the
 * generic constraint sentence instead, and the original goes to the log.
 *
 * @spec openspec/specs/objects-crud/spec.md
 */
final class WriteFailureAnswer {

	/**
	 * Constructor.
	 *
	 * @param LoggerInterface $logger Receives the database error the client is not shown.
	 */
	public function __construct(private readonly LoggerInterface $logger) {
	}//end __construct()

	/**
	 * The response for an unrecognised write failure.
	 *
	 * @param \Exception $exception The failure.
	 *
	 * @return JSONResponse The 403.
	 */
	public function respond(\Exception $exception): JSONResponse {
		$database = DatabaseErrorChain::causeOf(failure: $exception);
		if ($database === null) {
			return new JSONResponse(data: ['error' => $exception->getMessage()], statusCode: 403);
		}

		$this->logger->error(
			message: '[ObjectsController] Object write failed in the database',
			context: ['file' => __FILE__, 'line' => __LINE__, 'exception' => $exception]
		);

		$constraint = DatabaseConstraintException::fromDatabaseException(dbException: $database, entityType: 'object');

		return new JSONResponse(data: ['error' => $constraint->getMessage()], statusCode: 403);
	}//end respond()
}//end class
