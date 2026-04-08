<?php

/**
 * EmailsController
 *
 * REST controller for email relation operations on OpenRegister objects.
 *
 * @category  Controller
 * @package   OCA\OpenRegister\Controller
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git-id>
 * @link      https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Controller;

use Exception;
use OCA\OpenRegister\Service\EmailService;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * EmailsController handles email relation operations for objects in registers.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller
 */
class EmailsController extends Controller
{

    /**
     * Email service.
     *
     * @var EmailService
     */
    private readonly EmailService $emailService;

    /**
     * Object service for object validation.
     *
     * @var ObjectService
     */
    private readonly ObjectService $objectService;

    /**
     * User session.
     *
     * @var \OCP\IUserSession
     */
    private readonly \OCP\IUserSession $userSession;

    /**
     * Logger.
     *
     * @var \Psr\Log\LoggerInterface
     */
    private readonly \Psr\Log\LoggerInterface $logger;

    /**
     * Constructor.
     *
     * @param string                   $appName       Application name
     * @param IRequest                 $request       HTTP request object
     * @param EmailService             $emailService  Email service
     * @param ObjectService            $objectService Object service
     * @param \OCP\IUserSession        $userSession   User session
     * @param \Psr\Log\LoggerInterface $logger        Logger
     *
     * @return void
     */
    public function __construct(
        string $appName,
        IRequest $request,
        EmailService $emailService,
        ObjectService $objectService,
        \OCP\IUserSession $userSession,
        \Psr\Log\LoggerInterface $logger
    ) {
        parent::__construct(appName: $appName, request: $request);

        $this->emailService  = $emailService;
        $this->objectService = $objectService;
        $this->userSession   = $userSession;
        $this->logger        = $logger;
    }//end __construct()

