<?php

/**
 * Golden record of TalkProvider::list().
 *
 * The app missing, no session user, link rows, and the marker scan over the
 * user's rooms (no link mapper, a failing one, an empty link table) with rooms
 * that expose every getter, none, or throw, and a participant service that
 * answers, throws or is missing. The recording lives in
 * fixtures/talk-list-golden.json; set TALK_LIST_GOLDEN_RECORD=1 to write it.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Integration\Providers
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec exclude Pins the list shape of the Talk provider.
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Integration\Providers;

use DateTime;
use OCA\OpenRegister\Kernel\Contracts\ObjectRef;
use OCA\OpenRegister\Db\TalkLink;
use OCA\OpenRegister\Db\TalkLinkMapper;
use OCA\OpenRegister\Service\Integration\Providers\TalkProvider;
use OCP\App\IAppManager;
use OCP\IL10N;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use RuntimeException;

/**
 * list() of the Talk provider.
 *
 * @covers \OCA\OpenRegister\Service\Integration\Providers\TalkProvider
 * @uses   \OCA\OpenRegister\Service\Integration\Providers\NcAppLinkTableProvider
 * @uses   \OCA\OpenRegister\Service\Integration\Providers\LinkTableAppDescriptor
 * @uses   \OCA\OpenRegister\Db\TalkLink
 * @uses   \OCA\OpenRegister\Service\Integration\Providers\TalkRoomScope
 */
class TalkListGoldenTest extends TestCase {

	private const FIXTURE = __DIR__.'/fixtures/talk-list-golden.json';

	/**
	 * What the container was asked.
	 *
	 * @var list<string>
	 */
	private array $calls = [];

	/**
	 * A room exposing every getter, answering from $data.
	 *
	 * @param array<string, mixed> $data Getter answers; an exception is thrown.
	 *
	 * @return object The room.
	 */
	private function fullRoom(array $data): object {
		return new class($data) {
			public function __construct(private array $data) {
			}
			private function answer(string $key, mixed $default = null): mixed {
				$value = $this->data[$key] ?? $default;
				if ($value instanceof \Throwable) {
					throw $value;
				}
				return $value;
			}
			public function getName() {
				return $this->answer('name');
			}
			public function getDisplayName($userId) {
				return $this->answer('displayName', '');
			}
			public function getLastActivity() {
				return $this->answer('lastActivity');
			}
			public function getType() {
				return $this->answer('type');
			}
			public function getToken() {
				return $this->answer('token');
			}
			public function getDescription() {
				return $this->answer('description');
			}
			public function getLastMessage() {
				return $this->answer('lastMessage');
			}
		};
	}//end fullRoom()

	/**
	 * A comment exposing every getter.
	 *
	 * @param array<string, mixed> $data Getter answers.
	 *
	 * @return object The comment.
	 */
	private function comment(array $data): object {
		return new class($data) {
			public function __construct(private array $data) {
			}
			public function getMessage() {
				return $this->data['message'] ?? null;
			}
			public function getActorType() {
				return $this->data['actorType'] ?? null;
			}
			public function getActorId() {
				return $this->data['actorId'] ?? null;
			}
			public function getCreationDateTime() {
				return $this->data['created'] ?? null;
			}
		};
	}//end comment()

	/**
	 * The rooms the user's manager returns.
	 *
	 * @return list<object> The rooms.
	 */
	private function rooms(): array {
		return [
			$this->fullRoom([
				'name' => 'raw', 'displayName' => 'Case room [or:obj-1]', 'lastActivity' => new DateTime('@1700000000'),
				'type' => 2, 'token' => 'tok1', 'description' => '  ',
				'lastMessage' => $this->comment(['message' => 'hi', 'actorType' => 'users', 'actorId' => 'u1', 'created' => new DateTime('@1700000100')]),
			]),
			$this->fullRoom(['name' => '[or:obj-1] Named', 'type' => 6, 'token' => 'tok2', 'description' => 'About', 'lastMessage' => new RuntimeException('gone')]),
			$this->fullRoom(['name' => '[or:obj-1]', 'type' => 9, 'lastMessage' => new class () {
			}]),
			$this->fullRoom(['name' => 'Other room', 'type' => 1]),
			new class () {
			},
		];
	}//end rooms()

