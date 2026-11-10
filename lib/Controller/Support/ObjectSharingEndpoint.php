<?php

/**
 * OpenRegister ObjectSharingEndpoint
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller\Support
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Controller\Support;

use Closure;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Exception\NotAuthorizedException;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCA\OpenRegister\Kernel\Contracts\ObjectRef;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use Psr\Log\LoggerInterface;

/**
 * The shared frame of the sharing, ownership and per-user object endpoints.
 *
 * Every such endpoint first resolves its object through the RBAC boundary: an
 * object the caller cannot READ is refused with 404 rather than 403, so the
 * endpoints cannot be used to probe which object ids exist, and a caller
 * without read never reaches the operation behind them.
 *
 * The operation then runs here. A refusal by the service answers 403 with its
 * message, a rejected input 400 with its message (for the operations that
 * validate input), and anything else is logged and answered with a generic 500
 * whose message is never the exception's: it can carry storage paths and share
 * internals.
 *
 * @spec openspec/changes/object-level-sharing-and-private-scope/specs/object-level-sharing/spec.md
 */
final class ObjectSharingEndpoint {
	/**
	 * Hold the resolver and the failure reporting of one controller.
	 *
	 * @param ObjectOperationsInterface $objectService Resolves an object through the RBAC boundary.
	 * @param LoggerInterface           $logger        Records unexpected failures.
	 * @param string                    $file          The controller's file: names the log lines and their context.
	 * @param string                    $failure       The generic message of the 500.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/object-level-sharing-and-private-scope/specs/object-level-sharing/spec.md
	 */
	public function __construct(
		private readonly ObjectOperationsInterface $objectService,
		private readonly LoggerInterface $logger,
		private readonly string $file,
		private readonly string $failure,
	) {
	}//end __construct()

	/**
	 * Resolve the target object, or the 404 to answer.
	 *
	 * @param ObjectRef $ref The register, schema and object uuid the route names.
	 *
	 * @return ObjectEntity|JSONResponse The object, or the response to return.
	 *
	 * @spec openspec/changes/object-level-sharing-and-private-scope/specs/object-level-sharing/spec.md
	 */
	public function resolve(ObjectRef $ref): ObjectEntity|JSONResponse {
		// Aimed outside the try: an unknown register or schema is not an
		// unreadable object, and is left to propagate as it always has.
		$this->objectService->setRegister((string)$ref->register);
		$this->objectService->setSchema((string)$ref->schema);
		$this->objectService->setObject($ref->id);

		return ($this->current() ?? self::notFound());
	}//end resolve()

	/**
	 * The object the service is aimed at, or null when the caller cannot read it or it is missing.
	 *
	 * @return ObjectEntity|null The readable object.
	 */
	private function current(): ?ObjectEntity {
		try {
			$object = $this->objectService->getObject();
		} catch (\Throwable $e) {
			return null;
		}

		if (($object instanceof ObjectEntity) === false) {
			return null;
		}

		return $object;
	}//end current()

	/**
	 * The one answer for an object the caller cannot read.
	 *
	 * @return JSONResponse A 404.
	 *
	 * @spec openspec/changes/object-level-sharing-and-private-scope/specs/object-level-sharing/spec.md
	 */
	public static function notFound(): JSONResponse {
		return new JSONResponse(['message' => 'Object not found'], Http::STATUS_NOT_FOUND);
	}//end notFound()

	/**
	 * Run the operation and answer its result as JSON (200), or the refusal; a rejected input is a 500.
	 *
	 * @param Closure $operation Returns the response body.
	 * @param string  $context   Short label for the log line.
	 *
	 * @return JSONResponse The answer.
	 *
	 * @spec openspec/changes/object-level-sharing-and-private-scope/specs/object-level-sharing/spec.md
	 */
	public function answer(Closure $operation, string $context): JSONResponse {
		return self::respond(result: $this->run(operation: $operation, context: $context), status: Http::STATUS_OK);
	}//end answer()

	/**
	 * Run a validating operation and answer its result as JSON (200); a rejected input answers 400.
	 *
	 * @param Closure $operation Returns the response body.
	 * @param string  $context   Short label for the log line.
	 *
	 * @return JSONResponse The answer.
	 *
	 * @spec openspec/changes/object-level-sharing-and-private-scope/specs/object-level-sharing/spec.md
	 */
	public function answerValidated(Closure $operation, string $context): JSONResponse {
		return self::respond(result: $this->runValidated(operation: $operation, context: $context), status: Http::STATUS_OK);
	}//end answerValidated()

