<?php

/**
 * The answer to a report generation that failed.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Search\Controller\Support
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/rapportage-bi-export/specs/rapportage-bi-export/spec.md#requirement-the-system-must-support-configurable-report-templates
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Search\Controller\Support;

use InvalidArgumentException;
use OCA\OpenRegister\Exception\NotAuthorizedException;
use OCA\OpenRegister\Exception\OfficeServerRequiredException;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Maps why a generation failed to its HTTP answer; anything unexpected is
 * logged and answered without internal detail.
 */
class ReportGenerationFailures {
	/**
	 * The status per known failure, checked in order.
	 *
	 * @var array<class-string<\Throwable>, int>
	 */
	private const STATUS = [
		InvalidArgumentException::class => Http::STATUS_BAD_REQUEST,
		NotAuthorizedException::class => Http::STATUS_FORBIDDEN,
		DoesNotExistException::class => Http::STATUS_NOT_FOUND,
		RuntimeException::class => Http::STATUS_NOT_FOUND,
	];

	/**
	 * Constructor.
	 *
	 * @param LoggerInterface $logger Logger for unexpected failures.
	 */
	public function __construct(
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The response to a generation that failed.
	 *
	 * @param string     $templateId The template identifier.
	 * @param \Throwable $error      Why it failed.
	 *
	 * @return JSONResponse 400 invalid input, 403 a section not readable, 404 unknown template or source,
	 *                      the office server's status without one for a PDF, else a generic 500.
	 *
	 * @spec openspec/changes/rapportage-bi-export/specs/rapportage-bi-export/spec.md#requirement-the-system-must-support-configurable-report-templates
	 */
	public function response(string $templateId, \Throwable $error): JSONResponse {
		if ($error instanceof OfficeServerRequiredException) {
			return new JSONResponse(data: $error->toResponseData(), statusCode: OfficeServerRequiredException::HTTP_STATUS);
		}

		foreach (self::STATUS as $class => $status) {
			if ($error instanceof $class) {
				return new JSONResponse(data: ['error' => $error->getMessage()], statusCode: $status);
			}
		}

		$this->logger->error(
			message: '[ReportTemplateController] Generation failed',
			context: ['templateId' => $templateId, 'error' => $error->getMessage()]
		);

		return new JSONResponse(
			data: ['error' => 'Generation failed; see server logs for details'],
			statusCode: Http::STATUS_INTERNAL_SERVER_ERROR
		);
	}//end response()
}//end class
