<?php

/**
 * OpenRegister File Text Controller
 *
 * Controller for file text management operations.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Files\Controller;

use OCA\OpenRegister\AppInfo\Application;
use OCA\OpenRegister\Files\Service\File\CallerFileAccess;
use OCA\OpenRegister\Controller\Support\ControllerSession;
use OCA\OpenRegister\Files\Controller\Support\FileTextControllerServices;
use OCA\OpenRegister\Controller\Trait\RequiresJsonBodyTrait;
use OCA\OpenRegister\Files\Db\EntityRelationMapperInterface;
use OCA\OpenRegister\Exception\ManualEntityException;
use OCA\OpenRegister\Exception\PdfAnonymisationException;
use OCA\OpenRegister\Files\Service\File\ManualEntityRequest;
use OCA\OpenRegister\Files\Service\File\ManualEntityResult;
use OCA\OpenRegister\Files\Service\File\ManualEntityService;
use OCA\OpenRegister\Service\File\DocumentAnonymization;
use OCA\OpenRegister\Service\FileServiceInterface;
use OCA\OpenRegister\Files\Service\TextExtractionService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\Files\File;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * FileTextController
 *
 * Controller for file text management operations.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller
 * @author   OpenRegister Team
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @psalm-suppress UnusedClass
 *
 * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
 */
class FileTextController extends Controller {
	use RequiresJsonBodyTrait;

	/**
	 * Text extraction service
	 *
	 * @var TextExtractionService
	 */
	private readonly TextExtractionService $textExtractor;

	/**
	 * File service for file operations
	 *
	 * @var FileServiceInterface
	 */
	private readonly FileServiceInterface $fileService;

	/**
	 * Entity relation mapper
	 *
	 * @var EntityRelationMapperInterface
	 */
	private readonly EntityRelationMapperInterface $entityRelationMapper;

	/**
	 * Logger
	 *
	 * @var LoggerInterface
	 */
	private readonly LoggerInterface $logger;

	/**
	 * Orchestrator for the manual-entity write path
	 *
	 * @var ManualEntityService
	 */
	private readonly ManualEntityService $manualEntityService;

	/**
	 * The session bundle, which answers the admin check.
	 *
	 * @var ControllerSession
	 */
	private readonly ControllerSession $session;

	/**
	 * Session user accessor (for the manual-entity endpoint)
	 *
	 * @var IUserSession
	 */
	private readonly IUserSession $userSession;

	/**
	 * Whether the caller can open a file.
	 *
	 * @var CallerFileAccess
	 */
	private readonly CallerFileAccess $fileAccess;

	/**
	 * Constructor
	 *
	 * @param IRequest $request Request object
	 * @param FileTextControllerServices $services The file text controller services.
	 */
	public function __construct(
		IRequest $request,
		FileTextControllerServices $services,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
		$this->textExtractor = $services->domain->textExtractor;
		$this->fileService = $services->domain->fileService;
		$this->entityRelationMapper = $services->mappers->entityRelationMapper;
		$this->logger = $services->session->logger;
		$this->manualEntityService = $services->domain->manualEntityService;
		$this->userSession = $services->session->userSession;
		$this->session = $services->session;
		$this->fileAccess = new CallerFileAccess(
			fileReadScope: $services->domain->fileReadScope,
			userSession: $services->session->userSession,
			rootFolder: $services->platform->rootFolder
		);
	}//end __construct()

	/**
	 * Whether the current session user can access the given Nextcloud file.
	 *
	 * Resolves the file through the caller's own user folder so Nextcloud's
	 * share/permission ACLs apply. A file the user cannot access resolves to
	 * no node — preventing IDOR where a caller forces (re-)extraction of
	 * arbitrary file IDs they do not own.
	 *
	 * @param int $fileId Nextcloud file ID.
	 *
	 * @return bool True when the file is reachable in the caller's user folder.
	 */
	private function hasFileAccess(int $fileId): bool {
		return $this->fileAccess->mayOpen(fileId: $fileId);
	}//end hasFileAccess()

	/**
	 * Check whether the currently authenticated user is a Nextcloud administrator.
	 *
	 * The bulk extraction endpoint processes pending files across the whole
	 * instance, so it is admin-only.
	 *
	 * @return bool True if a user is signed in and belongs to the admin group.
	 */
	private function isCurrentUserAdmin(): bool {
		return $this->session->isAdmin();
	}//end isCurrentUserAdmin()

