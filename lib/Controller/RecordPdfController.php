<?php

/**
 * OpenRegister RecordPdfController
 *
 * Exports one record as a PDF.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/export-pdf-house-style/specs/export-pdf-format/spec.md#requirement-a-single-record-exports-as-a-pdf
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Controller;

use OCA\OpenRegister\Exception\OfficeServerRequiredException;
use OCA\OpenRegister\Exception\RegisterNotFoundException;
use OCA\OpenRegister\Exception\SchemaNotInRegisterException;
use OCA\OpenRegister\Kernel\Contracts\AppIdentity;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindOptions;
use OCA\OpenRegister\Service\Export\ExportGateInterface;
use OCA\OpenRegister\Service\ExportService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\Response;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * GET /api/objects/{register}/{schema}/{id}/pdf: the record's readable fields as a PDF.
 *
 * In order: a signed-in user (401); the register and the schema within it
 * (404); the export right on the schema, the same verb a list export needs
 * (its refusal); the record, read with the user's rights (404); then the PDF,
 * which leaves out every field the user may not read.
 *
 * @spec openspec/changes/export-pdf-house-style/specs/export-pdf-format/spec.md#requirement-a-single-record-exports-as-a-pdf
 */
class RecordPdfController extends Controller {

	/**
	 * What the export audit trail calls this export.
	 *
	 * @var string
	 */
	public const PROFILE = 'record-pdf';

	/**
	 * Constructor.
	 *
	 * @param IRequest                  $request        The request.
	 * @param IUserSession              $userSession    The signed-in user.
	 * @param ObjectOperationsInterface $objectService  Reads the record with the user's rights.
	 * @param ExportService             $exportService  Renders the PDF.
	 * @param ExportGateInterface       $exportGate     Checks the export right.
	 * @param LoggerInterface           $logger         Logs a failed export.
	 */
	public function __construct(
		IRequest $request,
		private readonly IUserSession $userSession,
		private readonly ObjectOperationsInterface $objectService,
		private readonly ExportService $exportService,
		private readonly ExportGateInterface $exportGate,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: AppIdentity::APP_ID, request: $request);
	}//end __construct()

	/**
	 * The record as a PDF download.
	 *
	 * @param string $register The register id or slug.
	 * @param string $schema   The schema id or slug.
	 * @param string $id       The record's id.
	 *
	 * @return Response The PDF, or a refusal.
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @spec openspec/changes/export-pdf-house-style/specs/export-pdf-format/spec.md#requirement-a-single-record-exports-as-a-pdf
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function show(string $register, string $schema, string $id): Response {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(data: ['error' => 'Not authenticated'], statusCode: Http::STATUS_UNAUTHORIZED);
		}

		try {
			// The schema is resolved inside the register, never instance-wide: the
			// same register-scoped resolution the list export uses.
			$this->objectService->setRegister(register: $register);
			$this->objectService->setSchema(schema: $schema);
			$pair = [
				'register' => $this->objectService->getCurrentRegisterEntity(),
				'schema'   => $this->objectService->getCurrentSchemaEntity(),
			];
			if ($pair['register'] === null || $pair['schema'] === null) {
				return self::notFound();
			}

			$refusal = $this->exportGate->refusalFor(schema: $pair['schema'], profile: self::PROFILE, registerId: $pair['register']->getId());
			if ($refusal !== null) {
				return $refusal;
			}

			$object = $this->objectService->find(
				id: $id,
				options: new ObjectFindOptions(register: $pair['register']->getId(), schema: $pair['schema']->getId())
			);
			if ($object === null) {
				return self::notFound();
			}

			$pdf = $this->exportService->recordToPdf(object: $object, schema: $pair['schema'], currentUser: $user);
		} catch (RegisterNotFoundException | SchemaNotInRegisterException | DoesNotExistException $e) {
			return self::notFound();
		} catch (OfficeServerRequiredException $e) {
			return new JSONResponse(data: $e->toResponseData(), statusCode: OfficeServerRequiredException::HTTP_STATUS);
		} catch (Throwable $e) {
			$this->logger->error(message: '[RecordPdfController] Record PDF export failed', context: ['exception' => $e]);
			return new JSONResponse(data: ['error' => 'The record could not be exported.'], statusCode: Http::STATUS_INTERNAL_SERVER_ERROR);
		}//end try

		return new DataDownloadResponse($pdf, self::filename(name: $object->getName(), id: $id), 'application/pdf');
	}//end show()

	/**
	 * The 404 for a register, schema or record the user cannot reach.
	 *
	 * @return JSONResponse
	 */
	private static function notFound(): JSONResponse {
		return new JSONResponse(data: ['error' => 'Record not found'], statusCode: Http::STATUS_NOT_FOUND);
	}//end notFound()

	/**
	 * The download's file name: the record's name, else its id, as safe characters.
	 *
	 * @param string|null $name The record's name.
	 * @param string      $id   The record's id.
	 *
	 * @return string The file name, ending in .pdf.
	 */
	private static function filename(?string $name, string $id): string {
		$base = trim((string) preg_replace('/[^A-Za-z0-9._-]+/', '-', (string) ($name ?? '')), '-.');
		if ($base === '') {
			$base = (string) preg_replace('/[^A-Za-z0-9._-]+/', '-', $id);
		}

		return substr($base, 0, 120) . '.pdf';
	}//end filename()
}//end class
