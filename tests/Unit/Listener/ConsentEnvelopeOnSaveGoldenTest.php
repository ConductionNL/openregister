<?php

/**
 * OpenRegister ConsentEnvelopeOnSaveGoldenTest
 *
 * Characterisation of ConsentEnvelopeOnSaveListener's allowed and refused
 * outcomes: the exact error a refused write carries, that a refusal stops
 * at the first offending property and leaves the payload untouched, and
 * which identity, address and agent an allowed write records.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Listener
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace Unit\Listener;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCA\OpenRegister\Listener\ConsentEnvelopeOnSaveListener;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Golden outcomes of the consent envelope on save.
 */
class ConsentEnvelopeOnSaveGoldenTest extends TestCase {

	/**
	 * Build the listener around a schema with two consent properties.
	 *
	 * @param string|null $uid     The acting user, or null for an anonymous write.
	 * @param string      $address The remote address the request reports.
	 *
	 * @return ConsentEnvelopeOnSaveListener
	 */
	private function listener(?string $uid, string $address): ConsentEnvelopeOnSaveListener {
		$schema = new Schema();
		$schema->setId(30);
		$schema->setProperties([
			'first' => ['type' => 'array', 'x-openregister-consent' => ['purpose' => 'p1', 'subjectProperty' => 'subject']],
			'title' => ['type' => 'string'],
			'second' => ['type' => 'array', 'x-openregister-consent' => ['purpose' => 'p2']],
		]);
		$schemas = $this->createMock(SchemaMapper::class);
		$schemas->method('find')->willReturn($schema);

		$request = $this->createMock(IRequest::class);
		$request->method('getRemoteAddress')->willReturn($address);
		$request->method('getHeader')->willReturn('agent/1');

		$user = null;
		if ($uid !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
		}

		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		return new ConsentEnvelopeOnSaveListener($schemas, $session, $request, $this->createMock(LoggerInterface::class));
	}//end listener()

	/**
	 * An object on the consent schema carrying the given data.
	 *
	 * @param array<string,mixed> $data The data.
	 *
	 * @return ObjectEntity
	 */
	private function objectWith(array $data): ObjectEntity {
		$object = new ObjectEntity();
		$object->setUuid('2a5c1a1e-5d1a-4d2f-9c6a-1f0f3c4b7e21');
		$object->setRegister('16');
		$object->setSchema('30');
		$object->setObject($data);

		return $object;
	}//end objectWith()

	/**
	 * A refused write carries exactly code, message and property, stops at the
	 * first offending property and leaves the payload as submitted.
	 *
	 * @return void
	 */
	public function testRefusalIsExactAndStopsAtTheFirstProperty(): void {
		$entry = ['decision' => 'granted', 'by' => 'u', 'timestamp' => 't1'];
		$persisted = $this->objectWith(['first' => [$entry], 'second' => [$entry]]);
		$submitted = [
			'first' => [array_merge($entry, ['decision' => 'refused'])],
			'second' => [],
			'title' => 'x',
		];
		$incoming = $this->objectWith($submitted);
		$submitted = $incoming->getObject();

		$event = new ObjectUpdatingEvent(newObject: $incoming, oldObject: $persisted);
		$this->listener(uid: 'u', address: '1.2.3.4')->handle($event);

		self::assertTrue($event->isPropagationStopped());
		$errors = $event->getErrors();
		self::assertSame(['code', 'message', 'property'], array_keys($errors));
		self::assertSame('consent-envelope-mutated', $errors['code']);
		self::assertSame('first', $errors['property']);
		self::assertIsString($errors['message']);
		self::assertNotSame('', $errors['message']);
		self::assertSame($submitted, $incoming->getObject());
	}//end testRefusalIsExactAndStopsAtTheFirstProperty()

	/**
	 * A refusal on the second property names that property.
	 *
	 * @return void
	 */
	public function testRefusalOnALaterPropertyNamesIt(): void {
		$entry = ['decision' => 'granted', 'by' => 'u', 'timestamp' => 't1'];
		$persisted = $this->objectWith(['first' => [$entry], 'second' => [$entry]]);
		$submitted = ['first' => [$entry], 'second' => []];
		$incoming = $this->objectWith($submitted);
		$submitted = $incoming->getObject();

		$event = new ObjectUpdatingEvent(newObject: $incoming, oldObject: $persisted);
		$this->listener(uid: 'u', address: '1.2.3.4')->handle($event);

		self::assertTrue($event->isPropagationStopped());
		self::assertSame('second', $event->getErrors()['property']);
		self::assertSame($submitted, $incoming->getObject());
	}//end testRefusalOnALaterPropertyNamesIt()

	/**
	 * An anonymous write records the subject property as "by" where declared,
	 * null where not, and an empty remote address as null.
	 *
	 * @return void
	 */
	public function testAnonymousWriteRecordsTheSubjectProperty(): void {
		$object = $this->objectWith([
			'subject' => 'learner-7',
			'first' => [['decision' => 'granted', 'evidenceOf' => 'v1']],
			'second' => [['decision' => 'granted', 'evidenceOf' => 'v2']],
			'title' => 'kept',
		]);

		$event = new ObjectCreatingEvent($object);
		$this->listener(uid: null, address: '')->handle($event);

		self::assertFalse($event->isPropagationStopped());
		$data = $object->getObject();
		self::assertSame('kept', $data['title']);
		self::assertSame('learner-7', $data['first'][0]['by']);
		self::assertNull($data['second'][0]['by']);
		self::assertNull($data['first'][0]['ip']);
		self::assertSame('agent/1', $data['second'][0]['userAgent']);
		self::assertSame(hash('sha256', 'p1grantedv1'), $data['first'][0]['contentHash']);
		self::assertSame(hash('sha256', 'p2grantedv2'), $data['second'][0]['contentHash']);
	}//end testAnonymousWriteRecordsTheSubjectProperty()

	/**
	 * A logged-in user is recorded as "by" over the subject property, and an
	 * update that only repeats the stored entries changes nothing.
	 *
	 * @return void
	 */
	public function testUserWinsAndAnUnchangedUpdateIsAllowed(): void {
		$object = $this->objectWith([
			'subject' => 'learner-7',
			'first' => [['decision' => 'granted', 'evidenceOf' => 'v1']],
		]);
		$this->listener(uid: 'guardian-1', address: '1.2.3.4')->handle(new ObjectCreatingEvent($object));
		$stored = $object->getObject();
		self::assertSame('guardian-1', $stored['first'][0]['by']);
		self::assertSame('1.2.3.4', $stored['first'][0]['ip']);

		$incoming = $this->objectWith($stored);
		$event = new ObjectUpdatingEvent(newObject: $incoming, oldObject: $this->objectWith($stored));
		$this->listener(uid: 'other', address: '9.9.9.9')->handle($event);

		self::assertFalse($event->isPropagationStopped());
		self::assertSame($stored, $incoming->getObject());
	}//end testUserWinsAndAnUnchangedUpdateIsAllowed()
}//end class
