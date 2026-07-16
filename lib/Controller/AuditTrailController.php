<?php

/**
 * Class AuditTrailController
 *
 * Controller for managing audit trail operations in the OpenRegister app.
 * Provides functionality to retrieve audit trails related to objects within registers and schemas.
 * Includes hash chain verification, verwerkingsregister, and immutability enforcement.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Controller
 * @package  OCA\OpenRegister\AppInfo
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/audit-trail-immutable/spec.md#requirement-the-audit-trail-must-use-cryptographic-hash-chaining
 * @spec openspec/specs/audit-hash-chain/spec.md
 * @spec openspec/specs/audit-trail-immutable/spec.md
 * @spec openspec/specs/audit-trail-immutable/spec.md
 * @spec openspec/specs/verwerkingsregister-api/spec.md
 * @spec openspec/specs/verwerkingsregister-api/spec.md
 * @spec openspec/specs/verwerkingsregister-api/spec.md
 */

namespace OCA\OpenRegister\Controller;

use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Service\AuditHashService;
use OCA\OpenRegister\Service\LogService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * Class AuditTrailController
 *
 * Handles all audit trail related operations.
 *
 * @psalm-suppress UnusedClass
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassLength)     Controller covers audit trail, verification, verwerkingsregister
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)   Necessary service dependencies
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)     One public method per audit trail route
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) Audit-trail surface fans out into hash-verify,
 *     verwerkingsregister, inzageverzoek, export, immutability + admin-gating; the controller's
 *     overall cyclomatic complexity sits one step above the default threshold (51 vs 50) after
 *     the wave-3 C6 admin-only gates on index()/show() were added. Splitting the controller
 *     would force a route-table reshuffle without removing any actual branching.
 */
class AuditTrailController extends Controller
{
    /**
     * Constructor for AuditTrailController
     *
     * @param string             $appName          The name of the app
     * @param IRequest           $request          The request object
     * @param LogService         $logService       The log service
     * @param AuditTrailMapper   $auditTrailMapper The audit trail mapper
     * @param AuditHashService   $auditHashService The audit hash chain service
     * @param \OCP\IUserSession  $userSession      Active user session for caller identity.
     * @param \OCP\IGroupManager $groupManager     Group manager for admin / role checks.
     */
    public function __construct(
        string $appName,
        IRequest $request,
        private readonly LogService $logService,
        private readonly AuditTrailMapper $auditTrailMapper,
        private readonly AuditHashService $auditHashService,
        private readonly \OCP\IUserSession $userSession,
        private readonly \OCP\IGroupManager $groupManager
    ) {
        parent::__construct(appName: $appName, request: $request);
    }//end __construct()

