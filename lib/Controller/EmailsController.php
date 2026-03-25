<?php

/**
 * EmailsController for mail-sidebar reverse-lookup and quick-link endpoints.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Controller;

use OCA\OpenRegister\Service\EmailService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Controller for email-object link operations.
 *
 * Provides reverse-lookup (email -> objects) and quick-link endpoints
 * for the Mail app sidebar integration.
 *
 * @psalm-suppress UnusedClass
 */
class EmailsController extends Controller
{
    /**
     * Constructor.
     *
     * @param string          $appName      The app name.
     * @param IRequest        $request      The request.
     * @param EmailService    $emailService The email service.
     * @param IUserSession    $userSession  The user session.
     * @param LoggerInterface $logger       The logger.
     */
    public function __construct(
        string $appName,
        IRequest $request,
        private readonly EmailService $emailService,
        private readonly IUserSession $userSession,
        private readonly LoggerInterface $logger
    ) {
        parent::__construct($appName, $request);
    }//end __construct()

    /**
     * Find objects linked to a specific email message.
     *
     * @NoAdminRequired
     *
     * @param int $accountId The mail account ID.
     * @param int $messageId The mail message ID.
     *
     * @return JSONResponse The response with linked objects.
     */
    public function byMessage(int $accountId, int $messageId): JSONResponse
    {
        if ($accountId <= 0 || $messageId <= 0) {
            return new JSONResponse(
                ['error' => 'Invalid account ID or message ID'],
                Http::STATUS_BAD_REQUEST
            );
        }

        try {
            $result = $this->emailService->findByMessageId($accountId, $messageId);
            return new JSONResponse($result);
        } catch (\Exception $e) {
            $this->logger->error(
                'Failed to find objects by message: {error}',
                ['error' => $e->getMessage(), 'exception' => $e]
            );
            return new JSONResponse(
                ['error' => 'Internal server error'],
                Http::STATUS_INTERNAL_SERVER_ERROR
            );
        }
    }//end byMessage()

    /**
     * Find objects linked to emails from a specific sender.
     *
     * @NoAdminRequired
     *
     * @return JSONResponse The response with discovered objects.
     */
    public function bySender(): JSONResponse
    {
        $sender = $this->request->getParam('sender');

        if (empty($sender) === true) {
            return new JSONResponse(
                ['error' => 'The sender parameter is required'],
                Http::STATUS_BAD_REQUEST
            );
        }

        if (filter_var($sender, FILTER_VALIDATE_EMAIL) === false) {
            return new JSONResponse(
                ['error' => 'Invalid email address format'],
                Http::STATUS_BAD_REQUEST
            );
        }

        try {
            $result = $this->emailService->findObjectsBySender($sender);
            return new JSONResponse($result);
        } catch (\Exception $e) {
            $this->logger->error(
                'Failed to find objects by sender: {error}',
                ['error' => $e->getMessage(), 'exception' => $e]
            );
            return new JSONResponse(
                ['error' => 'Internal server error'],
                Http::STATUS_INTERNAL_SERVER_ERROR
            );
        }
    }//end bySender()

    /**
     * Create a quick link between an email and an object.
     *
     * @NoAdminRequired
     *
     * @return JSONResponse The response with the created link.
     */
    public function quickLink(): JSONResponse
    {
        $params = $this->request->getParams();

        // Validate required fields.
        $required = ['mailAccountId', 'mailMessageId', 'objectUuid', 'registerId'];
        foreach ($required as $field) {
            if (empty($params[$field]) === true) {
                return new JSONResponse(
                    ['error' => "Missing required field: {$field}"],
                    Http::STATUS_BAD_REQUEST
                );
            }
        }

        // Set the linkedBy to the current user.
        $user = $this->userSession->getUser();
        if ($user !== null) {
            $params['linkedBy'] = $user->getUID();
        }

        try {
            $result = $this->emailService->quickLink($params);
            return new JSONResponse($result, Http::STATUS_CREATED);
        } catch (\RuntimeException $e) {
            $code = $e->getCode();
            if ($code === 409) {
                return new JSONResponse(
                    ['error' => $e->getMessage()],
                    Http::STATUS_CONFLICT
                );
            }
            if ($code === 404) {
                return new JSONResponse(
                    ['error' => $e->getMessage()],
                    Http::STATUS_NOT_FOUND
                );
            }

            $this->logger->error(
                'Failed to create quick link: {error}',
                ['error' => $e->getMessage(), 'exception' => $e]
            );
            return new JSONResponse(
                ['error' => 'Internal server error'],
                Http::STATUS_INTERNAL_SERVER_ERROR
            );
        }
    }//end quickLink()

    /**
     * Delete an email link by ID.
     *
     * @NoAdminRequired
     *
     * @param int $linkId The link ID to delete.
     *
     * @return JSONResponse The response.
     */
    public function deleteLink(int $linkId): JSONResponse
    {
        if ($linkId <= 0) {
            return new JSONResponse(
                ['error' => 'Invalid link ID'],
                Http::STATUS_BAD_REQUEST
            );
        }

        try {
            $this->emailService->deleteLink($linkId);
            return new JSONResponse(['status' => 'deleted']);
        } catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
            return new JSONResponse(
                ['error' => 'Link not found'],
                Http::STATUS_NOT_FOUND
            );
        } catch (\Exception $e) {
            $this->logger->error(
                'Failed to delete email link: {error}',
                ['error' => $e->getMessage(), 'exception' => $e]
            );
            return new JSONResponse(
                ['error' => 'Internal server error'],
                Http::STATUS_INTERNAL_SERVER_ERROR
            );
        }
    }//end deleteLink()
}//end class