	/**
	 * Get extracted text for a file
	 *
	 * @param int $fileId Nextcloud file ID
	 *
	 * @NoAdminRequired
	 *
	 * @NoCSRFRequired
	 *
	 * @return JSONResponse JSON response with file text or error
	 *
	 * @spec openspec/specs/api-test-coverage/spec.md
	 */
	public function getFileText(int $fileId): JSONResponse {
		// IDOR guard: the text of a file is its content, so it is served only
		// to a caller who can open the file. 404 either way, so the answer
		// does not tell a stranger which file ids exist.
		if ($this->hasFileAccess(fileId: $fileId) === false) {
			return new JSONResponse(
				data: ['success' => false, 'message' => 'File not found or access denied', 'file_id' => $fileId],
				statusCode: 404
			);
		}

		try {
			$text = $this->textExtractor->getExtractedText(fileId: $fileId);
			if ($text === null) {
				return new JSONResponse(
					data: [
						'success' => false,
						'message' => 'No text has been extracted from this file yet. Extract it with POST /api/files/{fileId}/extract.',
						'file_id' => $fileId,
					],
					statusCode: 404
				);
			}

			return new JSONResponse(
				data: [
					'success' => true,
					'file_id' => $fileId,
					'text' => $text,
					'length' => strlen($text),
				]
			);
		} catch (\Exception $e) {
			return $this->failed(action: 'Failed to get file text', message: 'Failed to retrieve file text', e: $e, fileId: $fileId);
		}//end try
	}//end getFileText()

	/**
	 * Bulk extract text from multiple files
	 *
	 * @NoAdminRequired
	 *
	 * @return JSONResponse JSON response with bulk extraction result
	 *
	 * @spec openspec/changes/retrofit-2026-05-25-bw2-ctrl-1/tasks.md#task-2
	 */
	public function bulkExtract(): JSONResponse {
		if ($this->isCurrentUserAdmin() === false) {
			return new JSONResponse(['success' => false, 'message' => 'Admin privileges required'], 403);
		}

		try {
			$limit = (int)$this->request->getParam('limit', 100);
			// Floor as well as ceiling: `?limit=0` used to reach the service, which
			// then walked nothing and answered `processed 0, failed 0, total 0` — a
			// success indistinguishable from "the queue is empty". Max 500 at once.
			$limit = max(1, min($limit, 500));
			$result = $this->textExtractor->extractPendingFiles($limit);

			return new JSONResponse(
				data: [
					'success' => true,
					'processed' => $result['processed'],
					'failed' => $result['failed'],
					'total' => $result['total'],
					// True when the walk stopped on MAX_PENDING_WINDOWS rather than
					// on an empty queue. Without it a truncated run is
					// indistinguishable from a finished one in the counters alone.
					'truncated' => ($result['truncated'] ?? false),
				]
			);
		} catch (\Exception $e) {
			return $this->failed(action: 'Failed bulk extraction', message: 'Bulk extraction failed', e: $e);
		}//end try
	}//end bulkExtract()

	/**
	 * Get file text extraction statistics
	 *
	 * @NoAdminRequired
	 *
	 * @NoCSRFRequired
	 *
	 * @return JSONResponse JSON response with extraction stats
	 *
	 * @no-admin-idor-exempt No per-object resource: returns aggregate extraction
	 *   counters (TextExtractionService::getStats); takes no caller-supplied file id.
	 *
	 * @spec openspec/changes/retrofit-2026-05-25-bw2-ctrl-1/tasks.md#task-2
	 */
	public function getStats(): JSONResponse {
		try {
			$stats = $this->textExtractor->getStats();

			return new JSONResponse(
				data: [
					'success' => true,
					'stats' => $stats,
				]
			);
		} catch (\Exception $e) {
			return $this->failed(action: 'Failed to get stats', message: 'Failed to retrieve statistics', e: $e);
		}//end try
	}//end getStats()

