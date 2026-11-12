<?php

/**
 * Person link write golden tests
 *
 * Pins the allowed and refused outcomes of linking a person to an object:
 * the role vocabulary check (and that the vocabulary is read even without a
 * role), which writer a payload reaches with which arguments, and the user
 * link row UserLinkWriter inserts or updates, with its refusals in order.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service
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

namespace OCA\OpenRegister\Tests\Unit\Service;

use DateTime;
use Exception;
use OCA\OpenRegister\Db\ContactLink;
use OCA\OpenRegister\Db\ContactLinkMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\PersonLinkRef;
use OCA\OpenRegister\Service\PersonLinkTarget;
use OCA\OpenRegister\Service\ContactService;
use OCA\OpenRegister\Service\PersonLinkEvents;
use OCA\OpenRegister\Service\PersonLinkService;
use OCA\OpenRegister\Service\PersonLinkTargets;
use OCA\OpenRegister\Service\UserLinkRequest;
use OCA\OpenRegister\Service\UserLinkWriter;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * Golden tests for person link writes.
 */
class PersonLinkWriteGoldenTest extends TestCase {

	/**
	 * Recorded calls, in order.
	 *
	 * @var list<string>
	 */
	private array $calls = [];

	/**
	 * Assert a call throws an Exception with the code and message.
	 *
	 * @param callable $call    The call
	 * @param int      $code    Expected code
	 * @param string   $message Expected message
	 *
	 * @return void
	 */
	private function assertRefused(callable $call, int $code, string $message): void {
		try {
			$call();
			$this->fail('Expected a refusal');
		} catch (Exception $e) {
			$this->assertSame([$code, $message], [$e->getCode(), $e->getMessage()]);
		}
	}//end assertRefused()

	/**
	 * A PersonLinkService whose schema 3 declares two roles and schema 4 none.
	 *
	 * @return PersonLinkService
	 */
	private function personLinks(): PersonLinkService {
		$links = $this->getMockBuilder(ContactLinkMapper::class)
			->disableOriginalConstructor()
			->onlyMethods(['update'])
			->getMock();
		$links->method('update')->willReturnArgument(0);
		$schemas = $this->getMockBuilder(SchemaMapper::class)
			->disableOriginalConstructor()
			->onlyMethods(['find'])
			->getMock();
		$schemas->method('find')->willReturnCallback(
			function ($id): Schema {
				$this->calls[] = 'schema:'.$id;
				if ($id === 5) {
					throw new \RuntimeException('gone');
				}

				$schema = new Schema();
				if ($id === 3) {
					$schema->setConfiguration(['linkRoles' => [['key' => 'initiator', 'label' => 'I'], ['key' => 'handler', 'label' => 'H']]]);
				}

				return $schema;
			}
		);
		$userLinks = $this->createMock(UserLinkWriter::class);
		$userLinks->method('write')->willReturnCallback(
			function (UserLinkRequest $request, ?string $role): ContactLink {
				$this->calls[] = 'user:'.json_encode([$request->objectUuid, $request->registerId, $request->schemaId, $request->userId, $role]);
				return new ContactLink();
			}
		);
		$contacts = $this->createMock(ContactService::class);
		$contacts->method('linkCard')->willReturnCallback(
			function (\OCA\OpenRegister\Service\ContactCardRef $card, \OCA\OpenRegister\Service\ContactPlacement $placement): ContactLink {
				$args = [$placement->objectUuid, $placement->registerId, $card->addressbookId, $card->contactUri, $placement->role, $placement->schemaId];
				$this->calls[] = 'contact:'.json_encode($args);
				return new ContactLink();
			}
		);
		return new PersonLinkService($links, new PersonLinkTargets($contacts, $userLinks), $schemas, new PersonLinkEvents($this->createMock(IEventDispatcher::class)));
	}//end personLinks()