	/**
	 * Run a validating operation that creates something and answer its result with 201; a rejected input answers 400.
	 *
	 * @param Closure $operation Returns the response body.
	 * @param string  $context   Short label for the log line.
	 *
	 * @return JSONResponse The answer.
	 *
	 * @spec openspec/changes/object-level-sharing-and-private-scope/specs/object-level-sharing/spec.md
	 */
	public function answerCreated(Closure $operation, string $context): JSONResponse {
		return self::respond(result: $this->runValidated(operation: $operation, context: $context), status: Http::STATUS_CREATED);
	}//end answerCreated()

	/**
	 * Run a validating operation that removes something and answer with 204; a rejected input answers 400.
	 *
	 * @param Closure $operation Returns the response body.
	 * @param string  $context   Short label for the log line.
	 *
	 * @return JSONResponse The answer.
	 *
	 * @spec openspec/changes/object-level-sharing-and-private-scope/specs/object-level-sharing/spec.md
	 */
	public function answerRemoved(Closure $operation, string $context): JSONResponse {
		return self::respond(result: $this->runValidated(operation: $operation, context: $context), status: Http::STATUS_NO_CONTENT);
	}//end answerRemoved()

	/**
	 * A result as a JSON answer with the given status, or the refusal it already is.
	 *
	 * @param mixed $result The operation's result, or the JSONResponse to return.
	 * @param int   $status The status of a successful answer.
	 *
	 * @return JSONResponse The answer.
	 */
	private static function respond(mixed $result, int $status): JSONResponse {
		if ($result instanceof JSONResponse) {
			return $result;
		}

		return new JSONResponse($result, $status);
	}//end respond()

	/**
	 * Run the operation and return its result, or the refusal as a response; a rejected input is a 500.
	 *
	 * @param Closure $operation The operation; it never returns a JSONResponse itself.
	 * @param string  $context   Short label for the log line.
	 *
	 * @return mixed The operation's result, or the JSONResponse to return.
	 *
	 * @spec openspec/changes/object-level-sharing-and-private-scope/specs/object-level-sharing/spec.md
	 */
	public function run(Closure $operation, string $context): mixed {
		try {
			return $operation();
		} catch (\Throwable $e) {
			return ($this->refusal(exception: $e, validates: false) ?? $this->unexpected(exception: $e, context: $context));
		}
	}//end run()

	/**
	 * Run a validating operation and return its result, or the refusal as a response; a rejected input answers 400.
	 *
	 * @param Closure $operation The operation; it never returns a JSONResponse itself.
	 * @param string  $context   Short label for the log line.
	 *
	 * @return mixed The operation's result, or the JSONResponse to return.
	 *
	 * @spec openspec/changes/object-level-sharing-and-private-scope/specs/object-level-sharing/spec.md
	 */
	public function runValidated(Closure $operation, string $context): mixed {
		try {
			return $operation();
		} catch (\Throwable $e) {
			return ($this->refusal(exception: $e, validates: true) ?? $this->unexpected(exception: $e, context: $context));
		}
	}//end runValidated()

	/**
	 * The answer for a failure the caller caused: a refusal, or a rejected input of a validating operation.
	 *
	 * @param \Throwable $exception The failure.
	 * @param bool       $validates Whether a rejected input answers 400 rather than 500.
	 *
	 * @return JSONResponse|null The 403 or 400, or null when the failure is unexpected.
	 */
	private function refusal(\Throwable $exception, bool $validates): ?JSONResponse {
		if ($exception instanceof NotAuthorizedException) {
			return new JSONResponse(['message' => $exception->getMessage()], Http::STATUS_FORBIDDEN);
		}

		if ($validates === true && $exception instanceof \InvalidArgumentException) {
			return new JSONResponse(['message' => $exception->getMessage()], Http::STATUS_BAD_REQUEST);
		}

		return null;
	}//end refusal()

	/**
	 * Log an unexpected failure and return a generic error.
	 *
	 * @param \Throwable $exception The failure.
	 * @param string     $context   Short label for the log line.
	 *
	 * @return JSONResponse A generic 500.
	 *
	 * @spec openspec/changes/object-level-sharing-and-private-scope/specs/object-level-sharing/spec.md
	 */
	private function unexpected(\Throwable $exception, string $context): JSONResponse {
		$this->logger->error(
			message: '['.basename(path: $this->file, suffix: '.php').'] '.$context.': '.$exception->getMessage(),
			context: ['file' => $this->file, 'line' => __LINE__, 'exception' => $exception]
		);

		return new JSONResponse(['message' => $this->failure], Http::STATUS_INTERNAL_SERVER_ERROR);
	}//end unexpected()
}//end class
