<?php

/**
 * Who called what, and the declaration an administrator edits to deprecate it.
 *
 * Two surfaces, and they are the same conversation from both ends.
 *
 *  - `GET /api/callers` answers "who still calls this". That is what turns a
 *    deprecation from a mailing list into a phone call, and it is the reason
 *    the caller record exists at all.
 *  - `GET` and `PUT /api/settings/api-versions` are the declaration itself. A
 *    version lifecycle nobody can edit is a lifecycle that never moves: the
 *    first PR of this change shipped the mechanism and left the only way to
 *    use it an `occ config:app:set` line, which is a feature an administrator
 *    cannot find.
 *
 * 🔴 ADMIN ONLY, AND CHECKED IN THE BODY. The caller record names every
 * principal that has touched this instance's API, which is a list of who
 * integrates with this gemeente. `#[NoAdminRequired]` plus a body check is the
 * house form here, because the attribute alone answers "is anyone logged in",
 * which is not the question.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
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
 * @spec openspec/changes/api-as-a-versioned-surface/specs/api-surface-governance/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Controller;

use DateTime;
use OCA\OpenRegister\Db\ApiCallRecord;
use OCA\OpenRegister\Db\ApiCallRecordMapper;
use OCA\OpenRegister\Service\ApiVersion\ApiVersion;
use OCA\OpenRegister\Service\ApiVersion\ApiVersionCatalogue;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The caller record, and the version declaration an administrator edits.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller
 *
 * @psalm-suppress UnusedClass Registered through appinfo/routes.php.
 */
class ApiCallersController extends Controller {

	/**
	 * How far back the record is read when no period is named.
	 *
	 * A month, because that is the unit a deprecation window is discussed in.
	 *
	 * @var int
	 */
	private const DEFAULT_PERIOD_DAYS = 30;

	/**
	 * The most records one read returns.
	 *
	 * @var int
	 */
	private const MAX_ROWS = 500;

