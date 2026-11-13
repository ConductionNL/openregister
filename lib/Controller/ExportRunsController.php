<?php

/**
 * Class ExportRunsController
 *
 * The exports area: which copies of the register have left the building, who
 * made them, how many rows they carried, when their file stops existing and
 * how often the register served it.
 *
 * Scoped in the body to the caller's own runs. An administrator sees every
 * run, which is the whole point of the area: "who holds an export of this
 * register" is a question an administrator is asked rather than one they
 * choose to ask.
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
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/an-export-is-a-file-with-a-life/specs/data-import-export/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Controller;

use DateTime;
use Exception;
use OCA\OpenRegister\Service\Export\ExportRightService;
use OCA\OpenRegister\Service\Export\ExportRunRecorder;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\Response;
use OCP\AppFramework\Http\StreamResponse;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;

/**
 * Lists the produced exports.
 */
class ExportRunsController extends Controller {
	/**
	 * The query parameters that narrow the listing, in the order they are passed on.
	 *
	 * @var array<int, string>
	 */
	private const FILTER_KEYS = ['register', 'schema', 'profile', 'source', 'status', 'actor', 'from', 'until'];

	/**
	 * The filters that bound the period the export was produced in; they must parse as dates.
	 *
	 * @var array<int, string>
	 */
	private const PERIOD_KEYS = ['from', 'until'];


