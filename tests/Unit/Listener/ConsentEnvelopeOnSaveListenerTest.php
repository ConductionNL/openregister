<?php

/**
 * OpenRegister ConsentEnvelopeOnSaveListenerTest
 *
 * A consent-shaped property (declared via `x-openregister-consent`) fills
 * its evidentiary fields on every newly appended entry and refuses any
 * write that mutates or drops an already-persisted entry.
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

class ConsentEnvelopeOnSaveListenerTest extends TestCase {

	/** @var SchemaMapper&\PHPUnit\Framework\MockObject\MockObject */
	private $schemaMapper;

	/** @var IRequest&\PHPUnit\Framework\MockObject\MockObject */
	private $request;

	/** @var IUserSession&\PHPUnit\Framework\MockObject\MockObject */
	private $userSession;

	private ConsentEnvelopeOnSaveListener $listener;

	protected function setUp(): void {
		$this->schemaMapper = $this->createMock(originalClassName: SchemaMapper::class);
		$this->request = $this->createMock(originalClassName: IRequest::class);
		$this->request->method('getRemoteAddress')->willReturn('203.0.113.5');
		$this->request->method('getHeader')->willReturn('Mozilla/5.0 (test)');

		$this->userSession = $this->createMock(originalClassName: IUserSession::class);
		$user = $this->createMock(originalClassName: IUser::class);
		$user->method('getUID')->willReturn('guardian-42');
		$this->userSession->method('getUser')->willReturn($user);

		$this->listener = new ConsentEnvelopeOnSaveListener(
			$this->schemaMapper,
			$this->userSession,
			$this->request,
			$this->createMock(originalClassName: LoggerInterface::class)
		);
	}//end setUp()

	/**
	 * A schema whose `beeldmateriaalConsent` property is consent-shaped.
	 */
	private function consentSchema(): Schema {
		$schema = new Schema();
		$schema->setId(30);
		$schema->setProperties([
			'beeldmateriaalConsent' => [
				'type' => 'array',
				'x-openregister-consent' => ['purpose' => 'beeldmateriaal-gebruik'],
			],
		]);
		$this->schemaMapper->method('find')->willReturn($schema);

		return $schema;
	}//end consentSchema()

	private function objectWith(array $data): ObjectEntity {
		$object = new ObjectEntity();
		$object->setUuid('2a5c1a1e-5d1a-4d2f-9c6a-1f0f3c4b7e21');
		$object->setRegister('16');
		$object->setSchema('30');
		$object->setObject($data);

		return $object;
	}//end objectWith()

	public function testGrantingConsentFillsEvidenceFields(): void {
		$this->consentSchema();
		$object = $this->objectWith([
			'beeldmateriaalConsent' => [
				['subject' => 'learner-7', 'decision' => 'granted', 'evidenceOf' => 'beeldmateriaal-terms-v3'],
			],
		]);

		$this->listener->handle(new ObjectCreatingEvent($object));

		$entry = $object->getObject()['beeldmateriaalConsent'][0];
		$this->assertSame('guardian-42', $entry['by']);
		$this->assertSame('203.0.113.5', $entry['ip']);
		$this->assertSame('Mozilla/5.0 (test)', $entry['userAgent']);
		$this->assertNotEmpty($entry['timestamp']);
		$this->assertSame(
			hash('sha256', 'beeldmateriaal-gebruik' . 'granted' . 'beeldmateriaal-terms-v3'),
			$entry['contentHash']
		);
		$this->assertNull($entry['withdrawnAt']);
	}//end testGrantingConsentFillsEvidenceFields()

	public function testCallerSuppliedEvidenceFieldsAreOverwritten(): void {
		$this->consentSchema();
		$object = $this->objectWith([
			'beeldmateriaalConsent' => [
				[
					'decision' => 'granted',
					'evidenceOf' => 'v3',
					'timestamp' => '2000-01-01T00:00:00+00:00',
					'ip' => '10.0.0.1',
				],
			],
		]);

		$this->listener->handle(new ObjectCreatingEvent($object));

		$entry = $object->getObject()['beeldmateriaalConsent'][0];
		$this->assertNotSame('2000-01-01T00:00:00+00:00', $entry['timestamp']);
		$this->assertSame('203.0.113.5', $entry['ip']);
	}//end testCallerSuppliedEvidenceFieldsAreOverwritten()

	public function testWithdrawalSetsWithdrawnAt(): void {
		$this->consentSchema();
		$object = $this->objectWith([
			'beeldmateriaalConsent' => [
				['decision' => 'withdrawn', 'evidenceOf' => 'v3'],
			],
		]);

		$this->listener->handle(new ObjectCreatingEvent($object));

		$entry = $object->getObject()['beeldmateriaalConsent'][0];
		$this->assertNotNull($entry['withdrawnAt']);
		$this->assertSame($entry['timestamp'], $entry['withdrawnAt']);
	}//end testWithdrawalSetsWithdrawnAt()

	public function testEditingAnExistingEntryIsRefused(): void {
		$this->consentSchema();
		$persisted = $this->objectWith([
			'beeldmateriaalConsent' => [
				['decision' => 'granted', 'by' => 'guardian-42', 'timestamp' => 't1'],
			],
		]);
		$incoming = $this->objectWith([
			'beeldmateriaalConsent' => [
				['decision' => 'refused', 'by' => 'guardian-42', 'timestamp' => 't1'],
			],
		]);

		$event = new ObjectUpdatingEvent(newObject: $incoming, oldObject: $persisted);
		$this->listener->handle($event);

		$this->assertTrue($event->isPropagationStopped());
		$this->assertSame('consent-envelope-mutated', $event->getErrors()['code']);
		$this->assertSame('beeldmateriaalConsent', $event->getErrors()['property']);
	}//end testEditingAnExistingEntryIsRefused()

	public function testShorteningTheArrayIsRefused(): void {
		$this->consentSchema();
		$persisted = $this->objectWith([
			'beeldmateriaalConsent' => [
				['decision' => 'granted', 'by' => 'guardian-42', 'timestamp' => 't1'],
				['decision' => 'withdrawn', 'by' => 'guardian-42', 'timestamp' => 't2'],
			],
		]);
		$incoming = $this->objectWith([
			'beeldmateriaalConsent' => [
				['decision' => 'granted', 'by' => 'guardian-42', 'timestamp' => 't1'],
			],
		]);

		$event = new ObjectUpdatingEvent(newObject: $incoming, oldObject: $persisted);
		$this->listener->handle($event);

		$this->assertTrue($event->isPropagationStopped());
	}//end testShorteningTheArrayIsRefused()

	public function testAppendingBeyondPersistedLengthIsAllowed(): void {
		$this->consentSchema();
		$grantEntry = ['decision' => 'granted', 'by' => 'guardian-42', 'timestamp' => 't1', 'ip' => null, 'userAgent' => null, 'contentHash' => 'h1', 'withdrawnAt' => null];
		$persisted = $this->objectWith(['beeldmateriaalConsent' => [$grantEntry]]);
		$incoming = $this->objectWith([
			'beeldmateriaalConsent' => [
				$grantEntry,
				['decision' => 'withdrawn', 'evidenceOf' => 'v3'],
			],
		]);

		$event = new ObjectUpdatingEvent(newObject: $incoming, oldObject: $persisted);
		$this->listener->handle($event);

		$this->assertFalse($event->isPropagationStopped());
		$data = $incoming->getObject()['beeldmateriaalConsent'];
		$this->assertCount(2, $data);
		$this->assertSame('granted', $data[0]['decision']);
		$this->assertSame('withdrawn', $data[1]['decision']);
		$this->assertNotNull($data[1]['withdrawnAt']);
	}//end testAppendingBeyondPersistedLengthIsAllowed()

	public function testUnannotatedSchemaIsUntouched(): void {
		$schema = new Schema();
		$schema->setId(31);
		$schema->setProperties(['title' => ['type' => 'string']]);
		$this->schemaMapper->method('find')->willReturn($schema);

		$object = $this->objectWith(['title' => 'hello']);
		$original = $object->getObject();

		$this->listener->handle(new ObjectCreatingEvent($object));

		$this->assertSame($original, $object->getObject());
	}//end testUnannotatedSchemaIsUntouched()
}