	/**
	 * Constructor.
	 *
	 * @param string $appName The app name.
	 * @param IRequest $request The incoming request.
	 * @param ApiCallRecordMapper $records Reads the caller record.
	 * @param ApiVersionCatalogue $catalogue The declared contract versions.
	 * @param IAppConfig $appConfig Reads and writes the declaration.
	 * @param IUserSession $userSession Names the caller.
	 * @param IGroupManager $groupManager Answers whether that caller is an administrator.
	 * @param LoggerInterface $logger Records a read or a write that failed.
	 *
	 * @return void
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly ApiCallRecordMapper $records,
		private readonly ApiVersionCatalogue $catalogue,
		private readonly IAppConfig $appConfig,
		private readonly IUserSession $userSession,
		private readonly IGroupManager $groupManager,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: $appName, request: $request);

	}//end __construct()

	/**
	 * Who called which endpoint over a period.
	 *
	 * Optional `from` and `to` as ISO dates, and `version` to narrow to one
	 * contract. Busiest caller first, because the question being asked is
	 * almost always "who would this deprecation hurt most".
	 *
	 * @return JSONResponse The caller record.
	 *
	 * @psalm-return JSONResponse<200|403|500, array<string, mixed>, array<never, never>>
	 *
	 * @spec openspec/changes/api-as-a-versioned-surface/specs/api-surface-governance/spec.md#requirement-every-api-call-records-its-caller-and-a-caller-carries-a-limit-and-an-address-binding-req-avs-003
	 *
	 * @contract tests/Unit/Controller/ApiCallersControllerTest.php
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: 60, period: 60)]
	public function index(): JSONResponse {
		$refusal = $this->requireAdministrator();
		if ($refusal !== null) {
			return $refusal;
		}

		$to = $this->dateParam(name: 'to', fallback: new DateTime());
		$from = $this->dateParam(
			name: 'from',
			fallback: (new DateTime())->modify('-' . self::DEFAULT_PERIOD_DAYS . ' days')
		);

		$version = trim((string)$this->request->getParam('version', ''));

		try {
			$rows = $this->records->findInPeriod(
				from: $from,
				to: $to,
				apiVersion: ($version === '') ? null : $version,
				limit: self::MAX_ROWS,
			);
		} catch (Throwable $e) {
			$this->logger->error('OpenRegister: could not read the API caller record.', ['exception' => $e]);

			return new JSONResponse(
				data: ['error' => 'The caller record could not be read.'],
				statusCode: Http::STATUS_INTERNAL_SERVER_ERROR,
			);
		}

		return new JSONResponse(
			data: [
				'from' => $from->format('c'),
				'to' => $to->format('c'),
				'total' => count($rows),
				'truncated' => (count($rows) === self::MAX_ROWS),
				'callers' => array_map(
					static fn (ApiCallRecord $record): array => $record->jsonSerialize(),
					$rows
				),
			]
		);

	}//end index()

	/**
	 * The version declaration as it stands.
	 *
	 * @return JSONResponse The declaration, and any refusals it carries.
	 *
	 * @psalm-return JSONResponse<200|403, array<string, mixed>, array<never, never>>
	 *
	 * @spec openspec/changes/api-as-a-versioned-surface/specs/openapi-generation/spec.md#requirement-two-contract-versions-are-served-at-once-with-a-declared-lifecycle-req-avs-005
	 *
	 * @contract tests/Unit/Controller/ApiCallersControllerTest.php
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: 60, period: 60)]
	public function readDeclaration(): JSONResponse {
		$refusal = $this->requireAdministrator();
		if ($refusal !== null) {
			return $refusal;
		}

		return new JSONResponse(
			data: [
				'versions' => array_values(array_map(
					static fn (ApiVersion $version): array => $version->jsonSerialize(),
					$this->catalogue->all()
				)),
				'currentVersion' => $this->catalogue->current()->id,
				'rejections' => $this->catalogue->rejections(),
				'administered' => ($this->storedDeclaration() !== ''),
			]
		);

	}//end readDeclaration()

	/**
	 * Replace the version declaration.
	 *
	 * 🔑 THE WRITE IS VALIDATED BEFORE IT IS STORED, AND REFUSED RATHER THAN
	 * STORED BROKEN. The catalogue falls back to the built-in contract when the
	 * stored declaration is unusable, which is the right behaviour at read time
	 * and the wrong answer here: an administrator who saves a broken
	 * declaration would get a 200, see the built-in contract come back, and
	 * have no idea their edit did nothing.
	 *
	 * @return JSONResponse The stored declaration, or the refusal.
	 *
	 * @psalm-return JSONResponse<200|400|403|500, array<string, mixed>, array<never, never>>
	 *
	 * @spec openspec/changes/api-as-a-versioned-surface/specs/openapi-generation/spec.md#requirement-two-contract-versions-are-served-at-once-with-a-declared-lifecycle-req-avs-005
	 *
	 * @contract tests/Unit/Controller/ApiCallersControllerTest.php
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: 30, period: 60)]
	public function writeDeclaration(): JSONResponse {
		$refusal = $this->requireAdministrator();
		if ($refusal !== null) {
			return $refusal;
		}

		$declared = $this->request->getParam('versions');
		if (is_array($declared) === false) {
			return new JSONResponse(
				data: ['error' => 'Send a "versions" list of version declarations.'],
				statusCode: Http::STATUS_BAD_REQUEST,
			);
		}

		$errors = $this->declarationErrors(declared: $declared);
		if ($errors !== []) {
			return new JSONResponse(
				data: [
					'error' => 'This declaration cannot be served, so it was not stored.',
					'rejections' => $errors,
				],
				statusCode: Http::STATUS_BAD_REQUEST,
			);
		}

		try {
			$this->appConfig->setValueString(
				'openregister',
				ApiVersionCatalogue::CONFIG_KEY,
				(string)json_encode(array_values($declared))
			);
		} catch (Throwable $e) {
			$this->logger->error('OpenRegister: could not store the API version declaration.', ['exception' => $e]);

			return new JSONResponse(
				data: ['error' => 'The declaration could not be stored.'],
				statusCode: Http::STATUS_INTERNAL_SERVER_ERROR,
			);
		}

		return new JSONResponse(data: ['versions' => array_values($declared), 'stored' => true]);

	}//end writeDeclaration()

	/**
	 * Why a declaration could not be served, if it could not.
	 *
	 * Asks the catalogue rather than reimplementing its rules: a second copy of
	 * "what makes a declaration usable" is a second copy that will disagree with
	 * the first one the week after it is written.
	 *
	 * @param array<int, mixed> $declared The candidate declarations.
	 *
	 * @return array<string, string> The refusals, keyed by the version they concern.
	 */
	private function declarationErrors(array $declared): array {
		if ($declared === []) {
			return ['*' => 'Declare at least one version; an instance that serves none has no answer for any caller.'];
		}

		return $this->catalogue->inspect(declarations: array_values($declared));

	}//end declarationErrors()

	/**
	 * The stored declaration, or the empty string.
	 *
	 * @return string The raw configuration value.
	 */
	private function storedDeclaration(): string {
		try {
			return trim((string)$this->appConfig->getValueString('openregister', ApiVersionCatalogue::CONFIG_KEY, ''));
		} catch (Throwable) {
			return '';
		}

	}//end storedDeclaration()

	/**
	 * Refuse a caller who is not an administrator.
	 *
	 * @return JSONResponse|null The refusal, or null when the caller may proceed.
	 */
	private function requireAdministrator(): ?JSONResponse {
		try {
			$user = $this->userSession->getUser();
			if ($user !== null && $this->groupManager->isAdmin($user->getUID()) === true) {
				return null;
			}
		} catch (Throwable) {
			// Fall through to the refusal: an administrator check that failed
			// is not an administrator check that passed.
			$user = null;
		}

		return new JSONResponse(
			data: ['error' => 'Reading who calls this instance, and editing the version declaration, are administrator actions.'],
			statusCode: Http::STATUS_FORBIDDEN,
		);

	}//end requireAdministrator()

	/**
	 * Read an ISO date parameter, falling back when it is absent or unparseable.
	 *
	 * @param string $name The parameter name.
	 * @param DateTime $fallback The value to use when there is none.
	 *
	 * @return DateTime The date.
	 */
	private function dateParam(string $name, DateTime $fallback): DateTime {
		$raw = trim((string)$this->request->getParam($name, ''));
		if ($raw === '') {
			return $fallback;
		}

		try {
			return new DateTime($raw);
		} catch (Throwable) {
			return $fallback;
		}

	}//end dateParam()
}//end class
