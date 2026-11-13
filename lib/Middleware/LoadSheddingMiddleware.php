<?php

/**
 * Load Shedding Middleware
 *
 * Maps a {@see DependencyUnavailableException} thrown anywhere in a request —
 * because a dependency's circuit breaker is open — onto 503 with a
 * `Retry-After` header and an `application/problem+json` body that names only
 * the dependency's class, never a host or a source's connection details
 * (operate-load-shedding design D-5). Every other exception is rethrown
 * untouched. Registered before ObjectSourceErrorMiddleware so the breaker's
 * refusal is not rewritten into a plain source error.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Middleware
 * @package  OCA\OpenRegister\Middleware
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/operate-load-shedding/specs/load-shedding/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Middleware;

use Exception;
use OCA\OpenRegister\Service\Oas\ProblemDescription;
use OCA\OpenRegister\Service\Oas\ProblemDetailsBuilderInterface;
use OCA\OpenRegister\Service\Oas\ProblemStatement;
use OCA\OpenRegister\Service\Resilience\DependencyUnavailableException;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\Response;
use OCP\AppFramework\Middleware;
use Psr\Log\LoggerInterface;

/**
 * Translates an open breaker's refusal into the 503 contract.
 *
 * @package OCA\OpenRegister\Middleware
 *
 * @spec openspec/changes/operate-load-shedding/specs/load-shedding/spec.md
 */
class LoadSheddingMiddleware extends Middleware {

	/**
	 * Constructor.
	 *
	 * @param ProblemDetailsBuilderInterface $problems The RFC 7807 response shape builder.
	 * @param LoggerInterface                $logger   The app logger.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ProblemDetailsBuilderInterface $problems,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Map a DependencyUnavailableException onto the 503 contract; rethrow anything else.
	 *
	 * @param Controller $controller The controller that was executing.
	 * @param string     $methodName The controller method that was executing.
	 * @param Exception  $exception  The exception that was thrown.
	 *
	 * @return Response The 503 response for an open breaker.
	 *
	 * @throws Exception Rethrows every exception that is not a DependencyUnavailableException.
	 *
	 * @spec openspec/changes/operate-load-shedding/specs/load-shedding/spec.md
	 */
	public function afterException(Controller $controller, string $methodName, Exception $exception): Response {
		if ($exception instanceof DependencyUnavailableException === false) {
			throw $exception;
		}

		return $this->refusal(exception: $exception);
	}//end afterException()

	/**
	 * The 503 refusal: a problem document naming the dependency class, with a Retry-After.
	 *
	 * 🔴 The detail and the `dependency` extension name the class only — never
	 * the key's host or source id — because the refusal can reach an anonymous
	 * caller on a public route.
	 *
	 * @param DependencyUnavailableException $exception The open-breaker refusal.
	 *
	 * @return JSONResponse The 503, as application/problem+json.
	 *
	 * @spec openspec/changes/operate-load-shedding/specs/load-shedding/spec.md
	 */
	private function refusal(DependencyUnavailableException $exception): JSONResponse {
		$dependency = $exception->dependency();
		$retryAfter = $exception->retryAfter();

		$this->logger->info(sprintf('[LoadSheddingMiddleware] refused: %s breaker open, retry after %d seconds', $dependency, $retryAfter));

		$problem = $this->problems->build(
			new ProblemStatement(
				status: Http::STATUS_SERVICE_UNAVAILABLE,
				title: 'Dependency unavailable',
				description: new ProblemDescription(detail: $this->detailFor(dependency: $dependency, retryAfter: $retryAfter)),
				extensions: ['dependency' => $dependency]
			)
		);

		$response = new JSONResponse(data: $problem, statusCode: Http::STATUS_SERVICE_UNAVAILABLE);
		$response->addHeader('Retry-After', (string) $retryAfter);
		$response->addHeader('Content-Type', ProblemDetailsBuilderInterface::CONTENT_TYPE);
		return $response;
	}//end refusal()

	/**
	 * The human sentence for a refused dependency class.
	 *
	 * @param string $dependency The dependency class.
	 * @param int    $retryAfter The seconds to retry after.
	 *
	 * @return string The detail sentence.
	 */
	private function detailFor(string $dependency, int $retryAfter): string {
		$subjects = [
			'database' => 'The register database is under heavy load',
			'llm' => 'The language model provider is not answering',
			'source' => 'The outside database this schema reads from is not answering',
			'outbound' => 'An outside service this request needs is not answering',
			'webhook' => 'The webhook receiver is not answering',
		];

		$subject = ($subjects[$dependency] ?? 'A dependency this request needs is not answering');
		return sprintf('%s. Try again in %d seconds.', $subject, $retryAfter);
	}//end detailFor()
}//end class
