<?php

/**
 * AVG / GDPR processing-log inquiry & export controller (verwerkingenlogging).
 *
 * Read-only, access-guarded surface over the append-only
 * `oc_openregister_processing_log`. The log itself is sensitive (it
 * records who looked at whose personal data), so every endpoint is:
 *
 *   - Admin-only by default with delegation to a configurable
 *     privacy-officer (FG) NC group (`processing_log_fg_group`,
 *     default `privacy-officer`). NC SecurityMiddleware already makes
 *     these admin-only at the framework level (no `#[NoAdminRequired]`);
 *     the in-body check additionally admits the FG group and fails
 *     CLOSED for everyone else.
 *   - Organisation-scoped: a non-admin caller only ever queries the
 *     organisations they belong to — no IDOR across tenants.
 *   - Confidential-gated: `confidential` entries are excluded for
 *     non-FG callers at the mapper-query level.
 *
 * The log is append-only by surface: this controller exposes ONLY GET
 * endpoints — there is deliberately no update or delete route.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Controller;

use DateTime;
use OCA\OpenRegister\Db\ProcessingLogMapper;
use OCA\OpenRegister\Service\OrganisationService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * REST endpoints for querying and exporting the AVG processing log.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 *
 * @spec openspec/specs/avg-verwerkingsregister/spec.md
 */
class ProcessingLogController extends Controller
{

    /**
     * App identifier for IAppConfig reads.
     *
     * @var string
     */
    private const APP_ID = 'openregister';

    /**
     * App-config key naming the privacy-officer (FG) delegation group.
     *
     * @var string
     */
    private const FG_GROUP_KEY = 'processing_log_fg_group';

    /**
     * Default privacy-officer group name.
     *
     * @var string
     */
    private const FG_GROUP_DEFAULT = 'privacy-officer';

    /**
     * Maximum per-subject extract range in days (configurable).
     *
     * @var string
     */
    private const MAX_RANGE_KEY = 'processing_export_max_range_days';

    /**
     * Constructor.
     *
     * @param string              $appName             App identifier.
     * @param IRequest            $request             Active request.
     * @param ProcessingLogMapper $logMapper           Append-only log mapper.
     * @param IUserSession        $userSession         Current user session.
     * @param IGroupManager       $groupManager        Group manager (admin + FG gate).
     * @param IAppConfig          $appConfig           App-config reader.
     * @param OrganisationService $organisationService Org-scoping helper.
     */
    public function __construct(
        string $appName,
        IRequest $request,
        private readonly ProcessingLogMapper $logMapper,
        private readonly IUserSession $userSession,
        private readonly IGroupManager $groupManager,
        private readonly IAppConfig $appConfig,
        private readonly OrganisationService $organisationService,
    ) {
        parent::__construct(appName: $appName, request: $request);

    }//end __construct()

    /**
     * GET /api/avg/verwerkingen — filtered processing-log inquiry.
     *
     * Optional query params: `register`, `schema`, `activity`, `actor`,
     * `action`, `subjectIdType`, `subjectIdValue`, `from`, `to`,
     * `limit`, `offset`.
     *
     * Admin-only by NC default; delegated to the FG group. Fails closed.
     *
     * @return JSONResponse Wrapped list envelope, 401, or 403.
     *
     * @NoCSRFRequired
     *
     * @spec openspec/specs/avg-verwerkingsregister/spec.md
     */
    public function index(): JSONResponse
    {
        $access = $this->resolveAccess();
        if ($access instanceof JSONResponse) {
            return $access;
        }

        [$isFg, $organisationId] = $access;

        $entries = $this->logMapper->findFiltered(
            filters: [
                'register_id'      => $this->optionalStringParam(key: 'register'),
                'schema_id'        => $this->optionalStringParam(key: 'schema'),
                'activity_id'      => $this->optionalStringParam(key: 'activity'),
                'actor'            => $this->optionalStringParam(key: 'actor'),
                'action'           => $this->optionalStringParam(key: 'action'),
                'subject_id_type'  => $this->optionalStringParam(key: 'subjectIdType'),
                'subject_id_value' => $this->optionalStringParam(key: 'subjectIdValue'),
            ],
            from: $this->optionalDateParam(key: 'from'),
            to: $this->optionalDateParam(key: 'to'),
            organisationId: $organisationId,
            includeConfidential: $isFg,
            limit: $this->intParam(key: 'limit', default: 100, max: 1000),
            offset: $this->intParam(key: 'offset', default: 0, max: 1000000)
        );

        return new JSONResponse(data: ['count' => count($entries), 'results' => $entries]);

    }//end index()

