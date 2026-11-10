<?php

/**
 * OpenRegister Database Constraint Exception
 *
 * This file contains the custom exception class for handling database constraint violations
 * with user-friendly error messages.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Exception
 * @package  OCA\OpenRegister\Exception
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

namespace OCA\OpenRegister\Exception;

use Exception;

/**
 * Exception class for database constraint violations
 *
 * This class provides user-friendly error messages for database constraint violations
 * and can determine the type of constraint violation from the database error message.
 * Extends the base Exception class to add HTTP status code support for API responses.
 *
 * @category Exception
 * @package  OCA\OpenRegister\Exception
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */
class DatabaseConstraintException extends Exception {

	/**
	 * HTTP status code for the error response
	 *
	 * Used to return appropriate HTTP status codes in API responses.
	 * Defaults to 409 Conflict for constraint violations.
	 *
	 * @var integer HTTP status code (typically 409 for conflicts)
	 */
	private readonly int $httpStatusCode;

	/**
	 * Constructor
	 *
	 * @param string $message The user-friendly error message
	 * @param int $code The error code
	 * @param int $httpStatus The HTTP status code (default: 409 Conflict)
	 * @param Exception|null $previous The previous exception
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function __construct(string $message, int $code = 0, int $httpStatus = 409, ?Exception $previous = null) {
		parent::__construct(message: $message, code: $code, previous: $previous);
		$this->httpStatusCode = $httpStatus;
	}//end __construct()

	/**
	 * Get the HTTP status code for this exception
	 *
	 * Returns the HTTP status code associated with this exception.
	 * Used by API controllers to return appropriate HTTP responses.
	 *
	 * @return int The HTTP status code (typically 409 for constraint violations)
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function getHttpStatusCode(): int {
		return $this->httpStatusCode;
	}//end getHttpStatusCode()

	/**
	 * Create a DatabaseConstraintException from a database exception
	 *
	 * Factory method that converts a raw database exception into a user-friendly
	 * DatabaseConstraintException. Parses the database error message to determine
	 * the type of constraint violation and generates appropriate user message.
	 *
	 * @param Exception $dbException The original database exception
	 * @param string $entityType The type of entity (e.g., 'schema', 'register', 'object')
	 *                           Used to customize error messages (default: 'item')
	 *
	 * @return DatabaseConstraintException The user-friendly exception with parsed message
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public static function fromDatabaseException(
		Exception $dbException,
		string $entityType = 'item',
	): DatabaseConstraintException {
		// Extract original database error message.
		$message = $dbException->getMessage();

		// Parse database error message to generate user-friendly message.
		$userMessage = self::parseConstraintError(dbMessage: $message, entityType: $entityType);

		// Create new exception with user-friendly message, preserving original exception.
		// HTTP status code defaults to 409 Conflict for constraint violations.
		return new self($userMessage, (int)$dbException->getCode(), 409, $dbException);
	}//end fromDatabaseException()

	/**
	 * Parse database constraint error messages and return user-friendly messages
	 *
	 * Analyzes database error messages to identify constraint violation types
	 * and generates user-friendly error messages. Handles various constraint
	 * types: unique, foreign key, NOT NULL, CHECK, and data length violations.
	 *
	 * @param string $dbMessage The database error message from the exception
	 * @param string $entityType The type of entity being saved (used in error messages)
	 *
	 * @return string User-friendly error message explaining the constraint violation
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	private static function parseConstraintError(string $dbMessage, string $entityType): string {
		return DatabaseConstraintMessages::forMessage(dbMessage: $dbMessage, entityType: $entityType);
	}//end parseConstraintError()
}//end class