	/**
	 * Delete file text by file ID
	 *
	 * @param int $fileId Nextcloud file ID
	 *
	 * @NoAdminRequired
	 *
	 * @return JSONResponse JSON response with deletion result
	 *
	 * @spec openspec/changes/retrofit-2026-05-25-bw2-ctrl-1/tasks.md#task-2
	 */
	public function deleteFileText(int $fileId): JSONResponse {
		// IDOR guard: deletion targets a file by id — only permit it for files
		// the caller can access (guards the stub before it is implemented).
		if ($this->hasFileAccess(fileId: $fileId) === false) {
			return new JSONResponse(
				data: ['success' => false, 'message' => 'File not found or access denied'],
				statusCode: 404
			);
		}

		try {
			// TextExtractionService works with chunks.
			// TODO: Implement chunk deletion for file.
			// For now, return a message indicating this needs implementation.
			return new JSONResponse(
				data: [
					'success' => false,
					'message' => 'Chunk deletion not yet implemented. Use chunk-based endpoints.',
				],
				statusCode: 501
			);
		} catch (\Exception $e) {
			return $this->failed(action: 'Failed to delete file text', message: 'Failed to delete file text', e: $e, fileId: $fileId);
		}//end try
	}//end deleteFileText()

	/**
	 * Anonymize a file by replacing detected entities with placeholders
	 *
	 * Creates a new anonymized copy of the file with all detected PII entities
	 * replaced by placeholders in the format [ENTITY_TYPE: key].
	 * The original file remains unchanged.
	 *
	 * Accepts an optional `preserveStructure` request param (REQ-ORTPR-004):
	 * PDF only, tri-state (absent/empty = auto — preserve iff the input is a
	 * tagged PDF). The response carries a `structurePreservation` block for
	 * PDF inputs (REQ-ORTPR-003) — omitted for DOCX/ODT/text.
	 *
	 * @param int $fileId Nextcloud file ID to anonymize
	 *
	 * @NoAdminRequired
	 *
	 * @return JSONResponse JSON response with anonymization result
	 *
	 * @SuppressWarnings(PHPMD.NPathComplexity) Sequential independent request-param guards
	 *                                          (scope, dossierKey, preserveStructure) and a
	 *                                          response-shape guard — not nested branching.
	 *
	 * @spec openspec/changes/retrofit-2026-05-25-bw2-ctrl-1/tasks.md#task-2
	 * @spec openspec/specs/pdf-anonymisation/spec.md
	 * @spec openspec/specs/tag-preserving-redaction/spec.md
	 */
	public function anonymizeFile(int $fileId): JSONResponse {
		if ($this->hasFileAccess(fileId: $fileId) === false) {
			return new JSONResponse(
				data: ['success' => false, 'message' => 'File not found or access denied'],
				statusCode: Http::STATUS_NOT_FOUND
			);
		}

		try {
			$this->logger->info(
				message: '[FileTextController] Anonymizing file',
				context: ['file' => __FILE__, 'line' => __LINE__, 'file_id' => $fileId]
			);

			$fileNode = $this->fileService->getFileById($fileId);
			$refusal  = $this->anonymizeRefusal(fileNode: $fileNode);
			if ($refusal !== null) {
				return $refusal;
			}

			$entities = $this->anonymizationEntities(fileId: $fileId);
			if ($entities === []) {
				return $this->anonymizeFailure(
					message: 'No entities detected in this file. Run text extraction first.',
					statusCode: Http::STATUS_BAD_REQUEST
				);
			}

			// The anonymizeDocument call marks the relation rows itself, so this controller MUST NOT
			// mark them again (a second UPDATE would overwrite anonymized_value).
			$anonymizedFile = $this->fileService->anonymizeDocument(
				node: $fileNode,
				anonymization: new DocumentAnonymization(
					entities: $entities,
					scope: $this->requestedPlaceholderScope(),
					fileKey: $this->requestedDossierKey(),
					preserveStructure: $this->requestedPreserveStructure()
				)
			);

			$residualEntities = $this->fileService->getLastResidualEntities();
			$isComplete       = (count($residualEntities) === 0);
			$responseData     = ['success' => true, 'complete' => $isComplete, 'message' => $this->anonymizedMessage(isComplete: $isComplete)]
				+ $this->anonymizedFileIds(fileId: $fileId, anonymizedFile: $anonymizedFile)
				+ ['entities_replaced' => count($entities), 'residual_count' => count($residualEntities), 'residual_entities' => $residualEntities];

			return $this->anonymizedFileResponse(responseData: $responseData);
		} catch (PdfAnonymisationException $e) {
			return $this->pdfAnonymisationFailure(exception: $e, fileId: $fileId);
		} catch (\Exception $e) {
			// PII-redacted (ADR-005): only the exception message and the file id are surfaced.
			$this->logger->error(
				message: '[FileTextController] Failed to anonymize file',
				context: ['file' => __FILE__, 'line' => __LINE__, 'file_id' => $fileId, 'error' => $e->getMessage()]
			);

			return $this->anonymizeFailure(
				message: 'Failed to anonymize file: ' . $e->getMessage(),
				statusCode: Http::STATUS_INTERNAL_SERVER_ERROR
			);
		}//end try
	}//end anonymizeFile()

