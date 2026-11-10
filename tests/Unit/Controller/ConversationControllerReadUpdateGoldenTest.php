<?php

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Controller;

use OCA\OpenRegister\Mcp\Db\AgentMapper;
use OCA\OpenRegister\Mcp\Db\Conversation;
use OCA\OpenRegister\Mcp\Db\ConversationMapper;
use OCA\OpenRegister\Db\FeedbackMapper;
use OCA\OpenRegister\Db\ListQuery;
use OCA\OpenRegister\Db\Organisation;
use OCA\OpenRegister\Notifications\Db\Message;
use OCA\OpenRegister\Notifications\Db\MessageMapper;
use OCA\OpenRegister\Mcp\Service\ChatService;
use OCA\OpenRegister\Service\OrganisationService;
use OCA\OpenRegister\Tests\Unit\Controller\Support\ConversationControllerFactory;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Golden test for the read and update actions of ConversationController:
 * show(), messages() and update().
 *
 * Every case records each collaborator call in order (the access check with
 * the user and organisation it ran for, the message reads, the update), the
 * logger messages and the response, and compares the recording with
 * fixtures/conversation-read-update-golden.json. The access doubles answer
 * true only for the acting (session) user, so a forged userId request value
 * cannot grant access. Set UPDATE_GOLDEN=1 to rewrite the fixture.
 *
 * @covers \OCA\OpenRegister\Controller\ConversationController
 * @uses \OCA\OpenRegister\Controller\Support\ChatMappers
 * @uses \OCA\OpenRegister\Controller\Support\ConversationDomain
 * @uses \OCA\OpenRegister\Controller\Support\ConversationPlatform
 * @uses \OCA\OpenRegister\Controller\Support\ConversationControllerServices
 * @uses \OCA\OpenRegister\Controller\Support\ConversationFailure
 * @uses \OCA\OpenRegister\Mcp\Db\Conversation
 * @uses \OCA\OpenRegister\Db\Organisation
 * @uses \OCA\OpenRegister\Notifications\Db\Message
 */
class ConversationControllerReadUpdateGoldenTest extends TestCase {
	private const FIXTURE = __DIR__ . '/fixtures/conversation-read-update-golden.json';

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
		foreach (['show', 'messages', 'update'] as $action) {
			$cases[$action . ' by the owner'] = [['action' => $action, 'user' => 'alice']];
			$cases[$action . ' by the owner without an organisation'] = [['action' => $action, 'user' => 'alice', 'noOrg' => true]];
			$cases[$action . ' by another user'] = [['action' => $action, 'user' => 'bob']];
			$cases[$action . ' by another user with forged identity'] = [['action' => $action, 'user' => 'bob', 'params' => ['userId' => 'alice', 'title' => 'X']]];
			$cases[$action . ' not found'] = [['action' => $action, 'user' => 'alice', 'missing' => true]];
			$cases[$action . ' access check failure'] = [['action' => $action, 'user' => 'alice', 'fail' => 'access']];
			$cases[$action . ' organisation failure'] = [['action' => $action, 'user' => 'alice', 'fail' => 'organisation']];
		}

