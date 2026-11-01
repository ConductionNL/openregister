<?php

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Controller;

use Error;
use OCA\OpenRegister\Controller\ChatController;
use OCA\OpenRegister\Db\AgentMapper;
use OCA\OpenRegister\Db\Conversation;
use OCA\OpenRegister\Db\ConversationMapper;
use OCA\OpenRegister\Db\Feedback;
use OCA\OpenRegister\Db\FeedbackMapper;
use OCA\OpenRegister\Db\Message;
use OCA\OpenRegister\Db\MessageMapper;
use OCA\OpenRegister\Db\Organisation;
use OCA\OpenRegister\Service\ChatService;
use OCA\OpenRegister\Service\OrganisationService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IDBConnection;
use OCP\IL10N;
use OCP\IRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Golden test for ChatController::sendFeedback().
 *
 * The acting user is "alice". Every request also carries a forged userId,
 * requestedBy and owner ("mallory") through getParam() and getParams(), and the
 * feedback lookup only answers for the acting user. Every case records each
 * collaborator call and the response, and compares the recording with
 * fixtures/chat-feedback-golden.json. Log line numbers, file paths and traces
 * are left out. Set UPDATE_GOLDEN=1 to rewrite the fixture.
 *
 * @covers \OCA\OpenRegister\Controller\ChatController
 * @uses   \OCA\OpenRegister\Db\Conversation
 * @uses   \OCA\OpenRegister\Db\Feedback
 * @uses   \OCA\OpenRegister\Db\Message
 * @uses   \OCA\OpenRegister\Db\Organisation
 */
class ChatControllerFeedbackGoldenTest extends TestCase {
	private const FIXTURE = __DIR__ . '/fixtures/chat-feedback-golden.json';

	private const ACTING_USER = 'alice';

	private const FORGED = ['userId' => 'mallory', 'requestedBy' => 'mallory', 'owner' => 'mallory'];

	/** @var list<array<string, mixed>> */
	private array $calls = [];

	/** @var array<string, mixed>|null */
	private static ?array $recorded = null;

	/** @var array<string, mixed> */
	private static array $golden = [];

	/**
	 * @return array<string, array{0: array<string, mixed>}>
	 */
	public static function cases(): array {
		return [
			'invalid type' => [['type' => 'neutral']],
			'missing type' => [[]],
			'conversation of another user' => [['type' => 'positive', 'owner' => 'mallory']],
			'message of another conversation' => [['type' => 'positive', 'messageConversation' => 99]],
			'new positive feedback with a comment' => [['type' => 'positive', 'comment' => 'Helpful', 'organisation' => 'org-1', 'agentId' => 5]],
			'new negative feedback without a comment or organisation' => [['type' => 'negative']],
			'new feedback with an empty comment' => [['type' => 'negative', 'comment' => '', 'organisation' => 'org-1']],
			'existing feedback of the acting user' => [['type' => 'negative', 'comment' => 'Changed my mind', 'existing' => true, 'organisation' => 'org-1']],
			'conversation not found' => [['type' => 'positive', 'conversationThrows' => true]],
			'insert throws an error' => [['type' => 'positive', 'insertThrows' => true]],
		];
	}

