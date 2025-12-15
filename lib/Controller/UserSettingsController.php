<?php
/**
 * OpenRegister User Settings Controller
 *
 * This file contains the controller class for handling user-specific settings,
 * particularly GitHub token management.
 *
 * @category  Controller
 * @package   OCA\OpenRegister\Controller
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://www.OpenRegister.app
 */

namespace OCA\OpenRegister\Controller;

use Exception;
use OCA\OpenRegister\Service\Configuration\GitHubHandler;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Class UserSettingsController
 *
 * Controller for managing user-specific settings (GitHub tokens, etc.).
 *
 * @package OCA\OpenRegister\Controller
 *
 * @psalm-suppress UnusedClass
 */
class UserSettingsController extends Controller
{

    /**
     * GitHub service instance.
     *
     * @var GitHubHandler The GitHub service instance.
     */
    private GitHubHandler $gitHubService;

    /**
     * User session instance.
     *
     * @var IUserSession The user session instance.
     */
    private IUserSession $userSession;

    /**
     * Logger instance.
     *
     * @var LoggerInterface The logger instance.
     */
    private LoggerInterface $logger;


    /**
     * Constructor
     *
     * @param string          $appName       The app name
     * @param IRequest        $request       The request object
     * @param GitHubHandler   $gitHubService GitHub service
     * @param IUserSession    $userSession   User session
     * @param LoggerInterface $logger        Logger
     */
    public function __construct(
        string $appName,
        IRequest $request,
        GitHubHandler $gitHubService,
        IUserSession $userSession,
        LoggerInterface $logger
    ) {
        parent::__construct(appName: $appName, request: $request);

        $this->gitHubService = $gitHubService;
        $this->userSession   = $userSession;
        $this->logger        = $logger;

    }//end __construct()


    /**
     * Get current GitHub token status (without exposing the token).
     *
     * @NoAdminRequired
     *
     * @return JSONResponse JSON response containing GitHub token status
     *
     * @NoCSRFRequired
     *
     * @psalm-return JSONResponse<200|401|500, array{error?: 'Failed to get token status'|'User not authenticated', hasToken?: bool, isValid?: bool, message?: 'No GitHub token configured'|'Token is invalid or expired'|'Token is valid'}, array<never, never>>
     */
    public function getGitHubTokenStatus(): JSONResponse
    {
        try {
            $user = $this->userSession->getUser();
            if ($user === null) {
                return new JSONResponse(data: ['error' => 'User not authenticated'], statusCode: 401);
            }

            $token = $this->gitHubService->getUserToken($user->getUID());

            if ($token === null) {
                return new JSONResponse(
                        data: [
                            'hasToken' => false,
                            'isValid'  => false,
                            'message'  => 'No GitHub token configured',
                        ],
                        statusCode: 200
                        );
            }

            // Validate the token.
            $this->gitHubService->setUserToken(token: $token, userId: $user->getUID());
            $isValid = $this->gitHubService->validateToken(userId: $user->getUID());

            return new JSONResponse(
                    data: [
                        'hasToken' => true,
                        'isValid'  => $isValid,
                        'message'  => $this->getTokenValidationMessage($isValid),
                    ],
                    statusCode: 200
                    );
        } catch (Exception $e) {
            $this->logger->error('Failed to get GitHub token status: '.$e->getMessage());

            return new JSONResponse(data: ['error' => 'Failed to get token status'], statusCode: 500);
        }//end try

    }//end getGitHubTokenStatus()


    /**
     * Set GitHub personal access token for the current user.
     *
     * @NoAdminRequired
     *
     * @return JSONResponse JSON response containing token save result
     *
     * @NoCSRFRequired
     *
     * @psalm-return JSONResponse<200|400|401|403|404|500, array{error?: string, success?: true, message?: 'GitHub token saved successfully'}, array<never, never>>
     */
    public function setGitHubToken(): JSONResponse
    {
        try {
            $user = $this->userSession->getUser();
            if ($user === null) {
                return new JSONResponse(data: ['error' => 'User not authenticated'], statusCode: 401);
            }

            $data  = $this->request->getParams();
            $token = $data['token'] ?? null;

            if ($token === null || trim($token) === '') {
                return new JSONResponse(data: ['error' => 'Token is required'], statusCode: 400);
            }

            // Validate the token before saving.
            $this->gitHubService->setUserToken(token: $token, userId: $user->getUID());
            if ($this->gitHubService->validateToken(userId: $user->getUID()) === false) {
                return new JSONResponse(data: ['error' => 'Invalid GitHub token'], statusCode: 400);
            }

            // Save the token (it's already saved by setUserToken).
            $this->logger->info(message: "GitHub token set for user: {$user->getUID()}");

            return new JSONResponse(
                    data: [
                        'success' => true,
                        'message' => 'GitHub token saved successfully',
                    ],
                    statusCode: 200
                    );
        } catch (Exception $e) {
            $this->logger->error(message: 'Failed to set GitHub token: '.$e->getMessage());

            return new JSONResponse(data: ['error' => 'Failed to save token: '.$e->getMessage()], statusCode: 500);
        }//end try

    }//end setGitHubToken()


    /**
     * Remove GitHub personal access token for the current user.
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     *
     * @return JSONResponse Success or error message
     *
     * @psalm-return JSONResponse<
     *     200|401|500,
     *     array{
     *         error?: 'Failed to remove token'|'User not authenticated',
     *         success?: true,
     *         message?: 'GitHub token removed successfully'
     *     },
     *     array<never, never>
     * >
     */
    public function removeGitHubToken(): JSONResponse
    {
        try {
            $user = $this->userSession->getUser();
            if ($user === null) {
                return new JSONResponse(data: ['error' => 'User not authenticated'], statusCode: 401);
            }

            // Clear the token.
            $this->gitHubService->setUserToken(token: null, userId: $user->getUID());

            $this->logger->info(message: "GitHub token removed for user: {$user->getUID()}");

            return new JSONResponse(
                    data: [
                        'success' => true,
                        'message' => 'GitHub token removed successfully',
                    ],
                    statusCode: 200
                    );
        } catch (Exception $e) {
            $this->logger->error(message: 'Failed to remove GitHub token: '.$e->getMessage());

            return new JSONResponse(data: ['error' => 'Failed to remove token'], statusCode: 500);
        }//end try

    }//end removeGitHubToken()


    /**
     * Get token validation message
     *
     * @param bool $isValid Whether token is valid
     *
     * @return string Validation message
     *
     * @psalm-return 'Token is invalid or expired'|'Token is valid'
     */
    private function getTokenValidationMessage(bool $isValid): string
    {
        if ($isValid === true) {
            return 'Token is valid';
        }

        return 'Token is invalid or expired';

    }//end getTokenValidationMessage()


}//end class
