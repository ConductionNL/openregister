<?php

/**
 * OpenRegister Record Import Controller
 *
 * The record-import surface: propose a column mapping for an uploaded record
 * file, then import it with the confirmed mapping through the streaming save.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
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
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/widget-record-import/specs/widget-record-import/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Controller;

use InvalidArgumentException;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Service\Import\RecordImport\ColumnMapper;
use OCA\OpenRegister\Service\Import\RecordImport\FailedRowExport;
use OCA\OpenRegister\Service\Import\RecordImport\ImportProgress;
use OCA\OpenRegister\Service\Import\RecordImport\RecordFileReader;
use OCA\OpenRegister\Service\Import\RecordImport\RecordImportRequest;
use OCA\OpenRegister\Service\Import\RecordImport\RecordImportService;
use OCA\OpenRegister\Service\Import\RecordImport\UnconfirmedMappingException;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Administrator-only: there is no `#[NoAdminRequired]` on any action.
 *
 * Each imported row goes straight to the save handler through the streaming
 * save, past the per-row checks of the default bulk path, so this surface is
 * restricted the way `BulkController` restricts `stream`. The controller only
 * moves the uploaded file and the user's decisions into the import services; it
 * parses nothing itself.
 *
 * @spec openspec/changes/widget-record-import/specs/widget-record-import/spec.md
 */
