<?php

/**
 * HandlesExceptionsTrait
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Controller
 * @package   OCA\OpenRegister
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/openregister
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Controller\Trait;

use InvalidArgumentException;
use OCA\OpenRegister\AppHost\Exception\ConfigurationMissingException;
use OCA\OpenRegister\AppHost\Exception\FoundationUnavailableException;
use OCA\OpenRegister\Exception\AppendOnlyException;
use OCA\OpenRegister\Exception\ArchivalImmutableException;
use OCA\OpenRegister\Exception\DatabaseConstraintException;
use OCA\OpenRegister\Exception\FolderAccessDeniedException;
use OCA\OpenRegister\Exception\NotAuthorizedException;
use OCA\OpenRegister\Exception\RegisterNotFoundException;
use OCA\OpenRegister\Exception\SchemaNotFoundException;
use OCA\OpenRegister\Exception\ValidationException;
use OCA\OpenRegister\Service\Resolver\Exception\MissingConfigException;
use OCA\OpenRegister\Service\Resolver\Exception\PropertyNotFoundException;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Shared exception-handling helper for controllers.
 *
 * SEC-CTRL-7: prevents pervasive exception-message disclosure on HTTP 500
 * responses. Internal exception messages (stack-trace adjacent, SQL fragments,
 * file paths) must never be echoed to the client. This trait logs the real
 * exception server-side and returns a generic envelope to the caller.
 *
 * Two entry points:
 *
 *   - {@see errorResponse()} — legacy helper, ONLY for genuine 500 (internal
 *     error) paths. Behaviour unchanged for its existing consumers.
 *   - {@see handleApiException()} — the ADR-051 typed exception→HTTP-status
 *     translation with the ADR-050 `{message, error}` error envelope
 *     (published fleet-wide as the AppHost exception-translation consumable,
 *     ADR-066). Typed 4xx/5xx shapes keep their intentional, user-facing
 *     message; anything untyped falls through to a generic, leak-safe 500
 *     whose detail is written to the server log only.
 *
 * @category Controller
 * @package  OCA\OpenRegister
 * @author   Conduction <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/openregister
 */
trait HandlesExceptionsTrait {
	/**
	 * Translate a caught throwable into the ADR-050/ADR-051 JSON error response.
	 *
	 * Typed map (tasks.md 1.2 / ADR-051):
	 *   - not-found shapes                → 404
	 *   - forbidden shapes                → 403
	 *   - validation shapes               → 422 (typed) / 400 (InvalidArgumentException)
	 *   - conflict shapes                 → 409
	 *   - append-only / archival shapes   → 405
	 *   - foundation/config unavailable   → 503
	 *   - anything else                   → 500 with a generic body; the real
	 *     message is logged server-side only (leak-safe).
	 *
	 * Envelope (ADR-050): `{message, error}` — `message` human-readable,
	 * `error` a machine-readable kebab-case slug derived from the exception
	 * class name.
	 *
	 * @param Throwable $e The caught exception.
	 * @param string $context Optional short context label for the log line.
	 *
	 * @return JSONResponse The translated error response.
	 *
	 * @spec openspec/changes/apphost-settings-plane/specs/apphost-settings-plane/spec.md — Requirement: Exception-to-HTTP translation consumable
	 */
	protected function handleApiException(Throwable $e, string $context = ''): JSONResponse {
		$status = $this->exceptionStatus(e: $e);
		if ($status === null) {
			// Untyped/unexpected: generic 500, detailed server-side log only.
			return $this->errorResponse(e: $e, context: $context);
		}

		$this->logTranslatedException(e: $e, status: $status, context: $context);

		return new JSONResponse(
			data: [
				'message' => $e->getMessage(),
				'error' => $this->errorSlug(e: $e),
			],
			statusCode: $status
		);
	}//end handleApiException()

