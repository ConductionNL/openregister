<?php

/**
 * OpenRegister Health Controller
 *
 * Exposes health check endpoint for container orchestration and monitoring.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2025 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Controller;

use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IDBConnection;
use OCP\IRequest;
use OCP\App\IAppManager;
use Psr\Log\LoggerInterface;

/**
 * Controller for health check endpoints.
 *
 * @psalm-suppress UnusedClass
 */
class HealthController extends Controller
{
    /**
     * Constructor.
     *
     * @param string          $appName    The application name
     * @param IRequest        $request    The HTTP request
     * @param IDBConnection   $db         Database connection
     * @param IAppManager     $appManager App manager
     * @param LoggerInterface $logger     Logger
     */
    public function __construct(
        string $appName,
        IRequest $request,
        private IDBConnection $db,
        private IAppManager $appManager,
        private LoggerInterface $logger,
    ) {
        parent::__construct(appName: $appName, request: $request);
    }//end __construct()

    /**
     * Health check endpoint.
     *
     * Returns JSON with status and individual check results.
     *
     * @NoCSRFRequired
     *
     * @return JSONResponse Health status
     */
    public function index(): JSONResponse
    {
        $checks = [];
        $status = 'ok';

        // Check database connectivity.
        $checks['database'] = $this->checkDatabase();
        if ($checks['database'] !== 'ok') {
            $status = 'error';
        }

        // Check filesystem.
        $checks['filesystem'] = $this->checkFilesystem();
        if ($checks['filesystem'] !== 'ok') {
            $status = ($status === 'error') ? 'error' : 'degraded';
        }

        $httpStatus = ($status === 'ok') ? Http::STATUS_OK : Http::STATUS_SERVICE_UNAVAILABLE;

        return new JSONResponse(
            [
                'status'  => $status,
                'version' => $this->getAppVersion(),
                'checks'  => $checks,
            ],
            $httpStatus
        );
    }//end index()

    /**
     * Check database connectivity.
     *
     * @return string 'ok' or error message
     */
    private function checkDatabase(): string
    {
        try {
            $qb = $this->db->getQueryBuilder();
            $qb->select($qb->createFunction('1'));
            $result = $qb->executeQuery();
            $result->closeCursor();

            return 'ok';
        } catch (\Exception $e) {
            $this->logger->error('[HealthController] Database check failed', ['error' => $e->getMessage()]);
            return 'failed: ' . $e->getMessage();
        }
    }//end checkDatabase()

    /**
     * Check filesystem access.
     *
     * @return string 'ok' or error message
     */
    private function checkFilesystem(): string
    {
        try {
            $tmpFile = sys_get_temp_dir() . '/openregister_health_' . getmypid();
            $written = file_put_contents($tmpFile, 'health');
            if ($written === false) {
                return 'failed: cannot write to temp directory';
            }

            unlink($tmpFile);

            return 'ok';
        } catch (\Exception $e) {
            return 'failed: ' . $e->getMessage();
        }
    }//end checkFilesystem()

    /**
     * Get the app version.
     *
     * @return string The app version
     */
    private function getAppVersion(): string
    {
        try {
            return $this->appManager->getAppVersion('openregister');
        } catch (\Exception $e) {
            return 'unknown';
        }
    }//end getAppVersion()
}//end class