    /**
     * List all email links for a specific object.
     *
     * @param string $register The register slug or identifier
     * @param string $schema   The schema slug or identifier
     * @param string $id       The ID of the object
     *
     * @return JSONResponse JSON response with email links
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function index(
        string $register,
        string $schema,
        string $id
    ): JSONResponse {
        if ($this->emailService->isMailAvailable() === false) {
            return new JSONResponse(
                ['error' => 'Nextcloud Mail app is not installed', 'code' => 'APP_NOT_AVAILABLE'],
                501
            );
        }

        try {
            $object = $this->validateObject(object: $register, schema: $schema, schemaObject: $id);
            if ($object === null) {
                return new JSONResponse(['error' => 'Object not found'], 404);
            }

            $params = $this->request->getParams();
            $limit  = isset($params['limit']) === true ? (int) $params['limit'] : null;
            $offset = isset($params['offset']) === true ? (int) $params['offset'] : null;

            $result = $this->emailService->getEmailsForObject($object->getUuid(), $limit, $offset);

            return new JSONResponse($result);
        } catch (DoesNotExistException $e) {
            return new JSONResponse(['error' => 'Object not found'], 404);
        } catch (Exception $e) {
            return new JSONResponse(['error' => $e->getMessage()], 500);
        }//end try
    }//end index()

    /**
     * Link an email to a specific object.
     *
     * @param string $register The register slug or identifier
     * @param string $schema   The schema slug or identifier
     * @param string $id       The ID of the object
     *
     * @return JSONResponse JSON response with the created email link
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function create(
        string $register,
        string $schema,
        string $id
    ): JSONResponse {
        if ($this->emailService->isMailAvailable() === false) {
            return new JSONResponse(
                ['error' => 'Nextcloud Mail app is not installed', 'code' => 'APP_NOT_AVAILABLE'],
                501
            );
        }

        try {
            $object = $this->validateObject(object: $register, schema: $schema, schemaObject: $id);
            if ($object === null) {
                return new JSONResponse(['error' => 'Object not found'], 404);
            }

            $data = $this->request->getParams();

            if (empty($data['mailAccountId']) === true || empty($data['mailMessageId']) === true) {
                return new JSONResponse(
                    ['error' => 'mailAccountId and mailMessageId are required'],
                    400
                );
            }

            $link = $this->emailService->linkEmail(
                $object->getUuid(),
                (int) $object->getRegister(),
                (int) $data['mailAccountId'],
                (int) $data['mailMessageId']
            );

            return new JSONResponse($link, 201);
        } catch (DoesNotExistException $e) {
            return new JSONResponse(['error' => 'Object not found'], 404);
        } catch (Exception $e) {
            $code = $e->getCode();
            if ($code === 409) {
                return new JSONResponse(['error' => $e->getMessage()], 409);
            }

            if ($code === 404) {
                return new JSONResponse(['error' => $e->getMessage()], 404);
            }

            return new JSONResponse(['error' => $e->getMessage()], 400);
        }//end try
    }//end create()

    /**
     * Remove an email link from an object.
     *
     * @param string $register The register slug or identifier
     * @param string $schema   The schema slug or identifier
     * @param string $id       The ID of the object
     * @param string $emailId  The email link ID
     *
     * @return JSONResponse JSON response confirming deletion
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function destroy(
        string $register,
        string $schema,
        string $id,
        string $emailId
    ): JSONResponse {
        if ($this->emailService->isMailAvailable() === false) {
            return new JSONResponse(
                ['error' => 'Nextcloud Mail app is not installed', 'code' => 'APP_NOT_AVAILABLE'],
                501
            );
        }

        try {
            $object = $this->validateObject(object: $register, schema: $schema, schemaObject: $id);
            if ($object === null) {
                return new JSONResponse(['error' => 'Object not found'], 404);
            }

            $this->emailService->unlinkEmail($object->getUuid(), $emailId);

            return new JSONResponse(['success' => true]);
        } catch (DoesNotExistException $e) {
            return new JSONResponse(['error' => 'Object not found'], 404);
        } catch (Exception $e) {
            $code = $e->getCode();
            if ($code === 404) {
                return new JSONResponse(['error' => $e->getMessage()], 404);
            }

            return new JSONResponse(['error' => $e->getMessage()], 400);
        }//end try
    }//end destroy()

    /**
     * Search email links by sender.
     *
     * @return JSONResponse JSON response with matching email links
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function search(): JSONResponse
    {
        if ($this->emailService->isMailAvailable() === false) {
            return new JSONResponse(
                ['error' => 'Nextcloud Mail app is not installed', 'code' => 'APP_NOT_AVAILABLE'],
                501
            );
        }

        try {
            $params = $this->request->getParams();
            $sender = $params['sender'] ?? null;

            if (empty($sender) === true) {
                return new JSONResponse(['error' => 'sender parameter is required'], 400);
            }

            $results = $this->emailService->searchBySender($sender);

            return new JSONResponse(['results' => $results, 'total' => count($results)]);
        } catch (Exception $e) {
            return new JSONResponse(['error' => $e->getMessage()], 500);
        }
    }//end search()

    /**
     * Validate that the object exists and return it.
     *
     * @param string $register The register slug or identifier
     * @param string $schema   The schema slug or identifier
     * @param string $id       The object ID
     *
     * @return \OCA\OpenRegister\Db\ObjectEntity|null The object or null
     */
    private function validateObject(
        string $register,
        string $schema,
        string $id
    ): ?\OCA\OpenRegister\Db\ObjectEntity {
        $this->objectService->setSchema($schema);
        $this->objectService->setRegister($register);
        $this->objectService->setObject($id);

        return $this->objectService->getObject();
    }//end validateObject()

    /**
     * Find objects linked to emails from a specific sender.
     *
     * @return JSONResponse
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function bySender(): JSONResponse
    {
        $sender = $this->request->getParam('sender');

        if (empty($sender) === true) {
            return new JSONResponse(['error' => 'The sender parameter is required'], 400);
        }

        try {
            $result = $this->emailService->searchBySender($sender);
            return new JSONResponse($result);
        } catch (\Exception $e) {
            $this->logger->error('Failed to find objects by sender: {error}', ['error' => $e->getMessage()]);
            return new JSONResponse(['error' => 'Internal server error'], 500);
        }
    }//end bySender()

}//end class
