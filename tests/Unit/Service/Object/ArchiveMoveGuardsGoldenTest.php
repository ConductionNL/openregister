<?php

declare(strict_types=1);

/**
 * Archive and move guards golden (characterisation) test
 *
 * Pins, on the code as it stood before the D06 complexity refactor, the
 * allowed AND refused outcomes of ArchiveHandler's resolve-and-authorise step
 * (unresolved register or schema, url scope by id and slug, empty scope,
 * schema without the archive annotation, the update check with and without a
 * session user, and the order of those checks) and of MoveObject's
 * precondition refusals and error sentences.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Object
 * @author   Conduction Development Team <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/openregister
 */

namespace OCA\OpenRegister\Tests\Unit\Service\Object;

use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Exception\NotAuthorizedException;
use OCA\OpenRegister\Service\Object\ArchiveHandler;
use OCA\OpenRegister\Service\Object\MoveObject;
use OCA\OpenRegister\Service\Object\ObjectValidatorInterface;
use OCA\OpenRegister\Service\Object\PermissionHandler;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;

/**
 * Golden outcomes of ArchiveHandler::resolveAndAuthorize() and MoveObject guards.
 */
class ArchiveMoveGuardsGoldenTest extends TestCase {
	/** @var list<string> Every permission question asked, in order. */
	private array $asked = [];

	/**
	 * Build an archive handler resolving to the given entities.
	 *
	 * @param mixed     $register The resolved register
	 * @param mixed     $schema   The resolved schema
	 * @param bool      $allow    Whether the update check allows
	 * @param IUser|null $user    The session user
	 *
	 * @return ArchiveHandler
	 */
	private function archive(mixed $register, mixed $schema, bool $allow, ?IUser $user): ArchiveHandler {
		$object = new ObjectEntity();
		$object->setUuid('o-1');
		$object->setOwner('owner-uid');

		$magic = $this->createMock(MagicMapper::class);
		$magic->method('findAcrossAllSources')->willReturn(['object' => $object, 'register' => $register, 'schema' => $schema]);
		$magic->method('updateObjectEntity')->willReturnArgument(0);

		$permissions = $this->createMock(PermissionHandler::class);
		$permissions->method('checkPermission')->willReturnCallback(
			function (...$args) use ($allow): void {
				$this->asked[] = $args[1] . '|' . var_export($args[2], true) . '|' . $args[3];
				if ($allow === false) {
					throw new NotAuthorizedException(message: 'no');
				}
			}
		);

		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		return new ArchiveHandler(
			$magic,
			$this->createMock(AuditTrailMapper::class),
			$permissions,
			$session,
			$this->createMock(LoggerInterface::class)
		);
	}//end archive()

	/**
	 * Archive once, answering 'ok' or the exception class and message.
	 *
	 * @param ArchiveHandler $handler  The handler
	 * @param string|null    $register The url register
	 * @param string|null    $schema   The url schema
	 *
	 * @return string
	 */
	private static function outcome(ArchiveHandler $handler, ?string $register, ?string $schema): string {
		try {
			$handler->archive(identifier: 'o-1', register: $register, schema: $schema);
			return 'ok';
		} catch (\Exception $e) {
			return (new \ReflectionClass($e))->getShortName() . ': ' . $e->getMessage();
		}
	}//end outcome()

	/**
	 * Every allowed and refused arm of the archive resolve-and-authorise step.
	 *
	 * @return void
	 */
	public function testArchiveResolveAndAuthorise(): void {
		$register = new Register();
		$register->setId(1);
		$register->setSlug('zaken');
		$schema = new Schema();
		$schema->setId(2);
		$schema->setSlug('zaak');
		$schema->setTitle('Zaak');
		$schema->setConfiguration([Schema::ARCHIVE_ANNOTATION => ['enabled' => true]]);
		$plain = new Schema();
		$plain->setId(2);
		$plain->setSlug('zaak');
		$plain->setTitle('Plain');
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('anna');

		$unresolved = 'ArchiveNotOfferedException: Cannot archive this object: its register and schema could not be resolved.';
		$this->assertSame($unresolved, self::outcome($this->archive(null, $schema, true, $user), null, null));
		$this->assertSame($unresolved, self::outcome($this->archive($register, null, true, $user), null, null));
		$this->assertSame($unresolved, self::outcome($this->archive($register, 'zaak', true, $user), null, null));

		$this->assertSame('ok', self::outcome($this->archive($register, $schema, true, $user), null, null));
		$this->assertSame('ok', self::outcome($this->archive($register, $schema, true, $user), '', ''));
		$this->assertSame('ok', self::outcome($this->archive($register, $schema, true, $user), '1', 'zaak'));
		$this->assertSame('ok', self::outcome($this->archive($register, $schema, true, $user), 'zaken', '2'));
		$this->assertSame(
			'DoesNotExistException: Object "o-1" is not in register "9".',
			self::outcome($this->archive($register, $schema, true, $user), '9', 'other')
		);
		$this->assertSame(
			'DoesNotExistException: Object "o-1" is not in schema "other".',
			self::outcome($this->archive($register, $schema, true, $user), 'zaken', 'other')
		);
		$this->assertSame(
			'ArchiveNotOfferedException: Schema "Plain" does not declare x-openregister-archive, so its objects cannot be archived.',
			self::outcome($this->archive($register, $plain, true, $user), 'zaken', 'zaak')
		);
		$this->assertSame('NotAuthorizedException: no', self::outcome($this->archive($register, $schema, false, $user), null, null));
		$this->assertSame('Exception: No user logged in', self::outcome($this->archive($register, $schema, true, null), null, null), 'Authorised, then refused by the marker itself.');

		// The update check runs last, only for objects that passed every earlier guard.
		$this->assertSame(
			['update|\'anna\'|owner-uid', 'update|\'anna\'|owner-uid', 'update|\'anna\'|owner-uid', 'update|\'anna\'|owner-uid', 'update|\'anna\'|owner-uid', 'update|NULL|owner-uid'],
			$this->asked
		);
	}//end testArchiveResolveAndAuthorise()

	/**
	 * MoveObject's error sentences for every shape of validation error.
	 *
	 * @return void
	 */
	public function testMoveSentences(): void {
		$move = new MoveObject(
			$this->createMock(MagicMapper::class),
			$this->createMock(ObjectValidatorInterface::class),
			$this->createMock(AuditTrailMapper::class),
			$this->createMock(LoggerInterface::class),
			$this->createMock(IEventDispatcher::class)
		);
		$sentence = new ReflectionMethod(MoveObject::class, 'sentenceFor');

		$withPath = new class {
			public function message(): string {
				return 'is required';
			}

			public function data(): object {
				return new class {
					public function path(): array {
						return ['a', 'b'];
					}
				};
			}
		};
		$messageOnly = new class {
			public function message(): string {
				return 'bad';
			}

			public function data(): string {
				return 'x';
			}
		};
		$pathOnly = new class {
			public function data(): object {
				return new class {
					public function path(): string {
						return 'p';
					}
				};
			}
		};
		$nothing = new class {
		};

		$this->assertSame('a/b: is required', $sentence->invoke($move, $withPath));
		$this->assertSame('bad', $sentence->invoke($move, $messageOnly));
		$this->assertSame('invalid', $sentence->invoke($move, $pathOnly));
		$this->assertSame('invalid', $sentence->invoke($move, $nothing));
	}//end testMoveSentences()
}//end class