	/**
	 * Log a failed file-text action and answer 500.
	 *
	 * @param string     $action  What failed, as the log names it.
	 * @param string     $message What failed, as the response names it.
	 * @param \Exception $e       The failure.
	 * @param int|null   $fileId  The file, when the action had one.
	 *
	 * @return JSONResponse The 500 response.
	 */
	private function failed(string $action, string $message, \Exception $e, ?int $fileId=null): JSONResponse {
		$context = ['file' => __FILE__, 'line' => __LINE__];
		if ($fileId !== null) {
			$context['file_id'] = $fileId;
		}

		$context['error'] = $e->getMessage();
		$this->logger->error(message: '[FileTextController] '.$action, context: $context);

		return new JSONResponse(
			data: ['success' => false, 'message' => $message.': '.$e->getMessage()],
			statusCode: 500
		);
	}//end failed()

	/**
	 * The response that refuses an anonymisation before it starts, if any.
	 *
	 * @param File|null $fileNode The file to anonymise, null when it was not found.
	 *
	 * @return JSONResponse|null 404 for a missing file, 400 for an anonymised copy, null to proceed.
	 */
	private function anonymizeRefusal(?File $fileNode): ?JSONResponse {
		if ($fileNode === null) {
			return $this->anonymizeFailure(message: 'File not found', statusCode: Http::STATUS_NOT_FOUND);
		}

		if (strpos($fileNode->getName(), '_anonymized') !== false) {
			return $this->anonymizeFailure(message: 'File is already anonymized', statusCode: Http::STATUS_BAD_REQUEST);
		}

		return null;
	}//end anonymizeRefusal()

	/**
	 * A failed anonymisation response.
	 *
	 * @param string $message    The message for the caller.
	 * @param int    $statusCode The HTTP status.
	 *
	 * @return JSONResponse The response.
	 */
	private function anonymizeFailure(string $message, int $statusCode): JSONResponse {
		return new JSONResponse(data: ['success' => false, 'message' => $message], statusCode: $statusCode);
	}//end anonymizeFailure()

	/**
	 * The detected entities of a file, one per distinct value, in the shape anonymizeDocument expects.
	 *
	 * Rows the operator flagged with skip_anonymization=true are excluded by the mapper.
	 *
	 * @param int $fileId The file id.
	 *
	 * @return array<int, array{text: string, entityType: string, key: string}> The entities.
	 */
	private function anonymizationEntities(int $fileId): array {
		$entities        = [];
		$processedValues = [];
		foreach ($this->entityRelationMapper->findEntitiesForAnonymization($fileId) as $entity) {
			$value = $entity['entity_value'];
			if (isset($processedValues[$value]) === true) {
				continue;
			}

			$processedValues[$value] = true;
			$entities[] = [
				'text'       => $value,
				'entityType' => $entity['entity_type'],
				// NOSEMGREP: php.lang.security.weak-crypto.weak-crypto -- The hash only derives a stable identifier, not a security value.
				'key'        => substr(md5($value . $entity['entity_type']), 0, 8),
			];
		}

		if ($entities !== []) {
			$this->logger->debug(
				message: '[FileTextController] Found entities to anonymize',
				context: ['file' => __FILE__, 'line' => __LINE__, 'file_id' => $fileId, 'entity_count' => count($entities)]
			);
		}

		return $entities;
	}//end anonymizationEntities()