	/**
	 * A container holding the Talk manager and participant service.
	 *
	 * @param string $mode 'full', 'no participants', 'participants throw' or 'no manager'.
	 *
	 * @return ContainerInterface The container.
	 */
	private function container(string $mode): ContainerInterface {
		$rooms = $this->rooms();
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			function (string $id) use ($mode, $rooms): object {
				$this->calls[] = $id;
				if ($id === 'OCA\\Talk\\Manager') {
					if ($mode === 'no manager') {
						throw new RuntimeException('no talk');
					}
					return new class ($rooms) {
						public function __construct(private array $rooms) {
						}
						public function getRoomsForUser($userId, $filter, $withLast) {
							return $this->rooms;
						}
					};
				}
				if ($mode === 'no participants') {
					throw new RuntimeException('no participants');
				}
				return new class ($mode) {
					public function __construct(private string $mode) {
					}
					public function getNumberOfActors($room) {
						if ($this->mode === 'participants throw') {
							throw new RuntimeException('count failed');
						}
						return '4';
					}
				};
			}
		);
		return $container;
	}//end container()

	/**
	 * Link rows: one filled, one bare.
	 *
	 * @return list<TalkLink> The links.
	 */
	private function links(): array {
		$full = new TalkLink();
		$full->setId(1);
		$full->setRoomToken('tok1');
		$full->setRoomName('Linked');
		$full->setRoomType(3);
		$full->setSubtitle('Public');
		$full->setParticipantCount(5);
		$full->setLastActivity(new DateTime('@1700000200'));
		$bare = new TalkLink();
		$bare->setId(2);
		return [$full, $bare];
	}//end links()

	/**
	 * Build the provider.
	 *
	 * @param bool   $installed Whether Talk is installed.
	 * @param bool   $user      Whether a user is logged in.
	 * @param mixed  $links     Link rows, an exception, or null for no mapper.
	 * @param string $mode      Container mode.
	 *
	 * @return TalkProvider The provider.
	 */
	private function provider(bool $installed, bool $user, mixed $links, string $mode): TalkProvider {
		$apps = $this->createMock(IAppManager::class);
		$apps->method('isInstalled')->willReturn($installed);
		$session = $this->createMock(IUserSession::class);
		$account = $this->createMock(IUser::class);
		$account->method('getUID')->willReturn('alice');
		$session->method('getUser')->willReturn($user ? $account : null);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text): string => 'T:'.$text);
		$mapper = null;
		if ($links !== null) {
			$mapper = $this->createMock(TalkLinkMapper::class);
			if ($links instanceof \Throwable) {
				$mapper->method('findByObjectUuid')->willThrowException($links);
			} else {
				$mapper->method('findByObjectUuid')->willReturn($links);
			}
		}
		return new TalkProvider($this->container($mode), new \OCA\OpenRegister\Service\Integration\Providers\AppProviderDescription($apps, $l10n), $session, $mapper);
	}//end provider()

	/**
	 * list() answers as recorded.
	 *
	 * @return void
	 */
	public function testListMatchesTheRecording(): void {
		$scenarios = [
			'app missing' => [false, true, $this->links(), 'full'],
			'no user' => [true, false, $this->links(), 'full'],
			'linked rows' => [true, true, $this->links(), 'full'],
			'no mapper, marker scan' => [true, true, null, 'full'],
			'mapper fails, marker scan' => [true, true, new RuntimeException('no table'), 'full'],
			'no links, participants missing' => [true, true, [], 'no participants'],
			'no links, participants throw' => [true, true, [], 'participants throw'],
			'no links, no Talk manager' => [true, true, [], 'no manager'],
		];
		$seen = [];
		foreach ($scenarios as $label => [$installed, $user, $links, $mode]) {
			$this->calls = [];
			$rows = $this->provider($installed, $user, $links, $mode)->list(new ObjectRef(register: 'r', schema: 's', id: 'obj-1'), []);
			$seen[$label] = ['rows' => $rows, 'container' => $this->calls];
		}

		$json = json_decode((string)json_encode($seen, JSON_PARTIAL_OUTPUT_ON_ERROR), true);
		if (getenv('TALK_LIST_GOLDEN_RECORD') === '1') {
			@mkdir(dirname(self::FIXTURE));
			file_put_contents(self::FIXTURE, json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
			$this->markTestIncomplete('recorded');
		}

		$this->assertSame(json_decode((string)file_get_contents(self::FIXTURE), true), $json);
	}//end testListMatchesTheRecording()
}//end class
