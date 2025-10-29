<?php

namespace OCA\OpenRegister\Controller;

use OCA\OpenRegister\Service\ChatService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * ChatController
 *
 * Controller for handling AI chat API endpoints
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller
 * @author   Conduction <info@conduction.nl>
 * @license  EUPL-1.2 https://opensource.org/licenses/EUPL-1.2
 * @link     https://www.conduction.nl
 */
class ChatController extends Controller
{
	/**
	 * @var ChatService Chat service
	 */
	private ChatService $chatService;

	/**
	 * @var LoggerInterface Logger
	 */
	private LoggerInterface $logger;

	/**
	 * @var string User ID
	 */
	private string $userId;

	/**
	 * Constructor
	 *
	 * @param string          $appName     Application name
	 * @param IRequest        $request     Request object
	 * @param ChatService     $chatService Chat service
	 * @param LoggerInterface $logger      Logger
	 * @param string          $userId      User ID
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		ChatService $chatService,
		LoggerInterface $logger,
		string $userId
	) {
		parent::__construct($appName, $request);
		$this->chatService = $chatService;
		$this->logger = $logger;
		$this->userId = $userId;
	}

    /**
	 * This returns the template of the main app's page
	 * It adds some data to the template (app version)
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @return TemplateResponse
	 */
	public function page(): TemplateResponse
	{
        return new TemplateResponse(
            //Application::APP_ID,
            'openregister',
            'index',
            []
        );
	}

	/**
	 * Send a chat message and get AI response
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @return JSONResponse
	 */
	public function sendMessage(): JSONResponse {
		try {
			// Get request data
			$data = $this->request->getParams();
			$message = $data['message'] ?? '';
			$searchMode = $data['searchMode'] ?? 'hybrid';
			$numSources = (int)($data['numSources'] ?? 5);
			$includeFiles = (bool)($data['includeFiles'] ?? true);
			$includeObjects = (bool)($data['includeObjects'] ?? true);

			// Validate message
			if (empty($message)) {
				return new JSONResponse(
					['error' => 'Message is required'],
					400
				);
			}

			// Validate search mode
			if (!in_array($searchMode, ['hybrid', 'semantic', 'keyword'])) {
				return new JSONResponse(
					['error' => 'Invalid search mode. Must be one of: hybrid, semantic, keyword'],
					400
				);
			}

			// Validate num sources
			if ($numSources < 1 || $numSources > 10) {
				return new JSONResponse(
					['error' => 'Number of sources must be between 1 and 10'],
					400
				);
			}

			$this->logger->info('[ChatController] Sending message', [
				'userId' => $this->userId,
				'searchMode' => $searchMode,
				'numSources' => $numSources,
			]);

			// Process message
			$result = $this->chatService->processMessage(
				$this->userId,
				$message,
				$searchMode,
				$numSources,
				$includeFiles,
				$includeObjects
			);

			return new JSONResponse($result, 200);
		} catch (\Exception $e) {
			$this->logger->error('[ChatController] Failed to send message', [
				'error' => $e->getMessage(),
				'trace' => $e->getTraceAsString(),
			]);

			return new JSONResponse(
				['error' => $e->getMessage()],
				500
			);
		}
	}

	/**
	 * Get conversation history
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @return JSONResponse
	 */
	public function getHistory(): JSONResponse {
		try {
			$limit = (int)($this->request->getParam('limit', 50));
			$offset = (int)($this->request->getParam('offset', 0));

			$this->logger->info('[ChatController] Getting history', [
				'userId' => $this->userId,
				'limit' => $limit,
				'offset' => $offset,
			]);

			$messages = $this->chatService->getConversationHistory(
				$this->userId,
				$limit,
				$offset
			);

			return new JSONResponse([
				'messages' => $messages,
				'count' => count($messages),
			], 200);
		} catch (\Exception $e) {
			$this->logger->error('[ChatController] Failed to get history', [
				'error' => $e->getMessage(),
			]);

			return new JSONResponse(
				['error' => $e->getMessage()],
				500
			);
		}
	}

	/**
	 * Clear conversation history
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @return JSONResponse
	 */
	public function clearHistory(): JSONResponse {
		try {
			$this->logger->info('[ChatController] Clearing history', [
				'userId' => $this->userId,
			]);

			$count = $this->chatService->clearConversationHistory($this->userId);

			return new JSONResponse([
				'success' => true,
				'deleted' => $count,
			], 200);
		} catch (\Exception $e) {
			$this->logger->error('[ChatController] Failed to clear history', [
				'error' => $e->getMessage(),
			]);

			return new JSONResponse(
				['error' => $e->getMessage()],
				500
			);
		}
	}

	/**
	 * Store feedback for a message
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @return JSONResponse
	 */
	public function sendFeedback(): JSONResponse {
		try {
			$data = $this->request->getParams();
			$messageId = (int)($data['messageId'] ?? 0);
			$feedback = $data['feedback'] ?? '';

			// Validate feedback
			if (!in_array($feedback, ['positive', 'negative', null], true)) {
				return new JSONResponse(
					['error' => 'Invalid feedback. Must be one of: positive, negative, null'],
					400
				);
			}

			$this->logger->info('[ChatController] Storing feedback', [
				'userId' => $this->userId,
				'messageId' => $messageId,
				'feedback' => $feedback,
			]);

			$this->chatService->storeFeedback($this->userId, $messageId, $feedback);

			return new JSONResponse([
				'success' => true,
			], 200);
		} catch (\Exception $e) {
			$this->logger->error('[ChatController] Failed to store feedback', [
				'error' => $e->getMessage(),
			]);

			return new JSONResponse(
				['error' => $e->getMessage()],
				500
			);
		}
	}
}