    /**
     * GET /api/avg/verwerkingen/betrokkene — per-subject inzage extract.
     *
     * Required query params: `subjectIdType`, `subjectIdValue`.
     * Optional: `from`, `to`. Range bounded by
     * `processing_export_max_range_days` (default 366) → HTTP 422.
     *
     * @return JSONResponse Extract envelope, 400/422, 401, or 403.
     *
     * @NoCSRFRequired
     *
     * @spec openspec/specs/avg-verwerkingsregister/spec.md
     */
    public function betrokkene(): JSONResponse
    {
        $access = $this->resolveAccess();
        if ($access instanceof JSONResponse) {
            return $access;
        }

        [$isFg, $organisationId] = $access;

        $idType  = $this->optionalStringParam(key: 'subjectIdType');
        $idValue = $this->optionalStringParam(key: 'subjectIdValue');
        if ($idType === null || $idValue === null) {
            return new JSONResponse(
                data: ['error' => 'subjectIdType and subjectIdValue are required'],
                statusCode: Http::STATUS_BAD_REQUEST
            );
        }

        $from = $this->optionalDateParam(key: 'from');
        $to   = $this->optionalDateParam(key: 'to');

        $rangeError = $this->validateRange(from: $from, to: $to);
        if ($rangeError instanceof JSONResponse) {
            return $rangeError;
        }

        $entries = $this->logMapper->findBySubject(
            idType: $idType,
            idValue: $idValue,
            from: $from,
            to: $to,
            organisationId: $organisationId,
            includeConfidential: $isFg
        );

        return new JSONResponse(
            data: [
                'subject' => ['idType' => $idType, 'idValue' => $idValue],
                'period'  => ['from' => $from?->format('c'), 'to' => $to?->format('c')],
                'count'   => count($entries),
                'reads'   => $entries,
            ]
        );

    }//end betrokkene()

    /**
     * Resolve the caller's access posture, or a denial response.
     *
     * @return JSONResponse|array{0: bool, 1: string|null} [isFg, organisationId] or a 401/403.
     */
    private function resolveAccess()
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(
                data: ['error' => 'Authentication required'],
                statusCode: Http::STATUS_UNAUTHORIZED
            );
        }

        $groups  = $this->groupManager->getUserGroupIds($user);
        $isAdmin = in_array('admin', $groups, true);
        $isFg    = in_array($this->fgGroup(), $groups, true);

        if ($isAdmin === false && $isFg === false) {
            // Fail closed: neither admin nor privacy officer.
            return new JSONResponse(
                data: ['error' => 'Privacy-officer or admin privileges required'],
                statusCode: Http::STATUS_FORBIDDEN
            );
        }

        // Admins query unscoped; FG-only callers are tenant-scoped to
        // their own organisation so they can never read another
        // tenant's log (no cross-tenant IDOR).
        $organisationId = null;
        if ($isAdmin === false) {
            $organisationId = $this->callerOrganisationId();
            if ($organisationId === null) {
                return new JSONResponse(data: ['count' => 0, 'results' => []]);
            }
        }

        return [$isFg || $isAdmin, $organisationId];

    }//end resolveAccess()

    /**
     * Active organisation uuid for a non-admin caller, or null.
     *
     * @return string|null
     */
    private function callerOrganisationId(): ?string
    {
        $organisations = $this->organisationService->getUserOrganisations();
        foreach ($organisations as $organisation) {
            $uuid = (string) $organisation->getUuid();
            if ($uuid !== '') {
                return $uuid;
            }
        }

        return null;

    }//end callerOrganisationId()

    /**
     * Configured privacy-officer (FG) group name.
     *
     * @return string
     */
    private function fgGroup(): string
    {
        $value = $this->appConfig->getValueString(self::APP_ID, self::FG_GROUP_KEY, self::FG_GROUP_DEFAULT);
        if ($value === '') {
            return self::FG_GROUP_DEFAULT;
        }

        return $value;

    }//end fgGroup()

    /**
     * Validate the requested period against the configured maximum range.
     *
     * @param DateTime|null $from Lower bound.
     * @param DateTime|null $to   Upper bound.
     *
     * @return JSONResponse|null 422 when the range is too wide, else null.
     */
    private function validateRange(?DateTime $from, ?DateTime $to): ?JSONResponse
    {
        if ($from === null || $to === null) {
            return null;
        }

        $maxDays  = (int) $this->appConfig->getValueInt(self::APP_ID, self::MAX_RANGE_KEY, 366);
        $spanDays = (int) floor(($to->getTimestamp() - $from->getTimestamp()) / 86400);
        if ($spanDays > $maxDays) {
            return new JSONResponse(
                data: [
                    'error'   => 'Requested period exceeds the maximum extract range',
                    'maxDays' => $maxDays,
                ],
                statusCode: Http::STATUS_UNPROCESSABLE_ENTITY
            );
        }

        return null;

    }//end validateRange()

    /**
     * Read a request parameter as a non-empty string, or null.
     *
     * @param string $key Parameter name.
     *
     * @return string|null
     */
    private function optionalStringParam(string $key): ?string
    {
        $value = $this->request->getParam(key: $key);
        if ($value === null || $value === '') {
            return null;
        }

        return (string) $value;

    }//end optionalStringParam()

    /**
     * Read a request parameter as an ISO-8601 DateTime, or null.
     *
     * @param string $key Parameter name.
     *
     * @return DateTime|null
     */
    private function optionalDateParam(string $key): ?DateTime
    {
        $value = $this->optionalStringParam(key: $key);
        if ($value === null) {
            return null;
        }

        try {
            return new DateTime($value);
        } catch (\Throwable $e) {
            return null;
        }

    }//end optionalDateParam()

    /**
     * Read a bounded integer request parameter.
     *
     * @param string $key     Parameter name.
     * @param int    $default Fallback value.
     * @param int    $max     Upper clamp.
     *
     * @return int
     */
    private function intParam(string $key, int $default, int $max): int
    {
        $value = $this->request->getParam(key: $key);
        if ($value === null || is_numeric($value) === false) {
            return $default;
        }

        $int = (int) $value;
        if ($int < 0) {
            return $default;
        }

        return min($int, $max);

    }//end intParam()
}//end class
