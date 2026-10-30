<?php

/**
 * The HTTP answers of the object folder endpoints.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\File
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/object-folder-in-files-browser/specs/file-actions/spec.md#requirement-changing-an-objects-folder-and-its-subfolders-follows-the-objects-update-rule
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\File;

use InvalidArgumentException;
use OCA\OpenRegister\Exception\ObjectFileAccessDeniedException;
use OCA\OpenRegister\Exception\ObjectFolderConflictException;
use OCA\OpenRegister\Exception\ObjectStateWriteException;
use OCP\AppFramework\Http\JSONResponse;
use OCP\Files\NotFoundException;
use OCP\IL10N;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Turns what ObjectFolderBrowser did, or why it refused, into a response.
 *
 * A person who may not read the object gets 404, the answer an object read
 * gives, so a refusal never confirms the object exists; a reader who may not
 * change it gets 403. A path that could leave the folder or a name that cannot
 * be used is 400, a path or node not in the folder is 404, a taken name or a
 * locked file is 409, and so is a change to the files of a frozen or archived
 * object, which answers with the state, who, when and why. Anything else is a
 * 500 that names nothing internal.
 *
 * @spec openspec/changes/object-folder-in-files-browser/specs/file-actions/spec.md#requirement-changing-an-objects-folder-and-its-subfolders-follows-the-objects-update-rule
 */
class ObjectFolderResponder {

	/**
	 * Constructor.
	 *
	 * @param IL10N           $l10n   Translates the messages.
	 * @param LoggerInterface $logger Logs an unexpected failure.
	 */
	public function __construct(
		private readonly IL10N $l10n,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Answer a failed call.
	 *
	 * @param Throwable $e The failure.
	 *
	 * @return JSONResponse The error response.
	 *
	 * @spec openspec/changes/object-folder-in-files-browser/specs/file-actions/spec.md#requirement-changing-an-objects-folder-and-its-subfolders-follows-the-objects-update-rule
	 */
	public function failure(Throwable $e): JSONResponse {
		if ($e instanceof ObjectFileAccessDeniedException === true) {
			if ($e->getHttpStatus() === ObjectFileAccessDeniedException::NOT_READABLE) {
				return new JSONResponse(data: ['error' => $this->l10n->t('Object not found')], statusCode: 404);
			}

			return new JSONResponse(data: ['error' => $this->l10n->t('You do not have access to this object')], statusCode: 403);
		}

		if ($e instanceof InvalidArgumentException === true) {
			$message = $this->l10n->t('This name cannot be used');
			if ($e->getCode() === ObjectFolderBrowser::BAD_PATH) {
				$message = $this->l10n->t('The path is not a folder of this object');
			}

			return new JSONResponse(data: ['error' => $message], statusCode: 400);
		}

		if ($e instanceof NotFoundException === true) {
			return new JSONResponse(data: ['error' => $this->l10n->t('Not found')], statusCode: 404);
		}

		if ($e instanceof ObjectStateWriteException === true) {
			return new JSONResponse(data: $e->toResponseBody(), statusCode: ObjectStateWriteException::HTTP_STATUS);
		}

		if ($e instanceof ObjectFolderConflictException === true) {
			return new JSONResponse(data: ['error' => $this->conflictMessage(code: (int)$e->getCode())], statusCode: 409);
		}

		// Logged in full here, answered without detail.
		$this->logger->error(
			message: '[ObjectFolder] ' . $e->getMessage(),
			context: ['exception' => $e]
		);

		return new JSONResponse(data: ['error' => $this->l10n->t('Internal server error')], statusCode: 500);
	}//end failure()

	/**
	 * Answer an upload request that carried no file.
	 *
	 * @return JSONResponse 400.
	 *
	 * @spec openspec/changes/object-folder-in-files-browser/specs/file-actions/spec.md#requirement-changing-an-objects-folder-and-its-subfolders-follows-the-objects-update-rule
	 */
	public function noUpload(): JSONResponse {
		return new JSONResponse(data: ['error' => $this->l10n->t('No file was uploaded')], statusCode: 400);
	}//end noUpload()

	/**
	 * Answer an upload batch.
	 *
	 * @param array $outcome What was stored and refused, from ObjectFolderUploads::uploadAll().
	 * @param int   $total   How many files were sent.
	 *
	 * @psalm-param array{stored: list<array<string, mixed>>, rejected: list<array{name: string, error: string, conflict: bool}>} $outcome
	 * @phpstan-param array{stored: list<array<string, mixed>>, rejected: list<array{name: string, error: string, conflict: bool}>} $outcome
	 *
	 * @return JSONResponse 201 when all were stored, 207 when some were, 409 when every file was a taken name, 400 otherwise.
	 *
	 * @spec openspec/changes/object-folder-in-files-browser/specs/file-actions/spec.md#requirement-changing-an-objects-folder-and-its-subfolders-follows-the-objects-update-rule
	 */
	public function uploaded(array $outcome, int $total): JSONResponse {
		$conflicts = 0;
		$rejected = [];
		foreach ($outcome['rejected'] as $refusal) {
			$error = $refusal['error'];
			if ($refusal['conflict'] === true) {
				$conflicts++;
				$error = $this->conflictMessage(code: ObjectFolderBrowser::TAKEN);
			}

			$rejected[] = ['name' => $refusal['name'], 'error' => $error];
		}

		if ($outcome['stored'] === []) {
			$status = 400;
			if ($conflicts === $total) {
				$status = 409;
			}

			return new JSONResponse(
				data: [
					'error' => ($rejected[0]['error'] ?? $this->l10n->t('The upload failed')),
					'stored' => [],
					'rejected' => $rejected,
				],
				statusCode: $status
			);
		}

		$status = 201;
		if ($rejected !== []) {
			$status = 207;
		}

		return new JSONResponse(data: ['stored' => $outcome['stored'], 'rejected' => $rejected], statusCode: $status);
	}//end uploaded()

	/**
	 * The message for a conflict.
	 *
	 * @param int $code ObjectFolderBrowser::TAKEN or ::LOCKED.
	 *
	 * @return string The translated message.
	 */
	private function conflictMessage(int $code): string {
		if ($code === ObjectFolderBrowser::LOCKED) {
			return $this->l10n->t('A file in this folder is locked by someone else');
		}

		return $this->l10n->t('A file or folder with that name is already here');
	}//end conflictMessage()
}//end class