	#[DataProvider('cases')]
	public function testSendFeedbackMatchesTheRecording(array $case): void {
		$this->calls = [];
		$params = self::FORGED;
		if (array_key_exists('type', $case) === true) {
			$params['type'] = $case['type'];
		}

		if (array_key_exists('comment', $case) === true) {
			$params['comment'] = $case['comment'];
		}

		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(function (string $key, $default = null) use ($params) {
			$this->calls[] = ['request.getParam' => [$key, $default]];
			return $params[$key] ?? $default;
		});
		$request->method('getParams')->willReturnCallback(function () use ($params) {
			$this->calls[] = ['request.getParams' => []];
			return $params;
		});

		$conversationMapper = $this->createMock(ConversationMapper::class);
		$conversationMapper->method('findByUuid')->willReturnCallback(function (string $uuid) use ($case) {
			$this->calls[] = ['conversationMapper.findByUuid' => [$uuid]];
			if (($case['conversationThrows'] ?? false) === true) {
				throw new DoesNotExistException('no conversation');
			}

			$conversation = new Conversation();
			$conversation->setId(3);
			$conversation->setUserId($case['owner'] ?? self::ACTING_USER);
			if (isset($case['agentId']) === true) {
				$conversation->setAgentId($case['agentId']);
			}

			return $conversation;
		});

		$messageMapper = $this->createMock(MessageMapper::class);
		$messageMapper->method('find')->willReturnCallback(function (int $id) use ($case) {
			$this->calls[] = ['messageMapper.find' => [$id]];
			$message = new Message();
			$message->setId($id);
			$message->setConversationId($case['messageConversation'] ?? 3);
			return $message;
		});

		$organisationService = $this->createMock(OrganisationService::class);
		$organisationService->method('getActiveOrganisation')->willReturnCallback(function () use ($case) {
			$this->calls[] = ['organisationService.getActiveOrganisation' => []];
			if (isset($case['organisation']) === false) {
				return null;
			}

			$organisation = new Organisation();
			$organisation->setUuid($case['organisation']);
			return $organisation;
		});

		$controller = new ChatController(
			'openregister',
			$request,
			$this->createMock(ChatService::class),
			$conversationMapper,
			$messageMapper,
			$this->feedbackMapper($case),
			$this->createMock(AgentMapper::class),
			$organisationService,
			$this->createMock(IDBConnection::class),
			$this->logger(),
			$this->l10n(),
			self::ACTING_USER
		);

		try {
			$response = $controller->sendFeedback('conv-uuid', 42);
			$outcome = ['status' => $response->getStatus(), 'data' => $response->getData()];
		} catch (\Throwable $e) {
			$outcome = ['thrown' => get_class($e), 'message' => $e->getMessage()];
		}

		$this->compare($this->dataName(), ['calls' => $this->calls] + $outcome);
	}

	/**
	 * @param array<string, mixed> $case
	 */
	private function feedbackMapper(array $case): FeedbackMapper {
		$mapper = $this->createMock(FeedbackMapper::class);
		$mapper->method('findByMessage')->willReturnCallback(function (int $messageId, string $userId) use ($case) {
			$this->calls[] = ['feedbackMapper.findByMessage' => [$messageId, $userId]];
			if (($case['existing'] ?? false) === false || $userId !== self::ACTING_USER || $messageId !== 42) {
				return null;
			}

			$feedback = new Feedback();
			$feedback->setId(8);
			$feedback->setMessageId(42);
			$feedback->setConversationId(3);
			$feedback->setUserId(self::ACTING_USER);
			$feedback->setType('positive');
			$feedback->setComment('First thought');
			$feedback->resetUpdatedFields();
			return $feedback;
		});
		$mapper->method('update')->willReturnCallback(function (Feedback $feedback) {
			$this->calls[] = ['feedbackMapper.update' => [array_keys($feedback->getUpdatedFields()), $feedback->jsonSerialize()]];
			return $feedback;
		});
		$mapper->method('insert')->willReturnCallback(function (Feedback $feedback) use ($case) {
			$this->calls[] = ['feedbackMapper.insert' => [array_keys($feedback->getUpdatedFields()), $feedback->jsonSerialize()]];
			if (($case['insertThrows'] ?? false) === true) {
				throw new Error('insert failed');
			}

			$feedback->setId(9);
			return $feedback;
		});
		return $mapper;
	}

	private function logger(): LoggerInterface {
		$logger = $this->createMock(LoggerInterface::class);
		foreach (['warning', 'error', 'debug', 'info'] as $level) {
			$logger->method($level)->willReturnCallback(function (...$args) use ($level) {
				unset($args[1]['line'], $args[1]['trace']);
				$args[1]['file'] = basename($args[1]['file']);
				$this->calls[] = ['logger.' . $level => $args];
			});
		}

		return $logger;
	}

	private function l10n(): IL10N {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(function (string $text, array $params = []): string {
			$this->calls[] = ['l10n.t' => [$text]];
			return 't:' . vsprintf($text, $params);
		});
		return $l10n;
	}

	/**
	 * @param array<string, mixed> $actual
	 */
	private function compare(string $key, array $actual): void {
		$actual = json_decode((string)json_encode($actual), true);
		if (getenv('UPDATE_GOLDEN') === '1') {
			if (self::$recorded === null) {
				self::$recorded = [];
			}

			self::$recorded[$key] = $actual;
			ksort(self::$recorded);
			file_put_contents(
				self::FIXTURE,
				json_encode(self::$recorded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n"
			);
			self::$golden = self::$recorded;
		}

		if (self::$golden === []) {
			self::$golden = json_decode((string)file_get_contents(self::FIXTURE), true);
		}

		$this->assertArrayHasKey($key, self::$golden);
		$this->assertSame(self::$golden[$key], $actual);
	}
}
