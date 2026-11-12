<?php

/**
 * ReferenceExistence and ReferenceEvents: whether a referenced object exists, and what is announced.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Object\SaveObject
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Object\SaveObject;

use OCA\OpenRegister\Db\MagicMapper\MagicObjectFinderInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Event\ReferenceValidatedEvent;
use OCA\OpenRegister\Event\ReferenceValidationFailedEvent;
use OCA\OpenRegister\Exception\CircularReferenceException;
use OCA\OpenRegister\Exception\ReferenceValidationException;
use OCA\OpenRegister\Service\Object\SaveObject\ReferenceCheck;
use OCA\OpenRegister\Service\Object\SaveObject\ReferenceEvents;
use OCA\OpenRegister\Service\Object\SaveObject\ReferenceExistence;
use OCA\OpenRegister\Service\Object\SaveObject\SaveCallFrame;
use OCA\OpenRegister\Service\Object\SaveObject\SaveCallStack;
use OCA\OpenRegister\Service\Object\SaveObject\SaveLookups;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventDispatcher;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Each verdict the existence check reaches.
 *
 * @covers \OCA\OpenRegister\Service\Object\SaveObject\ReferenceExistence
 * @covers \OCA\OpenRegister\Service\Object\SaveObject\ReferenceEvents
 * @uses \OCA\OpenRegister\Service\Object\SaveObject\ReferenceCheck
 * @uses \OCA\OpenRegister\Service\Object\SaveObject\SaveCallFrame
 * @uses \OCA\OpenRegister\Service\Object\SaveObject\SaveCallStack
 * @uses \OCA\OpenRegister\Service\Object\SaveObject\SaveLookups
 * @uses \OCA\OpenRegister\Service\Object\SaveObject\ReferenceText
 * @uses \OCA\OpenRegister\Event\AbstractReferenceValidationEvent
 * @uses \OCA\OpenRegister\Event\ReferenceValidatedEvent
 * @uses \OCA\OpenRegister\Event\ReferenceValidationFailedEvent
 * @uses \OCA\OpenRegister\Exception\BrokenReference
 * @uses \OCA\OpenRegister\Exception\ReferenceValidationException
 * @uses \OCA\OpenRegister\Exception\ValidationException
 * @uses \OCA\OpenRegister\Exception\CircularReferenceException
 * @uses \OCA\OpenRegister\Exception\ReferenceCycle
 * @uses \OCA\OpenRegister\Formats\UuidFormat
 * @uses \OCA\OpenRegister\Db\ObjectEntity
 * @uses \OCA\OpenRegister\Db\Register
 * @uses \OCA\OpenRegister\Db\Schema
 * @uses \OCA\OpenRegister\Db\Schema\ConfigurationEntryRules
 * @uses \OCA\OpenRegister\Db\Schema\ConfigurationValidator
 */
class ReferenceExistenceBehaviourTest extends TestCase {

	/**
	 * The object finder.
	 *
	 * @var MagicObjectFinderInterface&MockObject
	 */
	private MagicObjectFinderInterface $finder;

	/**
	 * The register mapper behind the lookups.
	 *
	 * @var RegisterMapperInterface&MockObject
	 */
	private RegisterMapperInterface $registers;

	/**
	 * The logger.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface $logger;

	/**
	 * Events dispatched.
	 *
	 * @var array<int, Event>
	 */
	private array $events = [];

	/**
	 * Warnings logged: message only.
	 *
	 * @var array<int, string>
	 */
	private array $warnings = [];

	/**
	 * The call stack of saves in progress.
	 *
	 * @var SaveCallStack
	 */
	private SaveCallStack $stack;

	/**
	 * Build the boundaries: schema "contact" (id 5, slug Contact).
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->finder = $this->createMock(MagicObjectFinderInterface::class);
		$this->registers = $this->createMock(RegisterMapperInterface::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->logger->method('warning')->willReturnCallback(
			function (string $message): void {
				$this->warnings[] = $message;
			}
		);
		$this->stack = new SaveCallStack();
	}//end setUp()

	/**
	 * The existence check, with a dispatcher that records or throws.
	 *
	 * @param bool $dispatchFails Whether dispatching throws.
	 *
	 * @return ReferenceExistence
	 */
	private function existence(bool $dispatchFails = false): ReferenceExistence {
		$contact = new Schema();
		$contact->setId(5);
		$contact->setSlug('Contact');
		$schemas = $this->createMock(SchemaMapperInterface::class);
		$schemas->method('findAll')->willReturn([$contact]);
		$schemas->method('find')->willReturnCallback(
			function (string|int $id) use ($contact): Schema {
				if ((string)$id !== '5') {
					throw new DoesNotExistException('no schema');
				}

				return $contact;
			}
		);
		$dispatcher = $this->createMock(IEventDispatcher::class);
		$dispatcher->method('dispatchTyped')->willReturnCallback(
			function (Event $event) use ($dispatchFails): void {
				if ($dispatchFails === true) {
					throw new RuntimeException('listener broke');
				}

				$this->events[] = $event;
			}
		);

		return new ReferenceExistence(new SaveLookups($schemas, $this->registers), $this->stack, $this->finder, new ReferenceEvents($dispatcher, $this->logger));
	}//end existence()

