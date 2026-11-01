<?php

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Controller;

use DateTime;
use OCA\OpenRegister\Controller\ConversationController;
use OCA\OpenRegister\Db\AgentMapper;
use OCA\OpenRegister\Db\Conversation;
use OCA\OpenRegister\Db\ConversationMapper;
use OCA\OpenRegister\Db\FeedbackMapper;
use OCA\OpenRegister\Db\MessageMapper;
use OCA\OpenRegister\Service\ChatService;
use OCA\OpenRegister\Service\OrganisationService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Golden test for the delete and restore actions of ConversationController:
 * destroy(), restore() and destroyPermanent().
 *
 * Every case records each mapper call in order (the access check with the
 * user it ran for, the feedback, message and conversation deletes, the
 * soft delete and the restore), the logger messages and the response, and
 * compares the recording with fixtures/conversation-delete-golden.json.
 * The access double answers true only when the acting (session) user owns
 * the conversation, so a forged userId, requestedBy or owner request value
 * cannot grant access. Set UPDATE_GOLDEN=1 to rewrite the fixture.
 *
 * @covers \OCA\OpenRegister\Controller\ConversationController
 * @uses \OCA\OpenRegister\Db\Conversation
 */
class ConversationControllerDeleteGoldenTest extends TestCase {
	private const FIXTURE = __DIR__ . '/fixtures/conversation-delete-golden.json';

	private const FORGED = ['userId' => 'alice', 'requestedBy' => 'alice', 'owner' => 'alice', 'uuid' => 'conv-other'];

	/** @var list<mixed> */
	private array $calls = [];

	/** @var array<string, mixed>|null */
	private static ?array $recorded = null;

	/** @var array<string, mixed> */
	private static array $golden = [];

	/**
	 * @return array<string, array{0: array<string, mixed>}>
	 */
	public static function cases(): array {
		$cases = [];
		foreach (['destroy', 'restore', 'destroyPermanent'] as $action) {
			$cases[$action . ' by the owner'] = [['action' => $action, 'user' => 'alice']];
			$cases[$action . ' of an archived conversation'] = [['action' => $action, 'user' => 'alice', 'archived' => true]];
			$cases[$action . ' by another user'] = [['action' => $action, 'user' => 'bob']];
			$cases[$action . ' by another user with forged identity'] = [['action' => $action, 'user' => 'bob', 'params' => self::FORGED]];
			$cases[$action . ' of an archived conversation by another user'] = [['action' => $action, 'user' => 'bob', 'archived' => true, 'params' => self::FORGED]];
			$cases[$action . ' not found'] = [['action' => $action, 'user' => 'alice', 'missing' => true]];
			$cases[$action . ' access check failure'] = [['action' => $action, 'user' => 'alice', 'fail' => 'access']];
			$cases[$action . ' message delete failure'] = [['action' => $action, 'user' => 'alice', 'archived' => true, 'fail' => 'messages']];
			$cases[$action . ' conversation delete failure'] = [['action' => $action, 'user' => 'alice', 'archived' => true, 'fail' => 'delete']];
		}

		$cases['destroy feedback delete failure'] = [['action' => 'destroy', 'user' => 'alice', 'archived' => true, 'fail' => 'feedback']];
		$cases['destroy soft delete failure'] = [['action' => 'destroy', 'user' => 'alice', 'fail' => 'softDelete']];
		$cases['restore failure'] = [['action' => 'restore', 'user' => 'alice', 'archived' => true, 'fail' => 'restore']];
		return $cases;
	}

	#[DataProvider('cases')]
	public function testDeleteActionMatchesTheRecording(array $case): void {
		$this->calls = [];
		$params = $case['params'] ?? [];
		$request = $this->createMock(IRequest::class);
		$request->method('getParams')->willReturn($params);
		$request->method('getParam')->willReturnCallback(fn (string $key, $default = null) => $params[$key] ?? $default);

		$conversation = new Conversation();
		$conversation->setId(42);
		$conversation->setUuid('conv-1');
		$conversation->setTitle('Chat');
		$conversation->setUserId('alice');
		if (($case['archived'] ?? false) === true) {
			$conversation->setDeletedAt(new DateTime('2026-01-02T03:04:05+00:00'));
		}

		$fail = $case['fail'] ?? null;
		$conversations = $this->createMock(ConversationMapper::class);
		$conversations->method('findByUuid')->willReturnCallback(function (string $uuid) use ($conversation, $case) {
			$this->calls[] = ['conversationMapper.findByUuid' => $uuid];
			if (($case['missing'] ?? false) === true) {
				throw new DoesNotExistException('none');
			}

			return $conversation;
		});
		$conversations->method('canUserModifyConversation')->willReturnCallback(function (Conversation $c, string $userId) use ($fail) {
			$this->calls[] = ['conversationMapper.canUserModifyConversation' => [$c->getId(), $userId]];
			$this->failOn('access', $fail);
			return $userId === $c->getUserId();
		});
		$conversations->method('delete')->willReturnCallback(function (Conversation $c) use ($fail) {
			$this->calls[] = ['conversationMapper.delete' => $c->getId()];
			$this->failOn('delete', $fail);
			return $c;
		});
		$conversations->method('softDelete')->willReturnCallback(function (int $id) use ($fail, $conversation) {
			$this->calls[] = ['conversationMapper.softDelete' => $id];
			$this->failOn('softDelete', $fail);
			return $conversation;
		});
		$conversations->method('restore')->willReturnCallback(function (int $id) use ($fail) {
			$this->calls[] = ['conversationMapper.restore' => $id];
			$this->failOn('restore', $fail);
			$restored = new Conversation();
			$restored->setId($id);
			$restored->setUuid('conv-1');
			$restored->setTitle('Restored');
			$restored->setUserId('alice');
			return $restored;
		});

		$messages = $this->createMock(MessageMapper::class);
		$messages->method('deleteByConversation')->willReturnCallback(function (int $id) use ($fail) {
			$this->calls[] = ['messageMapper.deleteByConversation' => $id];
			$this->failOn('messages', $fail);
			return 3;
		});
		$feedback = $this->createMock(FeedbackMapper::class);
		$feedback->method('deleteByConversation')->willReturnCallback(function (int $id) use ($fail) {
			$this->calls[] = ['feedbackMapper.deleteByConversation' => $id];
			$this->failOn('feedback', $fail);
		});

		$logger = $this->createMock(LoggerInterface::class);
		foreach (['info', 'error'] as $level) {
			$logger->method($level)->willReturnCallback(function ($message, array $context = []) use ($level) {
				$this->calls[] = ['logger.' . $level => [$message, $context['uuid'] ?? null, $context['error'] ?? null]];
			});
		}

		$controller = new ConversationController(
			'openregister',
			$request,
			$conversations,
			$messages,
			$feedback,
			$this->createMock(AgentMapper::class),
			$this->createMock(OrganisationService::class),
			$this->createMock(ChatService::class),
			$logger,
			$case['user']
		);

		$response = $controller->{$case['action']}('conv-1');
		$actual = ['calls' => $this->calls, 'status' => $response->getStatus(), 'data' => $response->getData()];
		$this->compare($this->dataName(), $actual);
	}

	private function failOn(string $step, ?string $fail): void {
		if ($step === $fail) {
			throw new RuntimeException($step . ' failed');
		}
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