	/**
	 * Constructor.
	 *
	 * @param string            $appName      The app name.
	 * @param IRequest          $request      The request.
	 * @param ExportRunRecorder $runs         The export runs.
	 * @param IUserSession      $userSession  Resolves the caller.
	 * @param ExportRightService $rights      Decides whether the caller sees every run.
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly ExportRunRecorder $runs,
		private readonly IUserSession $userSession,
		private readonly ExportRightService $rights,
	) {
		parent::__construct(appName: $appName, request: $request);
	}//end __construct()

	/**
	 * GET /api/exports - the runs this caller made.
	 *
	 * Filters on register, schema, profile, source, status and actor, and on
	 * the period `from` / `until` (ISO 8601, inclusive) over when the export
	 * was produced. An unknown filter key is dropped rather than widening the
	 * result; a date that does not parse is refused with 400, because
	 * dropping it would widen the result too. Whether the caller sees every
	 * run or only their own is answered by ExportRightService.
	 *
	 * @return JSONResponse The runs, or 401 when anonymous.
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 * @no-admin-idor-exempt Guarded in-body: the listing is scoped to the SESSION's user, and takes no
	 *     actor from the request that could name somebody else's runs.
	 *
	 * @spec openspec/changes/an-export-is-a-file-with-a-life/specs/data-import-export/spec.md
	 */
	public function index(): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(data: ['error' => 'Authentication required'], statusCode: Http::STATUS_UNAUTHORIZED);
		}

		$filters = $this->requestedFilters();
		$refusal = $this->periodRefusal(filters: $filters);
		if ($refusal !== null) {
			return $refusal;
		}

		return $this->runPage(user: $user, filters: $filters);
	}//end index()

	/**
	 * The non-blank filter parameters of the request, as text.
	 *
	 * @return array<string, string> The filters, in the order of FILTER_KEYS
	 */
	private function requestedFilters(): array {
		$filters = [];
		foreach (self::FILTER_KEYS as $key) {
			$value = $this->request->getParam(key: $key);
			if ($value !== null && $value !== '') {
				$filters[$key] = (string)$value;
			}
		}

		return $filters;
	}//end requestedFilters()

	/**
	 * Refuse a period bound that is not a date: dropping it would widen the list.
	 *
	 * @param array<string, string> $filters The requested filters
	 *
	 * @return JSONResponse|null A 400 naming the first bound that does not parse, or null
	 */
	private function periodRefusal(array $filters): ?JSONResponse {
		foreach (self::PERIOD_KEYS as $key) {
			if (isset($filters[$key]) === true && $this->isDate(value: $filters[$key]) === false) {
				return new JSONResponse(
					data: ['error' => sprintf('The %s date "%s" is not a date.', $key, $filters[$key])],
					statusCode: Http::STATUS_BAD_REQUEST
				);
			}
		}

		return null;
	}//end periodRefusal()

	/**
	 * Whether a period bound parses as a date.
	 *
	 * @param string $value The bound as given
	 *
	 * @return bool True when it parses
	 */
	private function isDate(string $value): bool {
		try {
			new DateTime($value);
		} catch (Exception) {
			return false;
		}

		return true;
	}//end isDate()

	/**
	 * List one page of the export runs the caller may see.
	 *
	 * @param IUser                 $user    The caller
	 * @param array<string, string> $filters The requested filters, period bounds already checked
	 *
	 * @return JSONResponse The runs, with the requested page and filters echoed
	 */
	private function runPage(IUser $user, array $filters): JSONResponse {
		$limit = (int)($this->request->getParam(key: 'limit') ?? 50);
		$offset = (int)($this->request->getParam(key: 'offset') ?? 0);

		$results = $this->runs->listFor(
			actor: $user->getUID(),
			isAdmin: $this->rights->seesEveryExportRun(userId: $user->getUID()),
			filters: array_merge($filters, $this->period(filters: $filters)),
			limit: max(1, min($limit, 200)),
			offset: max(0, $offset)
		);

		return $this->pageResponse(results: $results, filters: $filters);
	}//end runPage()

	/**
	 * The period bounds of the filters as dates.
	 *
	 * @param array<string, string> $filters The requested filters, period bounds already checked
	 *
	 * @return array<string, DateTime> The bounds present
	 */
	private function period(array $filters): array {
		$period = [];
		foreach (self::PERIOD_KEYS as $key) {
			if (isset($filters[$key]) === true) {
				$period[$key] = new DateTime($filters[$key]);
			}
		}

		return $period;
	}//end period()

	/**
	 * The listing answer: the runs, the requested page and the filters as given.
	 *
	 * @param array<int, mixed>     $results The runs
	 * @param array<string, string> $filters The requested filters
	 *
	 * @return JSONResponse The answer
	 */
	private function pageResponse(array $results, array $filters): JSONResponse {
		return new JSONResponse(
			data: [
				'results' => $results,
				'limit' => (int)($this->request->getParam(key: 'limit') ?? 50),
				'offset' => (int)($this->request->getParam(key: 'offset') ?? 0),
				'filters' => $filters,
			]
		);
	}//end pageResponse()

	/**
	 * GET /api/exports/{uuid}/download - hand the run's file back, and count it.
	 *
	 * The maker of the run gets it, and so does a caller ExportRightService
	 * lets see every run; anybody else gets 404, as for a uuid nobody has. An
	 * expired run answers 410: the record stays, the file does not. Each
	 * served download counts once on the run, never on the file, because a
	 * file copied inside Files is no longer what the register handed out.
	 *
	 * @param string $uuid The run.
	 *
	 * @return Response The file, or a JSON error.
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 * @no-admin-idor-exempt Guarded in-body: ExportRunRecorder::openForDownload() serves only the
	 *     run's maker or a caller who sees every run, and answers 404 to anybody else.
	 *
	 * @spec openspec/changes/an-export-is-a-file-with-a-life/specs/data-import-export/spec.md#requirement-downloads-are-counted-on-the-run
	 */
	public function download(string $uuid): Response {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(data: ['error' => 'Authentication required'], statusCode: Http::STATUS_UNAUTHORIZED);
		}

		$found = $this->runs->openForDownload(
			uuid: $uuid,
			callerUid: $user->getUID(),
			seesEveryRun: $this->rights->seesEveryExportRun(userId: $user->getUID())
		);
		if ($found['status'] !== Http::STATUS_OK || isset($found['file']) === false) {
			return new JSONResponse(data: ['error' => ($found['error'] ?? 'No export with this id.')], statusCode: $found['status']);
		}

		$file = $found['file'];
		$handle = $file->fopen('r');
		if (is_resource($handle) === false) {
			return new JSONResponse(data: ['error' => 'The file of this export could not be opened.'], statusCode: Http::STATUS_GONE);
		}

		$this->runs->countDownload(uuid: $uuid);

		$response = new StreamResponse($handle);
		$response->addHeader('Content-Type', (string)$file->getMimetype());
		$response->addHeader('Content-Disposition', 'attachment; filename="'.rawurlencode((string)$file->getName()).'"');

		return $response;
	}//end download()
}//end class