	/**
	 * A check for property owner pointing at a contact.
	 *
	 * @param string      $uuid     The referenced uuid.
	 * @param string|null $register The target register.
	 * @param string      $ref      The schema reference.
	 *
	 * @return ReferenceCheck
	 */
	private function check(string $uuid, ?string $register = null, string $ref = 'contact'): ReferenceCheck {
		$check = new ReferenceCheck();
		$check->propertyName = 'owner';
		$check->uuid = $uuid;
		$check->schemaRef = $ref;
		$check->register = $register;

		return $check;
	}//end check()

	/**
	 * A found object is accepted once, announced, and remembered; the verdict cache can be forgotten.
	 *
	 * @return void
	 */
	public function testAFoundObjectIsAcceptedAndRemembered(): void {
		$this->finder->expects($this->once())->method('find')->with('c-1', null, $this->isInstanceOf(Schema::class), false, false, false)->willReturn(new ObjectEntity());
		$existence = $this->existence();

		$existence->assertExists($this->check(uuid: 'c-1'));
		$existence->assertExists($this->check(uuid: 'c-1'));

		$this->assertSame(1, $existence->verdictCount());
		$this->assertCount(1, $this->events);
		$this->assertInstanceOf(ReferenceValidatedEvent::class, $this->events[0]);
		$this->assertSame('Contact', $this->events[0]->getTargetSchemaSlug());
		$existence->forget();
		$this->assertSame(0, $existence->verdictCount());
	}//end testAFoundObjectIsAcceptedAndRemembered()

	/**
	 * A missing object is refused and announced; asking again replays the refusal without a second read.
	 *
	 * @return void
	 */
	public function testAMissingObjectIsRefusedAndTheRefusalReplayed(): void {
		$this->finder->expects($this->once())->method('find')->willThrowException(new DoesNotExistException('gone'));
		$existence = $this->existence();
		$codes = [];
		for ($i = 0; $i < 2; $i++) {
			try {
				$existence->assertExists($this->check(uuid: 'c-2'));
			} catch (ReferenceValidationException $e) {
				$codes[] = [$e->getCode(), $e->getReferencedUuid()];
			}
		}

		$this->assertSame([[422, 'c-2'], [422, 'c-2']], $codes);
		$this->assertCount(1, $this->events);
		$this->assertInstanceOf(ReferenceValidationFailedEvent::class, $this->events[0]);
	}//end testAMissingObjectIsRefusedAndTheRefusalReplayed()

	/**
	 * A lookup error that is not non-existence is logged and blocks nothing; a failing listener is logged too.
	 *
	 * @return void
	 */
	public function testALookupErrorAndAFailingListenerAreLogged(): void {
		$this->finder->method('find')->willReturnOnConsecutiveCalls($this->throwException(new RuntimeException('db')), new ObjectEntity());
		$existence = $this->existence(dispatchFails: true);

		$existence->assertExists($this->check(uuid: 'c-3'));
		$existence->assertExists($this->check(uuid: 'c-3'));

		$this->assertSame(['[SaveObject] Reference validation lookup failed', '[SaveObject] ReferenceValidatedEvent dispatch failed'], $this->warnings);
		$this->assertSame(1, $existence->verdictCount(), 'only the successful read leaves a verdict');
	}//end testALookupErrorAndAFailingListenerAreLogged()

	/**
	 * An unresolvable schema reference is logged and not checked; an unknown register is logged and not checked; a known register is passed to the read.
	 *
	 * @return void
	 */
	public function testUnresolvableTargetsAreSkippedAndAKnownRegisterIsUsed(): void {
		$register = new Register();
		$register->setId(9);
		$this->registers->method('find')->willReturnCallback(
			function (string|int $id) use ($register): Register {
				if ((string)$id !== '9') {
					throw new DoesNotExistException('no register');
				}

				return $register;
			}
		);
		$this->finder->expects($this->once())->method('find')->with('c-4', $this->identicalTo($register))->willReturn(new ObjectEntity());
		$existence = $this->existence();

		$existence->assertExists($this->check(uuid: 'c-4', ref: 'nothing-like-it'));
		$existence->assertExists($this->check(uuid: 'c-4', register: '404'));
		$existence->assertExists($this->check(uuid: 'c-4', register: '9'));

		$this->assertSame(
			['[SaveObject] Could not resolve schema reference for reference validation', '[SaveObject] Could not resolve register for reference validation'],
			$this->warnings
		);
	}//end testUnresolvableTargetsAreSkippedAndAKnownRegisterIsUsed()

	/**
	 * A reference to an object whose save is still in progress is a cycle: refused with the trace, never read.
	 *
	 * @return void
	 */
	public function testAReferenceIntoASaveInProgressIsACycle(): void {
		$this->stack->push(new SaveCallFrame(schemaSlug: 'contact', uuid: 'c-5', register: '3'));
		$this->finder->expects($this->never())->method('find');

		try {
			$this->existence()->assertExists($this->check(uuid: 'c-5'));
			$this->fail('expected a cycle');
		} catch (CircularReferenceException $e) {
			$this->assertSame(422, $e->getCode());
			$this->assertSame([['schema' => 'contact', 'uuid' => 'c-5', 'register' => '3']], $e->getCycle());
		}

		$this->assertSame(['[SaveObject] Circular reference detected during validation'], $this->warnings);
	}//end testAReferenceIntoASaveInProgressIsACycle()
}//end class