    /**
     * Gate destructive / bulk-export / bulk-read audit operations on admin membership.
     *
     * SECURITY: `clearAll` wipes the entire audit table — a chain of
     * trust for AVG/GDPR Art 30 reviews. `export` dumps every row in
     * bulk and is an obvious recon path across tenants. `index` and
     * `show` expose cross-tenant audit-trail rows (per-row diffs of
     * every object change in every register/schema, frequently PII or
     * IP-heavy); without this gate any authenticated user — including
     * users restricted to a single app group — could enumerate every
     * other tenant's change history (wave-3 C6). All surfaces are
     * admin-only at the framework level for sensitive routes (no
     * `@NoAdminRequired`) and this body-level helper stays as
     * defence-in-depth so removing the framework gate by accident does
     * not silently open the surface.
     *
     * @return JSONResponse|null 401/403 response when blocked, null when allowed.
     */
    private function requireAdmin(): ?JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(
                data: ['error' => 'Authentication required'],
                statusCode: 401
            );
        }

        if ($this->groupManager->isAdmin($user->getUID()) === false) {
            return new JSONResponse(
                data: ['error' => 'Forbidden: this audit-trail operation is admin-only'],
                statusCode: 403
            );
        }

        return null;

    }//end requireAdmin()

    /**
     * Extract pagination, filter, and search parameters from request
     *
     * @return array The extracted request parameters
     *
     * @SuppressWarnings(PHPMD.NPathComplexity)      Request parameter extraction requires many conditional checks
     * @SuppressWarnings(PHPMD.CyclomaticComplexity) Each of limit/offset/page supports
     *   two alternative parameter names (with and without underscore prefix); the resulting
     *   if/else-if pairs are required to preserve backward compatibility with both formats.
     *
     * @spec openspec/specs/audit-trail-immutable/spec.md#requirement-the-audit-trail-must-use-cryptographic-hash-chaining
     */
    private function extractRequestParameters(): array
    {
        // Get request parameters for filtering and pagination.
        $params = $this->request->getParams();

        // Extract pagination parameters.
        $limit = 20;
        if (($params['limit'] ?? null) !== null) {
            $limit = (int) $params['limit'];
        } else if (($params['_limit'] ?? null) !== null) {
            $limit = (int) $params['_limit'];
        }

        $offset = null;
        if (($params['offset'] ?? null) !== null) {
            $offset = (int) $params['offset'];
        } else if (($params['_offset'] ?? null) !== null) {
            $offset = (int) $params['_offset'];
        }

        $page = null;
        if (($params['page'] ?? null) !== null) {
            $page = (int) $params['page'];
        } else if (($params['_page'] ?? null) !== null) {
            $page = (int) $params['_page'];
        }

        // If we have a page but no offset, calculate the offset.
        if ($page !== null && $offset === null) {
            $offset = ($page - 1) * $limit;
        }

        // Extract search parameter.
        $search = $params['search'] ?? $params['_search'] ?? null;

        $sort    = $this->buildSortFromParams(params: $params);
        $filters = $this->buildFiltersFromParams(params: $params);

        return [
            'limit'   => $limit,
            'offset'  => $offset,
            'page'    => $page,
            'filters' => $filters,
            'sort'    => $sort,
            'search'  => $search,
        ];
    }//end extractRequestParameters()

    /**
     * Build a sort map from raw request params.
     *
     * Supports bracket format `_sort[field]=ASC|DESC` and flat format
     * `sort=field&order=ASC|DESC`. Defaults to `created DESC`.
     *
     * @param array<string,mixed> $params Raw request params.
     *
     * @return array<string,string>
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity) Sort format normalisation
     *     inherently branches on array vs. scalar vs. absent.
     */
    private function buildSortFromParams(array $params): array
    {
        $sort    = [];
        $sortRaw = $params['sort'] ?? $params['_sort'] ?? null;

        if (is_array($sortRaw) === true) {
            // Bracket format: _sort[created]=DESC.
            foreach ($sortRaw as $field => $direction) {
                $sort[$field] = 'DESC';
                if (strtoupper($direction) === 'ASC') {
                    $sort[$field] = 'ASC';
                }
            }
        } else if ($sortRaw !== null) {
            // Flat format: sort=created&order=DESC.
            $sortOrder      = $params['order'] ?? $params['_order'] ?? 'DESC';
            $sort[$sortRaw] = 'DESC';
            if (strtoupper($sortOrder) === 'ASC') {
                $sort[$sortRaw] = 'ASC';
            }
        }

        if (empty($sort) === true) {
            $sort['created'] = 'DESC';
        }

        return $sort;
    }//end buildSortFromParams()

    /**
     * Strip pagination/system keys from raw request params to produce a
     * filter map for mapper queries.
     *
     * @param array<string,mixed> $params Raw request params.
     *
     * @return array<string,mixed>
     */
    private function buildFiltersFromParams(array $params): array
    {
        $systemKeys = [
            'limit',
            '_limit',
            'offset',
            '_offset',
            'page',
            '_page',
            'search',
            '_search',
            'sort',
            '_sort',
            'order',
            '_order',
            '_route',
            'id',
            'register',
            'schema',
            'format',
            'from',
            'to',
            'identifier',
        ];

        return array_filter(
            $params,
            function ($key) use ($systemKeys) {
                return in_array($key, $systemKeys, true) === false;
            },
            ARRAY_FILTER_USE_KEY
        );
    }//end buildFiltersFromParams()

    /**
     * Get all audit trail logs
     *
     * Admin-only at the framework level (no @NoAdminRequired). Body
     * `requireAdmin()` stays as defence-in-depth. The cross-tenant
     * audit-trail index leaks per-row diffs of every object change
     * across every register/schema — wave-3 C6. Returns 200 on
     * success, 401 when anonymous, 403 when non-admin.
     *
     * @return JSONResponse JSON response containing list of audit trails
     *
     * @NoCSRFRequired
     *
     * @spec openspec/specs/audit-trail-immutable/spec.md#requirement-the-audit-trail-must-use-cryptographic-hash-chaining
     * @spec openspec/specs/audit-trail-immutable/spec.md
     */
    public function index(): JSONResponse
    {
        $denial = $this->requireAdmin();
        if ($denial !== null) {
            return $denial;
        }

        // Extract common parameters.
        $params = $this->extractRequestParameters();

        // Get logs from service.
        $logs = $this->logService->getAllLogs($params);

        // Get total count for pagination.
        $total = $this->logService->countAllLogs($params['filters']);

        // Return paginated results.
        return new JSONResponse(
            data: [
                'results' => $logs,
                'total'   => $total,
                'page'    => $params['page'],
                'pages'   => ceil($total / $params['limit']),
                'limit'   => $params['limit'],
                'offset'  => $params['offset'],
            ]
        );
    }//end index()

    /**
     * Get a specific audit trail log by ID
     *
     * Admin-only at the framework level (no @NoAdminRequired). Body
     * `requireAdmin()` stays as defence-in-depth. Without the gate,
     * any authed caller could fetch any audit-trail row by ID — and
     * IDs are sequential, so this is also a trivial enumeration path
     * across every tenant's change history (wave-3 C6). Returns 200
     * on success, 401 when anonymous, 403 when non-admin, 404 if
     * the row does not exist.
     *
     * @param int $id The audit trail ID
     *
     * @return JSONResponse A JSON response containing the log
     *
     * @NoCSRFRequired
     *
     * @spec openspec/specs/audit-trail-immutable/spec.md#requirement-the-audit-trail-must-use-cryptographic-hash-chaining
     * @spec openspec/specs/audit-trail-immutable/spec.md
     */
    public function show(int $id): JSONResponse
    {
        $denial = $this->requireAdmin();
        if ($denial !== null) {
            return $denial;
        }

        try {
            $log = $this->logService->getLog($id);
            return new JSONResponse(data: $log);
        } catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
            return new JSONResponse(data: ['error' => 'Audit trail not found'], statusCode: 404);
        }
    }//end show()

    /**
     * Reject audit trail modification (immutability enforcement).
     *
     * @param int $id The audit trail ID
     *
     * @return JSONResponse HTTP 405 Method Not Allowed
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @no-admin-idor-exempt Immutability stub: returns HTTP 405 unconditionally and
     *   performs no object read or write, so there is no per-object resource to guard.
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter) $id is required by the OCP Controller
     *   route contract; the method intentionally ignores it to enforce immutability.
     *
     * @spec openspec/specs/audit-trail-immutable/spec.md#requirement-the-audit-trail-must-use-cryptographic-hash-chaining
     */
    public function update(int $id): JSONResponse
    {
        return new JSONResponse(
            data: ['error' => 'Audit trail entries cannot be modified'],
            statusCode: Http::STATUS_METHOD_NOT_ALLOWED
        );
    }//end update()

    /**
     * Get logs for an object
     *
     * Admin-only at the framework level (no @NoAdminRequired): a per-object
     * audit trail records actor UID, IP address and per-field diffs. Returning
     * it for an arbitrary object id leaks cross-tenant PII (wave-3 C7), so it
     * stays admin-only like index()/show()/export(). Body `requireAdmin()` is
     * defence-in-depth.
     *
     * @param string $register The register identifier
     * @param string $schema   The schema identifier
     * @param string $id       The object ID
     *
     * @return JSONResponse JSON response containing audit trails for specific object
     *
     * @NoCSRFRequired
     *
     * @psalm-return JSONResponse<200|400|404,
     *     array{error?: string,
     *     results?: array<\OCA\OpenRegister\Db\AuditTrail>,
     *     total?: int<0, max>, page?: int|null, pages?: float, limit?: int,
     *     offset?: int|null}, array<never, never>>
     *
     * @spec openspec/specs/audit-trail-immutable/spec.md#requirement-the-audit-trail-must-use-cryptographic-hash-chaining
     */
    public function objects(string $register, string $schema, string $id): JSONResponse
    {
        $denial = $this->requireAdmin();
        if ($denial !== null) {
            return $denial;
        }

        // Extract common parameters.
        $params = $this->extractRequestParameters();

        try {
            // Get logs from service.
            $logs = $this->logService->getLogs(
                register: $register,
                schema: $schema,
                id: $id,
                config: $params
            );

            // Get total count for pagination.
            $total = $this->logService->count(register: $register, schema: $schema, id: $id);

            // Return paginated results.
            return new JSONResponse(
                data: [
                    'results' => $logs,
                    'total'   => $total,
                    'page'    => $params['page'],
                    'pages'   => ceil($total / $params['limit']),
                    'limit'   => $params['limit'],
                    'offset'  => $params['offset'],
                ]
            );
        } catch (\InvalidArgumentException $e) {
            return new JSONResponse(data: ['error' => $e->getMessage()], statusCode: 400);
        } catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
            return new JSONResponse(data: ['error' => 'Object not found'], statusCode: 404);
        }//end try
    }//end objects()

    /**
     * Export audit trail logs in specified format
     *
     * Admin-only at the framework level (no @NoAdminRequired). Body
     * `requireAdmin()` stays as defence-in-depth.
     *
     * @NoCSRFRequired
     *
     * @return JSONResponse JSON response with export data or error
     *
     * @spec openspec/specs/audit-trail-immutable/spec.md#requirement-the-audit-trail-must-use-cryptographic-hash-chaining
     * @spec openspec/specs/verwerkingsregister-api/spec.md
     */
    public function export(): JSONResponse
    {
        $denial = $this->requireAdmin();
        if ($denial !== null) {
            return $denial;
        }

        // Extract request parameters.
        $params = $this->extractRequestParameters();

        // Get export specific parameters.
        $format          = $this->request->getParam('format', 'csv');
        $includeChanges  = $this->request->getParam('includeChanges', true);
        $includeMetadata = $this->request->getParam('includeMetadata', false);

        try {
            // Build export configuration.
            $exportConfig = [
                'filters'         => $params['filters'],
                'search'          => $params['search'],
                'includeChanges'  => filter_var($includeChanges, FILTER_VALIDATE_BOOLEAN),
                'includeMetadata' => filter_var($includeMetadata, FILTER_VALIDATE_BOOLEAN),
            ];

            // Export logs using service.
            $exportResult = $this->logService->exportLogs(format: $format, config: $exportConfig);

            // Return export data.
            $content     = $exportResult['content'];
            $contentSize = 0;
            if (is_string($content) === true) {
                $contentSize = strlen($content);
            }

            return new JSONResponse(
                data: [
                    'success' => true,
                    'data'    => [
                        'content'     => $content,
                        'filename'    => $exportResult['filename'],
                        'contentType' => $exportResult['contentType'],
                        'size'        => $contentSize,
                    ],
                ]
            );
        } catch (\InvalidArgumentException $e) {
            return new JSONResponse(
                data: [
                    'error' => 'Invalid export format: '.$e->getMessage(),
                ],
                statusCode: 400
            );
        } catch (\Exception $e) {
            return new JSONResponse(
                data: [
                    'error' => 'Export failed: '.$e->getMessage(),
                ],
                statusCode: 500
            );
        }//end try
    }//end export()

    /**
     * Reject audit trail deletion (immutability enforcement).
     *
     * @param int $id The audit trail ID
     *
     * @return JSONResponse HTTP 405 Method Not Allowed
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @no-admin-idor-exempt Immutability stub: returns HTTP 405 unconditionally and
     *   performs no object read or write, so there is no per-object resource to guard.
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter) $id is required by the OCP Controller
     *   route contract; the method intentionally ignores it to enforce immutability.
     *
     * @spec openspec/specs/audit-trail-immutable/spec.md#requirement-the-audit-trail-must-use-cryptographic-hash-chaining
     */
    public function destroy(int $id): JSONResponse
    {
        return new JSONResponse(
            data: ['error' => 'Audit trail entries cannot be deleted'],
            statusCode: Http::STATUS_METHOD_NOT_ALLOWED
        );
    }//end destroy()

    /**
     * Reject audit trail bulk deletion (immutability enforcement).
     *
     * @return JSONResponse HTTP 405 Method Not Allowed
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @no-admin-idor-exempt Immutability stub: returns HTTP 405 unconditionally and
     *   performs no object read or write, so there is no per-object resource to guard.
     *
     * @spec openspec/specs/audit-trail-immutable/spec.md#requirement-the-audit-trail-must-use-cryptographic-hash-chaining
     */
    public function destroyMultiple(): JSONResponse
    {
        return new JSONResponse(
            data: ['error' => 'Audit trail entries cannot be deleted'],
            statusCode: Http::STATUS_METHOD_NOT_ALLOWED
        );
    }//end destroyMultiple()

    /**
     * Clear all audit trail logs
     *
     * Admin-only at the framework level (no @NoAdminRequired). Body
     * `requireAdmin()` stays as defence-in-depth.
     *
     * @NoCSRFRequired
     *
     * @return JSONResponse JSON response confirming clear or error
     *
     * @spec openspec/specs/audit-trail-immutable/spec.md#requirement-the-audit-trail-must-use-cryptographic-hash-chaining
     * @spec openspec/specs/audit-trail-immutable/spec.md
     */
    public function clearAll(): JSONResponse
    {
        $denial = $this->requireAdmin();
        if ($denial !== null) {
            return $denial;
        }

        try {
            // Use the clearAllLogs method from the mapper.
            $result = $this->auditTrailMapper->clearAllLogs();

            if ($result === true) {
                return new JSONResponse(
                    data: [
                        'success' => true,
                        'message' => 'All audit trails cleared successfully',
                        'deleted' => 'All expired audit trails have been deleted',
                    ],
                    statusCode: 200
                );
            }

            return new JSONResponse(
                data: [
                    'success' => true,
                    'message' => 'No expired audit trails found to clear',
                    'deleted' => 0,
                ],
                statusCode: 200
            );
        } catch (\Exception $e) {
            return new JSONResponse(
                data: [
                    'success' => false,
                    'error'   => 'Failed to clear audit trails: '.$e->getMessage(),
                ],
                statusCode: 500
            );
        }//end try
    }//end clearAll()

    /**
     * Verify the integrity of the audit trail hash chain.
     *
     * Admin-only at the framework level (no @NoAdminRequired): validates the
     * tenant-wide audit hash chain across all registers/schemas — a GDPR
     * chain-of-trust management surface, like export()/clearAll(). Body
     * `requireAdmin()` is defence-in-depth.
     *
     * @NoCSRFRequired
     *
     * @return JSONResponse Verification result with valid/invalid status
     *
     * @spec openspec/specs/audit-trail-immutable/spec.md#requirement-the-audit-trail-must-use-cryptographic-hash-chaining
     * @spec openspec/specs/audit-hash-chain/spec.md
     */
    public function verify(): JSONResponse
    {
        $denial = $this->requireAdmin();
        if ($denial !== null) {
            return $denial;
        }

        $from = $this->request->getParam('from');
        $to   = $this->request->getParam('to');

        $fromInt = null;
        if ($from !== null) {
            $fromInt = (int) $from;
        }

        $toInt = null;
        if ($to !== null) {
            $toInt = (int) $to;
        }

        try {
            $result = $this->auditHashService->verifyChain($fromInt, $toInt);
            return new JSONResponse(data: $result);
        } catch (\Exception $e) {
            return new JSONResponse(
                data: ['error' => 'Verification failed: '.$e->getMessage()],
                statusCode: 500
            );
        }
    }//end verify()

    /**
     * Get verwerkingsregister (processing register) overview.
     *
     * Returns distinct processing activities from the audit trail with counts
     * and date ranges, for GDPR Art 30 compliance.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @return JSONResponse List of processing activities
     *
     * @SuppressWarnings(PHPMD.ExcessiveMethodLength) Large OCP/NC annotation block
     *   (@NoAdminRequired, @NoCSRFRequired, @psalm-return) inflates the reported line
     *   count; the actual executable body is 12 lines.
     *
     * @spec openspec/specs/audit-trail-immutable/spec.md#requirement-the-audit-trail-must-use-cryptographic-hash-chaining
     * @spec openspec/specs/verwerkingsregister-api/spec.md
     */
    public function verwerkingsregister(): JSONResponse
    {
        $organisationId = $this->request->getParam('organisationId');

        try {
            $results = $this->auditTrailMapper->getProcessingActivities($organisationId);
            return new JSONResponse(data: $results);
        } catch (\Exception $e) {
            return new JSONResponse(
                data: ['error' => 'Failed to retrieve verwerkingsregister: '.$e->getMessage()],
                statusCode: 500
            );
        }
    }//end verwerkingsregister()

    /**
     * Handle a data subject access request (inzageverzoek).
     *
     * Searches audit trail entries by identifier in the changed JSON field,
     * grouped by schema.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @return JSONResponse Matching audit trail entries grouped by schema
     *
     * @spec openspec/specs/audit-trail-immutable/spec.md#requirement-the-audit-trail-must-use-cryptographic-hash-chaining
     * @spec openspec/specs/verwerkingsregister-api/spec.md
     */
    public function inzageverzoek(): JSONResponse
    {
        $identifier = $this->request->getParam('identifier');

        if ($identifier === null || $identifier === '') {
            return new JSONResponse(
                data: ['error' => 'identifier parameter is required'],
                statusCode: 400
            );
        }

        try {
            $results = $this->auditTrailMapper->findByIdentifier($identifier);
            return new JSONResponse(data: $results);
        } catch (\Exception $e) {
            return new JSONResponse(
                data: ['error' => 'Inzageverzoek failed: '.$e->getMessage()],
                statusCode: 500
            );
        }
    }//end inzageverzoek()
}//end class