	/**
	 * The placeholder-numbering scope from the request: 'dossier', or 'document' for anything else.
	 *
	 * @return string The scope.
	 */
	private function requestedPlaceholderScope(): string {
		if ((string)$this->request->getParam('scope', 'document') === 'dossier') {
			return 'dossier';
		}

		return 'document';
	}//end requestedPlaceholderScope()

	/**
	 * The stable dossier folder key from the request, null when absent or empty.
	 *
	 * @return string|null The key.
	 */
	private function requestedDossierKey(): ?string {
		$fileKeyParam = $this->request->getParam('dossierKey', null);
		if ($fileKeyParam === null || $fileKeyParam === '') {
			return null;
		}

		return (string)$fileKeyParam;
	}//end requestedDossierKey()

	/**
	 * The PDF-only preserveStructure option (REQ-ORTPR-004): null (auto) when absent or empty.
	 *
	 * @return bool|null The option.
	 */
	private function requestedPreserveStructure(): ?bool {
		$preserveOptionParam = $this->request->getParam('preserveStructure', null);
		if ($preserveOptionParam === null || $preserveOptionParam === '') {
			return null;
		}

		return filter_var($preserveOptionParam, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
	}//end requestedPreserveStructure()

	/**
	 * The result message of an anonymisation.
	 *
	 * @param bool $isComplete Whether every entity was removed.
	 *
	 * @return string The message.
	 */
	private function anonymizedMessage(bool $isComplete): string {
		if ($isComplete === true) {
			return 'File anonymized successfully';
		}

		return 'File anonymized, but some entities could not be fully removed — review the output'
			. ' and refine the entities (manual entities, skip unselected occurrences).';
	}//end anonymizedMessage()

	/**
	 * The ids and path of an original file and its anonymised copy.
	 *
	 * @param int  $fileId         The original file id.
	 * @param Node $anonymizedFile The anonymised copy.
	 *
	 * @return array{original_file_id: int, anonymized_file_id: mixed, anonymized_path: string} The ids.
	 */
	private function anonymizedFileIds(int $fileId, Node $anonymizedFile): array {
		return [
			'original_file_id'   => $fileId,
			'anonymized_file_id' => $anonymizedFile->getId(),
			'anonymized_path'    => $anonymizedFile->getPath(),
		];
	}//end anonymizedFileIds()

	/**
	 * Log a finished anonymisation and build its response.
	 *
	 * Best-effort policy: the file is produced even when some entity text could not be removed,
	 * and the residual TEXT is carried only in this authenticated response (the log holds the count).
	 * The structurePreservation block is present for PDF inputs only (REQ-ORTPR-003).
	 *
	 * @param array<string, mixed> $responseData The response body.
	 *
	 * @return JSONResponse The response.
	 */
	private function anonymizedFileResponse(array $responseData): JSONResponse {
		$logSuffix = ' with residual entities';
		if ($responseData['complete'] === true) {
			$logSuffix = ' successfully';
		}

		$context = ['file' => __FILE__, 'line' => __LINE__];
		foreach (['original_file_id', 'anonymized_file_id', 'anonymized_path', 'entities_replaced', 'complete', 'residual_count'] as $key) {
			$context[$key] = $responseData[$key];
		}

		$this->logger->info(
			message: '[FileTextController] File anonymized' . $logSuffix,
			context: $context
		);

		$structureResult = $this->fileService->getLastStructurePreservation();
		if ($structureResult !== null) {
			$responseData['structurePreservation'] = $structureResult->jsonSerialize();
		}

		return new JSONResponse(data: $responseData);
	}//end anonymizedFileResponse()

	/**
	 * Map a PDF anonymisation failure to its response (pdf-anonymisation spec).
	 *
	 * The reasons encrypted_pdf and text_layer_missing are caller-correctable (422); validation_failed,
	 * internal_error and anything else are pipeline failures (500). The exception's diagnostic
	 * surface is PII-free by construction and is the only detail forwarded (ADR-005).
	 *
	 * @param PdfAnonymisationException $exception The failure.
	 * @param int                       $fileId    The file id.
	 *
	 * @return JSONResponse The response.
	 */
	private function pdfAnonymisationFailure(PdfAnonymisationException $exception, int $fileId): JSONResponse {
		$reason     = $exception->getReason();
		$statusCode = Http::STATUS_INTERNAL_SERVER_ERROR;
		if ($reason === PdfAnonymisationException::REASON_ENCRYPTED_PDF
			|| $reason === PdfAnonymisationException::REASON_TEXT_LAYER_MISSING
		) {
			$statusCode = Http::STATUS_UNPROCESSABLE_ENTITY;
		}

		$this->logger->error(
			message: '[FileTextController] PDF anonymisation failed',
			context: [
				'file'       => __FILE__,
				'line'       => __LINE__,
				'file_id'    => $fileId,
				'reason'     => $reason,
				'diagnostic' => $exception->getDiagnostic(),
			]
		);

		return new JSONResponse(
			data: ['success' => false, 'error' => 'pdf_anonymisation_failed', 'reason' => $reason, 'details' => $exception->getDiagnostic()],
			statusCode: $statusCode
		);
	}//end pdfAnonymisationFailure()

	/**
	 * Add an operator-supplied manual entity to a file.
	 *
	 * Implements `manual-entity-anonymisation`: takes an operator-typed
	 * value + type, performs chunk-aware string matching against the
	 * file's extracted text, creates (or reuses) the catalogue entry,
	 * and inserts one `EntityRelation` row per occurrence found.
	 *
	 * Idempotent: re-calling for the same value on the same file does
	 * NOT create duplicate relation rows. Zero-match responses are
	 * non-errors (HTTP 200 with a `message` field).
	 *
	 * Request body:
	 *
	 *     {
	 *         "value":         "Jan Jansen",     // required
	 *         "type":          "PERSON",          // required
	 *         "category":      "name",            // optional
	 *         "wholeWord":     true,              // optional, default true
	 *         "caseSensitive": true               // optional, default true
	 *     }
	 *
	 * @param int $fileId Nextcloud file ID the manual entity applies to.
	 *
	 * @return JSONResponse 201 on matches found, 200 on zero matches, 4xx/5xx on failure.
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/specs/entity-relation-grondslagen/spec.md
	 */
	public function addManualEntity(int $fileId): JSONResponse {
		$refusal = $this->refuseNonJsonBody(route: 'POST /api/files/{fileId}/manual-entities');
		if ($refusal !== null) {
			return $refusal;
		}

		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(data: ['error' => 'unauthenticated'], statusCode: Http::STATUS_UNAUTHORIZED);
		}

		$input = $this->manualEntityInput();
		if ($input['value'] === '' || $input['type'] === '') {
			$field = 'type';
			if ($input['value'] === '') {
				$field = 'value';
			}

			return new JSONResponse(data: ['error' => 'invalid_request', 'field' => $field], statusCode: Http::STATUS_BAD_REQUEST);
		}

		// ADR-005 PII rule: log the request shape WITHOUT the value.
		$this->logger->info(
			message: '[FileTextController] addManualEntity request',
			context: [
				'file'          => __FILE__,
				'line'          => __LINE__,
				'fileId'        => $fileId,
				'type'          => $input['type'],
				'wholeWord'     => $input['wholeWord'],
				'caseSensitive' => $input['caseSensitive'],
				'valueLength'   => strlen($input['value']),
				'actor'         => $user->getUID(),
			]
		);

		try {
			$manualEntity = (new ManualEntityRequest(fileId: $fileId, value: $input['value'], type: $input['type'], actor: $user))
				->withMatching(wholeWord: $input['wholeWord'], caseSensitive: $input['caseSensitive']);
			$result       = $this->manualEntityService->addManualEntity(request: $manualEntity);
		} catch (ManualEntityException $e) {
			return $this->mapManualEntityException(exception: $e, fileId: $fileId);
		} catch (Throwable $e) {
			$this->logger->error(
				message: '[FileTextController] addManualEntity unexpected failure',
				context: ['file' => __FILE__, 'line' => __LINE__, 'fileId' => $fileId, 'error' => $e->getMessage()]
			);
			return new JSONResponse(data: ['error' => 'internal_error'], statusCode: Http::STATUS_INTERNAL_SERVER_ERROR);
		}//end try

		return $this->formatManualEntityResponse(result: $result);
	}//end addManualEntity()