	/**
	 * Linking: role vocabulary and which writer is reached.
	 *
	 * @return void
	 */
	public function testLink(): void {
		$service = $this->personLinks();
		$service->linkPerson(new PersonLinkTarget('case-1', 9, 3), ['userId' => ' jan ', 'role' => ' handler ']);
		$service->linkPerson(new PersonLinkTarget('case-1', 9, 3), ['userId' => 'jan']);
		$service->linkPerson(new PersonLinkTarget('case-1', 9, 4), ['userId' => 'jan', 'role' => 'anything']);
		$service->linkPerson(new PersonLinkTarget('case-1', 9, 5), ['userId' => 'jan', 'role' => 'anything']);
		$service->linkPerson(new PersonLinkTarget('case-1', 9, null), ['addressbookId' => '2', 'contactUri' => ' c.vcf ', 'role' => 'x']);
		$service->linkPerson(new PersonLinkTarget('case-1', 9, 3), ['userId' => '  ', 'addressbookId' => 2, 'contactUri' => 'c.vcf', 'role' => 'initiator']);
		$this->assertSame(
			[
				'schema:3',
				'user:["case-1",9,3,"jan","handler"]',
				'schema:3',
				'user:["case-1",9,3,"jan",null]',
				'schema:4',
				'user:["case-1",9,4,"jan","anything"]',
				'schema:5',
				'user:["case-1",9,5,"jan","anything"]',
				'contact:["case-1",9,2,"c.vcf","x",null]',
				'schema:3',
				'contact:["case-1",9,2,"c.vcf","initiator",3]',
			],
			$this->calls
		);

		$this->calls = [];
		$this->assertRefused(fn () => $service->linkPerson(new PersonLinkTarget('case-1', 9, 3), ['userId' => 'jan', 'role' => 'boss']), 400, 'Role "boss" is not one of: initiator, handler');
		$this->assertRefused(fn () => $service->linkPerson(new PersonLinkTarget('case-1', 9, 3), ['role' => 'handler']), 400, 'Either userId or addressbookId+contactUri is required');
		$this->assertRefused(fn () => $service->linkPerson(new PersonLinkTarget('case-1', 9, 3), ['addressbookId' => 0, 'contactUri' => 'c.vcf']), 400, 'Either userId or addressbookId+contactUri is required');
		$this->assertRefused(fn () => $service->linkPerson(new PersonLinkTarget('case-1', 9, 3), ['addressbookId' => 2, 'contactUri' => ' ']), 400, 'Either userId or addressbookId+contactUri is required');
		$this->assertSame(['schema:3', 'schema:3', 'schema:3', 'schema:3'], $this->calls);
	}//end testLink()

	/**
	 * The user link row: inserted new, updated existing; refusals in order.
	 *
	 * @return void
	 */
	public function testUserLinkWriter(): void {
		$links = $this->getMockBuilder(ContactLinkMapper::class)
			->disableOriginalConstructor()
			->onlyMethods(['findByObjectContactAndRole', 'insert', 'update'])
			->getMock();
		$existing = new ContactLink();
		$existing->setObjectUuid('case-1');
		$existing->setContactUid('user:jan');
		$existing->setNote('kept');
		$links->method('findByObjectContactAndRole')->willReturnCallback(
			function (PersonLinkRef $ref) use ($existing): ?ContactLink {
				$args = [$ref->objectUuid, $ref->contactUid, $ref->role];
				$this->calls[] = 'find:'.json_encode($args);
				if ($args[2] === 'handler') {
					return $existing;
				}

				return null;
			}
		);
		foreach (['insert', 'update'] as $verb) {
			$links->method($verb)->willReturnCallback(
				function (ContactLink $link) use ($verb): ContactLink {
					$this->calls[] = $verb.':'.json_encode(
						[
							$link->getObjectUuid(),
							$link->getContactUid(),
							$link->getRegisterId(),
							$link->getSchemaId(),
							$link->getUserId(),
							$link->getDisplayName(),
							$link->getEmail(),
							$link->getAvatarUrl(),
							$link->getRole(),
							$link->getLinkedBy(),
							$link->getLinkedAt() instanceof DateTime,
							$link->getNote(),
						]
					);
					return $link;
				}
			);
		}

		$jan = $this->createMock(IUser::class);
		$jan->method('getUID')->willReturn('jan');
		$jan->method('getDisplayName')->willReturn('Jan');
		$jan->method('getEMailAddress')->willReturn(null);
		$users = $this->createMock(IUserManager::class);
		$users->method('get')->willReturnCallback(fn (string $uid): ?IUser => ($uid === 'jan') ? $jan : null);
		$admin = $this->createMock(IUser::class);
		$admin->method('getUID')->willReturn('admin');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturnOnConsecutiveCalls($admin, $admin, null, null);
		$urls = $this->createMock(IURLGenerator::class);
		$urls->method('linkToRoute')->willReturnCallback(fn (string $route, array $args): string => $route.'?'.http_build_query($args));
		$writer = new UserLinkWriter($links, new \OCA\OpenRegister\Service\UserDirectory(session: $session, users: $users, groups: $this->createMock(\OCP\IGroupManager::class)), $urls);

		$writer->write(new UserLinkRequest('case-1', 5, null, 'jan'), null);
		$writer->write(new UserLinkRequest('case-1', 5, 7, 'jan'), 'handler');
		$this->assertRefused(fn () => $writer->write(new UserLinkRequest('case-1', 5, 7, 'ghost'), 'handler'), 404, 'User not found');
		$this->assertRefused(fn () => $writer->write(new UserLinkRequest('case-1', 5, 7, 'jan'), 'handler'), 401, 'No user logged in');
		$this->assertRefused(fn () => $writer->write(new UserLinkRequest('case-1', 5, 7, 'ghost'), 'handler'), 404, 'User not found');
		$this->assertSame(
			[
				'find:["case-1","user:jan",null]',
				'insert:["case-1","user:jan",5,null,"jan","Jan",null,"core.avatar.getAvatar?userId=jan&size=64",null,"admin",true,null]',
				'find:["case-1","user:jan","handler"]',
				'update:["case-1","user:jan",5,7,"jan","Jan",null,"core.avatar.getAvatar?userId=jan&size=64","handler","admin",true,"kept"]',
			],
			$this->calls
		);
	}//end testUserLinkWriter()
}//end class
