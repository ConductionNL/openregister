<?php

/**
 * Flushes the request's reveals once, after the controller has answered.
 *
 * Ledger row 5.6, design D-2: a detail read reveals a protected field once and
 * a list read reveals it per row, so the rows are collected during rendering
 * and written in one batch at the end. This is that end.
 *
 * WHY A MIDDLEWARE RATHER THAN A DESTRUCTOR. `__destruct()` runs at an hour PHP
 * chooses, after the response in some SAPIs and during shutdown in others, with
 * the database connection possibly already gone and exceptions from it
 * unloggable. `afterController` is a defined point with a live container, which
 * is what a write to an append-only trail needs.
 *
 * 🔴 IT RUNS ON `afterException` TOO. A list read that threw halfway has still
 * SHOWN the rows it rendered before it threw, and those reveals are exactly the
 * ones an auditor would find missing later with no explanation. Recording only
 * on the happy path would make a failed request the way to read a BSN
 * untraceably.
 *
 * It never changes the response and never throws: see
 * {@see \OCA\OpenRegister\Service\Rbac\RevealFlusher} for why a recording
 * problem must not become an availability one.
 *
 * @category Middleware
 * @package  OCA\OpenRegister\Middleware
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/sensitive-field-reveal-audit/specs/row-field-level-security/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Middleware;

use Exception;
use OCA\OpenRegister\Service\Rbac\RevealFlusher;
use OCP\AppFramework\Http\Response;
use OCP\AppFramework\Middleware;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Writes the reveals collected during one request.
 *
 * @spec openspec/changes/sensitive-field-reveal-audit/specs/row-field-level-security/spec.md
 */
class RevealAuditMiddleware extends Middleware {

	/**
	 * Constructor.
	 *
	 * @param RevealFlusher $flusher Writes what the read path collected.
	 * @param LoggerInterface $logger The logger.
	 */
	public function __construct(
		private readonly RevealFlusher $flusher,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Flush after a controller answered.
	 *
	 * @param object $controller The controller.
	 * @param string $methodName The method.
	 * @param Response $response The response.
	 *
	 * @return Response The response, unchanged.
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) The signature is the
	 * Middleware contract; the flush is about the request, not the verb.
	 *
	 * @spec openspec/changes/sensitive-field-reveal-audit/specs/row-field-level-security/spec.md
	 */
	public function afterController($controller, $methodName, Response $response): Response {
		$this->flushQuietly();

		return $response;
	}//end afterController()

	/**
	 * Flush after a controller threw, and then re-throw.
	 *
	 * See the class docblock: a request that failed halfway has still shown
	 * what it rendered before it failed.
	 *
	 * @param object $controller The controller.
	 * @param string $methodName The method.
	 * @param Exception $exception The exception.
	 *
	 * @return Response Never returns; the exception is re-thrown for the next middleware.
	 *
	 * @throws Exception Always, unchanged.
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) The signature is the
	 * Middleware contract; the flush is about the request, not the verb.
	 *
	 * @spec openspec/changes/sensitive-field-reveal-audit/specs/row-field-level-security/spec.md
	 */
	public function afterException($controller, $methodName, Exception $exception): Response {
		$this->flushQuietly();

		// Re-thrown UNCHANGED so the next middleware decides what the response
		// is. Returning one here would make this middleware the error handler
		// for every controller in the app, which is not what it is for.
		throw $exception;
	}//end afterException()

	/**
	 * Flush, swallowing anything it throws.
	 *
	 * @return void
	 */
	private function flushQuietly(): void {
		try {
			$this->flusher->flush();
		} catch (Throwable $e) {
			$this->logger->error(
				message: '[RevealAuditMiddleware] The reveal flush failed; the request is unaffected',
				context: ['file' => __FILE__, 'line' => __LINE__, 'exception' => $e->getMessage()]
			);
		}
	}//end flushQuietly()
}//end class