	/**
	 * The manual entity fields of the request body, with their defaults.
	 *
	 * @return array{value: string, type: string, wholeWord: bool, caseSensitive: bool} The fields.
	 */
	private function manualEntityInput(): array {
		$body  = $this->request->getParams();
		$input = ['value' => '', 'type' => '', 'wholeWord' => true, 'caseSensitive' => true];
		foreach (['value', 'type'] as $key) {
			if (isset($body[$key]) === true) {
				$input[$key] = (string)$body[$key];
			}
		}

		foreach (['wholeWord', 'caseSensitive'] as $key) {
			if (isset($body[$key]) === true) {
				$input[$key] = (bool)$body[$key];
			}
		}

		return $input;
	}//end manualEntityInput()

	/**
	 * Translate a `ManualEntityException` to the matching HTTP response.
	 *
	 * Per the spec:
	 *   file_not_extracted      → 422 (operator must run extraction first)
	 *   regex_compile_failure   → 400 (malformed needle)
	 *   unsupported_entity_type → 400
	 *   forbidden               → 403 (service-side write-access check failed)
	 *   internal_error          → 500
	 *
	 * @param ManualEntityException $exception Source exception.
	 * @param int $fileId Target file id (used for logging).
	 *
	 * @return JSONResponse Structured error body with no PII echo.
	 */
	private function mapManualEntityException(ManualEntityException $exception, int $fileId): JSONResponse {
		$reason = $exception->getReason();

		if ($reason === ManualEntityException::REASON_FORBIDDEN) {
			return new JSONResponse(
				data: [
					'error' => 'forbidden',
					'reason' => 'write access to file required',
				],
				statusCode: Http::STATUS_FORBIDDEN
			);
		}

		$statusByReason = [
			ManualEntityException::REASON_FILE_NOT_EXTRACTED => Http::STATUS_UNPROCESSABLE_ENTITY,
			ManualEntityException::REASON_REGEX_COMPILE_FAILURE => Http::STATUS_BAD_REQUEST,
			ManualEntityException::REASON_UNSUPPORTED_ENTITY_TYPE => Http::STATUS_BAD_REQUEST,
			ManualEntityException::REASON_INTERNAL_ERROR => Http::STATUS_INTERNAL_SERVER_ERROR,
		];

		$status = ($statusByReason[$reason] ?? Http::STATUS_INTERNAL_SERVER_ERROR);

		$this->logger->info(
			message: '[FileTextController] addManualEntity translated exception',
			context: [
				'file' => __FILE__,
				'line' => __LINE__,
				'fileId' => $fileId,
				'reason' => $reason,
				'status' => $status,
			]
		);

		return new JSONResponse(
			data: ['error' => $reason],
			statusCode: $status
		);

	}//end mapManualEntityException()