class RecordImportController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param string                  $appName     The app id.
	 * @param IRequest                $request     The request.
	 * @param RegisterMapperInterface $registers   Finds the target register.
	 * @param SchemaMapperInterface   $schemas     Finds the target schema.
	 * @param RecordFileReader        $reader      Reads the file's columns.
	 * @param ColumnMapper            $mapper      Proposes and confirms the column mapping.
	 * @param RecordImportService     $importer    Writes the rows.
	 * @param FailedRowExport         $failures    Exports the failed rows.
	 * @param ImportProgress          $progress    Where a running import reports the rows it has read.
	 * @param IUserSession            $userSession The signed-in administrator, who owns the progress entry.
	 *
	 * @spec openspec/changes/widget-record-import/specs/widget-record-import/spec.md
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly RegisterMapperInterface $registers,
		private readonly SchemaMapperInterface $schemas,
		private readonly RecordFileReader $reader,
		private readonly ColumnMapper $mapper,
		private readonly RecordImportService $importer,
		private readonly FailedRowExport $failures,
		private readonly ImportProgress $progress,
		private readonly IUserSession $userSession,
	) {
		parent::__construct(appName: $appName, request: $request);
	}//end __construct()

	/**
	 * POST /api/record-import/{register}/{schema}/mapping — the proposed mapping of an uploaded file.
	 *
	 * Nothing is written. Unmatched columns are listed so the user maps or
	 * ignores each before importing.
	 *
	 * @param string $register The register id, uuid or slug.
	 * @param string $schema   The schema id, uuid or slug.
	 *
	 * @return JSONResponse 200 with format, columns, properties, mapped and unmatched; 400 or 404 otherwise.
	 *
	 * @spec openspec/changes/widget-record-import/specs/widget-record-import/spec.md
	 */
	public function mapping(string $register, string $schema): JSONResponse {
		$target = $this->target(register: $register, schema: $schema);
		if ($target instanceof JSONResponse) {
			return $target;
		}

		$file = $this->uploadedFile();
		if ($file instanceof JSONResponse) {
			return $file;
		}

		$columns    = $this->reader->columns(path: $file['path'], format: $file['format']);
		$properties = self::propertyNames(schema: $target['schema']);
		$proposal   = $this->mapper->propose(columns: $columns, properties: $properties);

		return new JSONResponse(
			data: [
				'format'     => $file['format'],
				'columns'    => $columns,
				'properties' => $properties,
				'mapped'     => $proposal['mapped'],
				'unmatched'  => $proposal['unmatched'],
			]
		);
	}//end mapping()

	/**
	 * POST /api/record-import/{register}/{schema} — import an uploaded file with the confirmed mapping.
	 *
	 * Request: the file as `file`, `decisions` (column => property, or null to
	 * ignore the column; an object or its JSON) and an optional `identifier`.
	 * The result is the import status; when rows failed it also carries them as
	 * CSV in the file's columns plus a reason column (`failedRows`).
	 *
	 * @param string $register The register id, uuid or slug.
	 * @param string $schema   The schema id, uuid or slug.
	 *
	 * @return JSONResponse 200 with the status; 422 while a column is undecided; 400 or 404 otherwise.
	 *
	 * @spec openspec/changes/widget-record-import/specs/widget-record-import/spec.md
	 */
	public function import(string $register, string $schema): JSONResponse {
		return $this->run(register: $register, schema: $schema, dryRun: false);
	}//end import()

	/**
	 * POST /api/record-import/{register}/{schema}/dry-run — what the import would do, writing nothing.
	 *
	 * The same request and the same report as the import, with `dryRun: true`
	 * and the counts meaning "would be". The rows go through the same save
	 * checks as a real import; the rows that would fail come back as CSV
	 * (`failedRows`) to correct before importing.
	 *
	 * @param string $register The register id, uuid or slug.
	 * @param string $schema   The schema id, uuid or slug.
	 *
	 * @return JSONResponse 200 with the report; 422 while a column is undecided; 400 or 404 otherwise.
	 *
	 * @spec openspec/changes/widget-record-import/specs/widget-record-import/spec.md
	 */
	public function dryRun(string $register, string $schema): JSONResponse {
		return $this->run(register: $register, schema: $schema, dryRun: true);
	}//end dryRun()

	/**
	 * GET /api/record-import/progress/{token} — how many rows the caller's running import has read.
	 *
	 * The import and dry-run requests take an optional `progress` token; while
	 * one runs, this answers the rows read so far and whether the file has been
	 * read to the end. Only the administrator who started the import can read it.
	 *
	 * @param string $token The token the import was started with.
	 *
	 * @return JSONResponse 200 with `rows` and `done`; 404 while nothing has been reported; 400 for an invalid token.
	 *
	 * @spec openspec/changes/widget-record-import/tasks.md#5.2
	 */
	public function progress(string $token): JSONResponse {
		$key = ImportProgress::key(userId: $this->userId(), token: $token);
		if ($key === null) {
			return self::error(message: 'The progress token must be 8 to 64 letters, digits, dashes or underscores.', status: Http::STATUS_BAD_REQUEST);
		}

		$progress = $this->progress->read(key: $key);
		if ($progress === null) {
			return self::error(message: 'No progress has been reported for this token.', status: Http::STATUS_NOT_FOUND);
		}

		return new JSONResponse(data: $progress);
	}//end progress()

	/**
	 * Import, or dry-run, an uploaded file with the confirmed mapping.
	 *
	 * @param string $register The register id, uuid or slug.
	 * @param string $schema   The schema id, uuid or slug.
	 * @param bool   $dryRun   Whether nothing is written.
	 *
	 * @return JSONResponse The report, or the error.
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) The two actions name the mode.
	 *
	 * @spec openspec/changes/widget-record-import/specs/widget-record-import/spec.md
	 */
	private function run(string $register, string $schema, bool $dryRun): JSONResponse {
		$target = $this->target(register: $register, schema: $schema);
		if ($target instanceof JSONResponse) {
			return $target;
		}

		$file = $this->uploadedFile();
		if ($file instanceof JSONResponse) {
			return $file;
		}

		$decisions = $this->decisions();
		if ($decisions === null) {
			return self::error(message: '"decisions" must be an object of column => property (or null to ignore).', status: Http::STATUS_BAD_REQUEST);
		}

		$columns = $this->reader->columns(path: $file['path'], format: $file['format']);
		try {
			$mapping = $this->mapper->confirm(
				columns: $columns,
				properties: self::propertyNames(schema: $target['schema']),
				decisions: $decisions,
				identifier: $this->identifier()
			);
		} catch (UnconfirmedMappingException $undecided) {
			return new JSONResponse(
				data: ['error' => $undecided->getMessage(), 'columns' => $undecided->columns],
				statusCode: Http::STATUS_UNPROCESSABLE_ENTITY
			);
		}

		$request = new RecordImportRequest(
			path: $file['path'],
			format: $file['format'],
			register: $target['register'],
			schema: $target['schema'],
			mapping: $mapping,
			progressKey: $this->progressKey()
		);
		$result = $this->report(request: $request, dryRun: $dryRun);

		if ($result['failedCount'] > 0) {
			$export = $this->failures->export(path: $file['path'], format: $file['format'], failures: $result['failures']);
			$result['failedRows'] = $export['csv'];
			$result['failedRowsUnplaced'] = $export['unplaced'];
		}

		return new JSONResponse(data: $result);
	}//end run()

	/**
	 * The import's report: a dry run's or a real one's.
	 *
	 * @param RecordImportRequest $request The import.
	 * @param bool                $dryRun  Whether nothing is written.
	 *
	 * @return array<string, mixed>
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) The two actions name the mode.
	 */
	private function report(RecordImportRequest $request, bool $dryRun): array {
		if ($dryRun === true) {
			return $this->importer->dryRun(request: $request);
		}

		return $this->importer->import(request: $request);
	}//end report()

	/**
	 * The register and schema, or a 404.
	 *
	 * @param string $register The register id, uuid or slug.
	 * @param string $schema   The schema id, uuid or slug.
	 *
	 * @return array{register: Register, schema: Schema}|JSONResponse
	 */
	private function target(string $register, string $schema): array|JSONResponse {
		try {
			return [
				'register' => $this->registers->find(id: $register),
				'schema'   => $this->schemas->find(id: $schema),
			];
		} catch (DoesNotExistException $missing) {
			unset($missing);
			return self::error(message: 'Register or schema not found.', status: Http::STATUS_NOT_FOUND);
		}
	}//end target()

	/**
	 * The uploaded record file's temporary path and format, or a 400.
	 *
	 * @return array{path: string, format: string}|JSONResponse
	 */
	private function uploadedFile(): array|JSONResponse {
		$file = $this->request->getUploadedFile(key: 'file');
		if (is_array($file) === false
			|| ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK
			|| is_string($file['tmp_name'] ?? null) === false
			|| $file['tmp_name'] === ''
		) {
			return self::error(message: 'Upload the record file as "file".', status: Http::STATUS_BAD_REQUEST);
		}

		try {
			$format = RecordFileReader::formatOf(fileName: (string)($file['name'] ?? ''));
		} catch (InvalidArgumentException $notARecordFile) {
			return self::error(message: $notARecordFile->getMessage(), status: Http::STATUS_BAD_REQUEST);
		}

		return ['path' => $file['tmp_name'], 'format' => $format];
	}//end uploadedFile()

	/**
	 * The user's column decisions, or null when they are not a column => property|null object.
	 *
	 * @return array<string, string|null>|null
	 */
	private function decisions(): ?array {
		$raw = $this->request->getParam(key: 'decisions', default: []);
		if (is_string($raw) === true) {
			$raw = json_decode($raw, true);
		}

		if (is_array($raw) === false) {
			return null;
		}

		$decisions = [];
		foreach ($raw as $column => $property) {
			if ($property !== null && is_string($property) === false) {
				return null;
			}

			$decisions[(string)$column] = $property;
		}

		return $decisions;
	}//end decisions()

	/**
	 * The nominated identifying property; null (create only) when none or an empty one is sent.
	 *
	 * @return string|null
	 */
	private function identifier(): ?string {
		$identifier = $this->request->getParam(key: 'identifier');
		if (is_string($identifier) === false || trim($identifier) === '') {
			return null;
		}

		return trim($identifier);
	}//end identifier()

	/**
	 * The progress key of this import, or null when the request asked for no (valid) progress token.
	 *
	 * @return string|null
	 */
	private function progressKey(): ?string {
		$token = $this->request->getParam(key: 'progress');
		if (is_string($token) === false) {
			return null;
		}

		return ImportProgress::key(userId: $this->userId(), token: $token);
	}//end progressKey()

	/**
	 * The signed-in user's id, or an empty string when there is none.
	 *
	 * @return string
	 */
	private function userId(): string {
		return (string)$this->userSession->getUser()?->getUID();
	}//end userId()

	/**
	 * The schema's property names.
	 *
	 * @param Schema $schema The schema.
	 *
	 * @return list<string>
	 */
	private static function propertyNames(Schema $schema): array {
		return array_map('strval', array_keys($schema->getProperties()));
	}//end propertyNames()

	/**
	 * An error response.
	 *
	 * @param string $message The message.
	 * @param int    $status  The HTTP status.
	 *
	 * @return JSONResponse
	 */
	private static function error(string $message, int $status): JSONResponse {
		return new JSONResponse(data: ['error' => $message], statusCode: $status);
	}//end error()
}//end class
