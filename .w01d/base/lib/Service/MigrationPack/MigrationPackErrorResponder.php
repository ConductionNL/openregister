<?php

/**
 * Builds every error response of the migration pack API.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\MigrationPack
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/migration-pack-editor-ui/specs/migration-mapping-packs/spec.md#requirement-pack-changes-must-be-limited-to-administrators-and-logged
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\MigrationPack;

use OCA\OpenRegister\Exception\BuiltinPackSlugLockedException;
use OCA\OpenRegister\Exception\MigrationPackRefusedException;
use OCA\OpenRegister\Exception\MigrationPackRequestException;
use OCA\OpenRegister\Exception\PackChangedException;
use OCA\OpenRegister\Exception\PackSlugExistsException;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * One place for the shape of a migration pack error (ADR-050).
 *
 * Every error is `{"message", "error", "details"?}`: `message` is static text
 * translated through IL10N, `error` a kebab-case code the client dispatches
 * on, and `details.errors` the validator's reasons for an invalid pack. No
 * exception message reaches a response; an unexpected failure is logged with
 * its exception and answered with a static message.
 *
 * @spec openspec/changes/migration-pack-editor-ui/specs/migration-mapping-packs/spec.md#requirement-pack-changes-must-be-limited-to-administrators-and-logged
 */
class MigrationPackErrorResponder {
	/**
	 * Constructor.
	 *
	 * @param IL10N           $l10n   Translates the messages.
	 * @param LoggerInterface $logger Records unexpected failures.
	 *
	 * @spec openspec/changes/migration-pack-editor-ui/specs/migration-mapping-packs/spec.md#requirement-pack-changes-must-be-limited-to-administrators-and-logged
	 */
	public function __construct(
		private readonly IL10N $l10n,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * 401: nobody is signed in.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/migration-pack-editor-ui/specs/migration-mapping-packs/spec.md#requirement-pack-changes-must-be-limited-to-administrators-and-logged
	 */
	public function notAuthenticated(): JSONResponse {
		return $this->error(
			status: 401,
			error: 'not-authenticated',
			message: $this->l10n->t('Authentication required')
		);
	}//end notAuthenticated()

	/**
	 * 403: the caller is signed in but not a Nextcloud administrator.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/migration-pack-editor-ui/specs/migration-mapping-packs/spec.md#requirement-pack-changes-must-be-limited-to-administrators-and-logged
	 */
	public function adminRequired(): JSONResponse {
		return $this->error(
			status: 403,
			error: 'admin-required',
			message: $this->l10n->t('Administrator privileges required')
		);
	}//end adminRequired()

	/**
	 * 404: no pack has the requested row id.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/specs/migration-mapping-packs/spec.md#migration-packs-must-be-manageable-via-admin-gated-rest-crud-with-json-file-importexport
	 */
	public function notFound(): JSONResponse {
		return $this->error(
			status: 404,
			error: 'pack-not-found',
			message: $this->l10n->t('Migration pack not found')
		);
	}//end notFound()

	/**
	 * Answer a refusal the caller caused: 400 for a malformed request, 409
	 * for an update based on an outdated read, 422 for a refused definition.
	 *
	 * @param MigrationPackRefusedException $exception The refusal.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/migration-pack-editor-ui/specs/migration-mapping-packs/spec.md#requirement-pack-changes-must-be-limited-to-administrators-and-logged
	 */
	public function refused(MigrationPackRefusedException $exception): JSONResponse {
		if ($exception instanceof MigrationPackRequestException) {
			return $this->badRequest(exception: $exception);
		}

		if ($exception instanceof PackChangedException) {
			return $this->error(
				status: 409,
				error: 'pack-changed',
				message: $this->l10n->t('The migration pack was saved by someone else after it was read')
			);
		}

		return $this->refusedDefinition(exception: $exception);
	}//end refused()

	/**
	 * 400: the request is malformed before any pack is read.
	 *
	 * @param MigrationPackRequestException $exception The refusal, with its code and translated reason.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/migration-pack-editor-ui/specs/migration-mapping-packs/spec.md#requirement-a-pack-must-be-testable-against-sample-rows-without-saving-it
	 */
	private function badRequest(MigrationPackRequestException $exception): JSONResponse {
		return $this->error(
			status: 400,
			error: $exception->getErrorCode(),
			message: $exception->getUserMessage()
		);
	}//end badRequest()

	/**
	 * 422: the pack definition is refused.
	 *
	 * A renamed built-in pack answers `builtin-slug-locked`, and a slug another
	 * pack already has answers `pack-slug-exists`; neither carries details, so
	 * a client shows its own text for the code. Any other refusal answers
	 * `invalid-pack` with every reason in `details.errors`.
	 *
	 * @param MigrationPackRefusedException $exception The refusal, with its reasons.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/specs/migration-mapping-packs/spec.md#the-system-must-validate-migration-pack-definitions-structurally-before-storing-them
	 */
	private function refusedDefinition(MigrationPackRefusedException $exception): JSONResponse {
		if ($exception instanceof BuiltinPackSlugLockedException) {
			return $this->error(
				status: 422,
				error: 'builtin-slug-locked',
				message: $this->l10n->t('The id of a built-in migration pack cannot be changed')
			);
		}

		if ($exception instanceof PackSlugExistsException) {
			return $this->error(
				status: 422,
				error: 'pack-slug-exists',
				message: $this->l10n->t('A migration pack with this id already exists')
			);
		}

		return new JSONResponse(
			data: [
				'message' => $this->l10n->t('The pack definition is invalid'),
				'error' => 'invalid-pack',
				'details' => ['errors' => $exception->getErrors()],
			],
			statusCode: 422
		);
	}//end refusedDefinition()

	/**
	 * 500: log an unexpected failure and answer with a static message.
	 *
	 * @param 'import'|'create'|'update'|'delete'|'preview' $action    What the caller tried to do.
	 * @param Throwable                                     $exception The failure, logged in full.
	 * @param array<string, mixed>                          $context   Extra log context, such as the row id.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/migration-pack-editor-ui/specs/migration-mapping-packs/spec.md#requirement-pack-changes-must-be-limited-to-administrators-and-logged
	 */
	public function unexpected(string $action, Throwable $exception, array $context = []): JSONResponse {
		$this->logger->error(
			message: '[MigrationPacksController] Error during migration pack ' . $action . ': ' . $exception->getMessage(),
			context: array_merge($context, ['exception' => $exception])
		);

		$message = match ($action) {
			'import' => $this->l10n->t('Failed to import migration pack'),
			'create' => $this->l10n->t('Failed to create migration pack'),
			'update' => $this->l10n->t('Failed to update migration pack'),
			'delete' => $this->l10n->t('Failed to delete migration pack'),
			'preview' => $this->l10n->t('Failed to preview migration pack'),
		};

		return $this->error(status: 500, error: 'migration-pack-error', message: $message);
	}//end unexpected()

	/**
	 * Build one `{message, error}` response.
	 *
	 * @param int    $status  The HTTP status.
	 * @param string $error   The kebab-case error code.
	 * @param string $message The translated message.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/migration-pack-editor-ui/specs/migration-mapping-packs/spec.md#requirement-pack-changes-must-be-limited-to-administrators-and-logged
	 */
	private function error(int $status, string $error, string $message): JSONResponse {
		return new JSONResponse(data: ['message' => $message, 'error' => $error], statusCode: $status);
	}//end error()
}//end class