	/**
	 * Format the success response body per the proposal.
	 *
	 * 201 when one or more matches were found; 200 with a `message`
	 * field when zero matches were found (catalogue entry was still
	 * created or reused).
	 *
	 * @param ManualEntityResult $result Service-layer result.
	 *
	 * @return JSONResponse
	 */
	private function formatManualEntityResponse(ManualEntityResult $result): JSONResponse {
		$entityPayload = [
			'id' => (int)$result->entity->getId(),
			'uuid' => $result->entity->getUuid(),
			'value' => $result->entity->getValue(),
			'type' => $result->entity->getType(),
			'reused' => ($result->entityWasNew === false),
		];

		$relationsPayload = [];
		foreach ($result->relations as $relation) {
			$relationsPayload[] = [
				'id' => (int)$relation->getId(),
				'chunkId' => $relation->getChunkId(),
				'positionStart' => $relation->getPositionStart(),
				'positionEnd' => $relation->getPositionEnd(),
				'context' => $relation->getContext(),
			];
		}

		$body = [
			'entity' => $entityPayload,
			'relations' => $relationsPayload,
			'matchCount' => $result->matchCount,
			'matchesSkipped' => $result->matchesSkipped,
		];

		if ($result->matchCount === 0) {
			$body['message'] = 'Text not found in file. Catalogue entry created (or reused) and is available for use on other files.';
			return new JSONResponse(data: $body, statusCode: Http::STATUS_OK);
		}

		return new JSONResponse(data: $body, statusCode: Http::STATUS_CREATED);
	}//end formatManualEntityResponse()
}//end class