		$cases['show count failure'] = [['action' => 'show', 'user' => 'alice', 'fail' => 'count']];
		$cases['messages with limit and offset'] = [['action' => 'messages', 'user' => 'alice', 'params' => ['limit' => '5', 'offset' => '10']]];
		$cases['messages with underscored paging'] = [['action' => 'messages', 'user' => 'alice', 'params' => ['_limit' => '7', '_offset' => '2']]];
		$cases['messages read failure'] = [['action' => 'messages', 'user' => 'alice', 'fail' => 'messages']];
		$cases['update title and metadata'] = [['action' => 'update', 'user' => 'alice', 'params' => ['title' => 'New', 'metadata' => ['a' => 1], 'userId' => 'mallory', 'organisation' => 'x']]];
		$cases['update with null title'] = [['action' => 'update', 'user' => 'alice', 'params' => ['title' => null, 'metadata' => ['b' => 2]]]];
		$cases['update save failure'] = [['action' => 'update', 'user' => 'alice', 'params' => ['title' => 'New'], 'fail' => 'update']];
		return $cases;
	}

	#[DataProvider('cases')]
	public function testReadOrUpdateMatchesTheRecording(array $case): void {
		$this->calls = [];
		$params = $case['params'] ?? [];
		$fail = $case['fail'] ?? null;
		$request = $this->createMock(IRequest::class);
		$request->method('getParams')->willReturn($params);
		$request->method('getParam')->willReturnCallback(fn (string $key, $default = null) => $params[$key] ?? $default);

		$conversation = new Conversation();
		$conversation->setId(42);
		$conversation->setUuid('conv-1');
		$conversation->setTitle('Chat');
		$conversation->setUserId('alice');
		$conversation->setOrganisation('org-1');

		$conversations = $this->createMock(ConversationMapper::class);
		$conversations->method('findByUuid')->willReturnCallback(function (string $uuid) use ($conversation, $case) {
			$this->calls[] = ['conversationMapper.findByUuid' => $uuid];
			if (($case['missing'] ?? false) === true) {
				throw new DoesNotExistException('none');
			}

			return $conversation;
		});
		$conversations->method('canUserAccessConversation')->willReturnCallback(function (Conversation $c, string $userId, ?string $org = null) use ($fail) {
			$this->calls[] = ['conversationMapper.canUserAccessConversation' => [$c->getId(), $userId, $org]];
			$this->failOn('access', $fail);
			return $userId === $c->getUserId();
		});
		$conversations->method('canUserModifyConversation')->willReturnCallback(function (Conversation $c, string $userId) use ($fail) {
			$this->calls[] = ['conversationMapper.canUserModifyConversation' => [$c->getId(), $userId]];
			$this->failOn('access', $fail);
			return $userId === $c->getUserId();
		});
		$conversations->method('update')->willReturnCallback(function (Conversation $c) use ($fail) {
			$this->calls[] = ['conversationMapper.update' => [$c->getTitle(), $c->getMetadata(), $c->getUserId(), $c->getOrganisation(), $c->getUpdated() !== null]];
			$this->failOn('update', $fail);
			return $c;
		});

		$messages = $this->createMock(MessageMapper::class);
		$messages->method('countByConversation')->willReturnCallback(function (int $id) use ($fail) {
			$this->calls[] = ['messageMapper.countByConversation' => $id];
			$this->failOn('count', $fail);
			return 3;
		});
		$messages->method('findByConversation')->willReturnCallback(function (int $conversationId, ListQuery $page) use ($fail) {
			$this->calls[] = ['messageMapper.findByConversation' => [$conversationId, $page->limit, $page->offset]];
			$this->failOn('messages', $fail);
			$message = new Message();
			$message->setId(7);
			$message->setUuid('msg-7');
			$message->setRole('user');
			$message->setContent('hi');
			return [$message];
		});

		$organisations = $this->createMock(OrganisationService::class);
		$organisations->method('getActiveOrganisation')->willReturnCallback(function () use ($case, $fail) {
			$this->calls[] = ['organisationService.getActiveOrganisation' => true];
			$this->failOn('organisation', $fail);
			if (($case['noOrg'] ?? false) === true) {
				return null;
			}

			$organisation = new Organisation();
			$organisation->setUuid('org-1');
			return $organisation;
		});

		$logger = $this->createMock(LoggerInterface::class);
		foreach (['info', 'error'] as $level) {
			$logger->method($level)->willReturnCallback(function ($message, array $context = []) use ($level) {
				$this->calls[] = ['logger.' . $level => [$message, $context['uuid'] ?? null, $context['error'] ?? null]];
			});
		}

		$controller = ConversationControllerFactory::create(
			$request,
			$conversations,
			$messages,
			$this->createMock(FeedbackMapper::class),
			$this->createMock(AgentMapper::class),
			$organisations,
			$this->createMock(ChatService::class),
			$logger,
			$case['user']
		);

		$response = $controller->{$case['action']}('conv-1');
		$data = $response->getData();
		unset($data['updated'], $data['created']);
		$actual = ['calls' => $this->calls, 'status' => $response->getStatus(), 'data' => $data];
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
