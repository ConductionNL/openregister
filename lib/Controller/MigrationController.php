<?php

declare(strict_types=1);

namespace OCA\OpenRegister\Controller;

use OCA\OpenRegister\Service\MigrationService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * Controller for storage migration between blob and magic tables.
 */
class MigrationController extends Controller
{
    public function __construct(
        string $appName,
        IRequest $request,
        private readonly MigrationService $migrationService,
    ) {
        parent::__construct(appName: $appName, request: $request);
    }//end __construct()

    /**
     * Get storage status for a register/schema combination.
     *
     * @NoCSRFRequired
     *
     * @param string $register Register ID or slug.
     * @param string $schema   Schema ID or slug.
     *
     * @return JSONResponse Storage status.
     */
    public function status(string $register, string $schema): JSONResponse
    {
        try {
            $resolved = $this->migrationService->resolveRegisterAndSchema(
                registerId: $register,
                schemaId: $schema
            );

            $status = $this->migrationService->getStorageStatus(
                register: $resolved['register'],
                schema: $resolved['schema']
            );

            return new JSONResponse(data: $status);
        } catch (\Exception $e) {
            return new JSONResponse(
                data: ['error' => $e->getMessage()],
                statusCode: 500
            );
        }
    }//end status()

    /**
     * Trigger a migration between blob storage and magic tables.
     *
     * @NoCSRFRequired
     *
     * Expected body: {register, schema, direction, batchSize?, dryRun?}
     *
     * @return JSONResponse Migration report.
     */
    public function migrate(): JSONResponse
    {
        try {
            $registerParam = $this->request->getParam('register');
            $schemaParam   = $this->request->getParam('schema');
            $direction     = $this->request->getParam('direction');
            $batchSize     = (int) ($this->request->getParam('batchSize', 100));
            $dryRun        = filter_var(
                $this->request->getParam('dryRun', false),
                FILTER_VALIDATE_BOOLEAN
            );

            if (empty($registerParam) === true || empty($schemaParam) === true) {
                return new JSONResponse(
                    data: ['error' => 'register and schema parameters are required'],
                    statusCode: 400
                );
            }

            if (in_array($direction, ['to-magic', 'to-blob'], true) === false) {
                return new JSONResponse(
                    data: ['error' => 'direction must be "to-magic" or "to-blob"'],
                    statusCode: 400
                );
            }

            $resolved = $this->migrationService->resolveRegisterAndSchema(
                registerId: $registerParam,
                schemaId: $schemaParam
            );

            if ($direction === 'to-magic') {
                $report = $this->migrationService->migrateToMagicTable(
                    register: $resolved['register'],
                    schema: $resolved['schema'],
                    batchSize: $batchSize,
                    dryRun: $dryRun
                );
            } else {
                $report = $this->migrationService->migrateToBlobStorage(
                    register: $resolved['register'],
                    schema: $resolved['schema'],
                    batchSize: $batchSize,
                    dryRun: $dryRun
                );
            }

            return new JSONResponse(data: $report);
        } catch (\Exception $e) {
            return new JSONResponse(
                data: ['error' => $e->getMessage()],
                statusCode: 500
            );
        }//end try
    }//end migrate()
}//end class