	/**
	 * The ordered typed exception→HTTP-status map (tasks.md 1.2 / ADR-051).
	 *
	 * Matched via `instanceof` in declaration order, so subclasses translate
	 * the same as their parents (e.g. CircularReferenceException → 422 via
	 * ValidationException); list a subclass BEFORE its parent when it needs a
	 * different status.
	 *
	 * @var array<class-string, int>
	 */
	private const EXCEPTION_STATUS_MAP = [
		// Not found → 404.
		DoesNotExistException::class => Http::STATUS_NOT_FOUND,
		RegisterNotFoundException::class => Http::STATUS_NOT_FOUND,
		SchemaNotFoundException::class => Http::STATUS_NOT_FOUND,
		\OCA\OpenRegister\Service\Resolver\Exception\RegisterNotFoundException::class => Http::STATUS_NOT_FOUND,
		\OCA\OpenRegister\Service\Resolver\Exception\SchemaNotFoundException::class => Http::STATUS_NOT_FOUND,
		PropertyNotFoundException::class => Http::STATUS_NOT_FOUND,
		// Forbidden → 403.
		NotAuthorizedException::class => Http::STATUS_FORBIDDEN,
		FolderAccessDeniedException::class => Http::STATUS_FORBIDDEN,
		// Validation → 422 typed / 400 argument-shaped.
		ValidationException::class => Http::STATUS_UNPROCESSABLE_ENTITY,
		\OCA\OpenRegister\Exception\CustomValidationException::class => Http::STATUS_UNPROCESSABLE_ENTITY,
		InvalidArgumentException::class => Http::STATUS_BAD_REQUEST,
		// Conflict → 409.
		MultipleObjectsReturnedException::class => Http::STATUS_CONFLICT,
		DatabaseConstraintException::class => Http::STATUS_CONFLICT,
		// Append-only / archival immutability → 405.
		AppendOnlyException::class => Http::STATUS_METHOD_NOT_ALLOWED,
		ArchivalImmutableException::class => Http::STATUS_METHOD_NOT_ALLOWED,
		// Foundation / configuration unavailable (ADR-049 fail-closed) → 503.
		FoundationUnavailableException::class => Http::STATUS_SERVICE_UNAVAILABLE,
		ConfigurationMissingException::class => Http::STATUS_SERVICE_UNAVAILABLE,
		MissingConfigException::class => Http::STATUS_SERVICE_UNAVAILABLE,
	];

	/**
	 * Map a throwable to its HTTP status, or null for the generic-500 fallback.
	 *
	 * @param Throwable $e The caught exception.
	 *
	 * @return int|null The HTTP status, or null when untyped (→ generic 500).
	 */
	private function exceptionStatus(Throwable $e): ?int {
		foreach (self::EXCEPTION_STATUS_MAP as $class => $status) {
			if ($e instanceof $class) {
				return $status;
			}
		}

		return null;
	}//end exceptionStatus()

	/**
	 * Derive the ADR-050 machine-readable kebab-case error slug from an
	 * exception class name (`RegisterNotFoundException` → `register-not-found`).
	 *
	 * @param Throwable $e The caught exception.
	 *
	 * @return string The kebab-case slug.
	 */
	private function errorSlug(Throwable $e): string {
		$basename = substr(strrchr('\\' . get_class($e), '\\'), 1);
		$trimmed = preg_replace(pattern: '/Exception$/', replacement: '', subject: $basename);
		$kebab = strtolower((string)preg_replace(pattern: '/([a-z0-9])([A-Z])/', replacement: '$1-$2', subject: (string)$trimmed));

		if ($kebab === '') {
			return 'error';
		}

		return $kebab;
	}//end errorSlug()

	/**
	 * Log a translated (typed, non-500) exception at warning level.
	 *
	 * @param Throwable $e The caught exception.
	 * @param int $status The HTTP status it was translated to.
	 * @param string $context Optional short context label for the log line.
	 *
	 * @return void
	 */
	private function logTranslatedException(Throwable $e, int $status, string $context): void {
		$logger = null;
		if (property_exists($this, 'logger') === true) {
			$logger = $this->logger;
		}

		if (($logger instanceof LoggerInterface) === false) {
			return;
		}

		$contextPrefix = '';
		if ($context !== '') {
			$contextPrefix = $context . ': ';
		}

		$logger->warning(
			message: '[' . static::class . '] ' . $contextPrefix . $e->getMessage() . ' (translated to HTTP ' . $status . ')',
			context: [
				'exception' => $e,
				'status' => $status,
			]
		);
	}//end logTranslatedException()

	/**
	 * Log an exception and return a generic 500 JSON response.
	 *
	 * The real message/trace is written to the server log (when a logger is
	 * available on the consuming controller) and never exposed to the client.
	 *
	 * @param Throwable $e The caught exception.
	 * @param string $context Optional short context label for the log line.
	 *
	 * @return JSONResponse A generic internal-server-error response.
	 */
	protected function errorResponse(Throwable $e, string $context = ''): JSONResponse {
		// Log server-side when the consuming controller exposes a logger.
		if (property_exists($this, 'logger') === true && $this->logger instanceof LoggerInterface) {
			if ($context !== '') {
				$contextPrefix = $context . ': ';
			} else {
				$contextPrefix = '';
			}

			$this->logger->error(
				message: '[' . static::class . '] ' . $contextPrefix . $e->getMessage(),
				context: [
					'file' => __FILE__,
					'line' => __LINE__,
					'exception' => $e,
				]
			);
		}

		return new JSONResponse(
			data: ['error' => 'Internal server error'],
			statusCode: Http::STATUS_INTERNAL_SERVER_ERROR
		);
	}//end errorResponse()
}//end trait
