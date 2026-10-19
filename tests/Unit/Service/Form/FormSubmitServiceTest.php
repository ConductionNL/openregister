<?php

/**
 * A submit creates its destination in one request, all or none, and repeats itself on a retried key.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/form-destination-validator/specs/form-destination/spec.md#requirement-a-submit-must-create-the-destination-in-one-request-and-return-its-reference
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Form;

use DateTime;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Event\ObjectActivatedEvent;
use OCA\OpenRegister\Exception\CustomValidationException;
use OCA\OpenRegister\Exception\FormSubmitRefusedException;
use OCA\OpenRegister\Exception\NotAuthorizedException;
use OCA\OpenRegister\Service\File\FilePropertyRules;
use OCA\OpenRegister\Service\Form\FormIdempotencyStore;
use OCA\OpenRegister\Service\Form\FormPayloadFindings;
use OCA\OpenRegister\Service\Form\FormSubmitService;
use OCA\OpenRegister\Service\Form\FormUploadStore;
use OCA\OpenRegister\Service\Object\DraftOwner;
use OCA\OpenRegister\Service\Object\DraftStatusPolicy;
use OCA\OpenRegister\Service\Object\ValidateObject;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Tests\Unit\Service\Form\Fakes\MemoryAppData;
use OCA\OpenRegister\Tests\Unit\Service\Form\Fakes\MemoryLockingProvider;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IL10N;
use OCP\ITempManager;
use OCP\IUser;
use Opis\JsonSchema\ValidationResult;
use Opis\JsonSchema\Validator;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The submit contract against real stores, a real opis validator and a fake creating listener.
 *
 * @covers \OCA\OpenRegister\Service\Form\FormSubmitService
 * @uses \OCA\OpenRegister\Service\Form\FormPayloadFindings
 * @uses \OCA\OpenRegister\Service\Form\FormIdempotencyStore
 * @uses \OCA\OpenRegister\Service\Form\FormUploadStore
 * @uses \OCA\OpenRegister\Service\Form\FormDestinationValidator
 * @uses \OCA\OpenRegister\Service\File\FilePropertyRules
 * @uses \OCA\OpenRegister\Exception\FormSubmitRefusedException
 * @uses \OCA\OpenRegister\Db\Schema
 * @uses \OCA\OpenRegister\Db\Register
 * @uses \OCA\OpenRegister\Db\ObjectEntity
 * @uses \OCA\OpenRegister\Exception\CustomValidationException
 * @uses \OCA\OpenRegister\Exception\NotAuthorizedException
 * @uses \OCA\OpenRegister\Event\ObjectActivatedEvent
 * @uses \OCA\OpenRegister\Event\ObjectCreatedEvent
 * @uses \OCA\OpenRegister\Service\Object\ObjectEventGate
 * @uses \OCA\OpenRegister\Service\Object\DraftFinder
 * @uses \OCA\OpenRegister\Service\Object\DraftOwner
 * @uses \OCA\OpenRegister\Service\Object\DraftStatusPolicy
 * @uses \OCA\OpenRegister\Exception\DraftAlreadyLeftException
 * @uses \OCA\OpenRegister\Exception\ValidationException
 * @uses \OCA\OpenRegister\Service\Form\FormUploadClaims
 * @uses \OCA\OpenRegister\Service\Form\NestedIdentityGuard
 */
class FormSubmitServiceTest extends TestCase {

	private ObjectService&MockObject $objects;

	private FormUploadStore $uploads;

	private FormSubmitService $service;

	/**
	 * The objects "stored", by uuid, after the fake creating listener ran.
	 *
	 * @var array<string, ObjectEntity>
	 */
	private array $stored = [];

	/**
	 * Uuids deleted by compensation, in order.
	 *
	 * @var array<int, string>
	 */
	private array $deleted = [];

	/**
	 * What each saveObject call received.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $saves = [];

	/**
	 * Schemas by slug.
	 *
	 * @var array<string, Schema>
	 */
	private array $schemaBySlug = [];

	/**
	 * A save to refuse, by schema slug, with the throwable to raise.
	 *
	 * @var array<string, \Throwable>
	 */
	private array $refuseSave = [];

	/**
	 * Whether the destination refuses an anonymous create under RBAC.
	 *
	 * @var bool
	 */
	private bool $publicCreateRefused = false;

	/**
	 * @var array<int, string>
	 */
	private array $temporary = [];

	/**
	 * Searches the service ran, with their RBAC flags.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $searches = [];

	/**
	 * Events the service dispatched.
	 *
	 * @var array<int, Event>
	 */
	private array $dispatched = [];

	/**
	 * The locks the idempotency store holds.
	 */
	private MemoryLockingProvider $locks;

	/**
	 * Run inside the next save, to simulate a parallel request.
	 *
	 * @var callable|null
	 */
	private $duringSave = null;

	/**
	 * Whether the fake save skips the real status policy, as a save that never reached it would.
	 *
	 * @var bool
	 */
	private bool $saveSkipsStatusPolicy = false;

	protected function setUp(): void {
		DraftStatusPolicy::resetPromotions();
		$this->schemaBySlug = [
			'case' => $this->schema(
				id: 1,
				slug: 'case',
				required: ['title', 'caseType', 'identifier'],
				properties: [
					'title' => ['type' => 'string', 'maxLength' => 200],
					'caseType' => ['type' => 'string'],
					'description' => ['type' => 'string'],
					'identifier' => ['type' => 'string', 'x-openregister' => ['serverSet' => true, 'reference' => true, 'confirmation' => true]],
					'termStartsAt' => ['type' => 'string', 'x-openregister' => ['serverSet' => true, 'confirmation' => true]],
					'internalNote' => ['type' => 'string', 'x-openregister' => ['serverSet' => true]],
					'bijlage' => ['type' => 'file', 'maxSize' => 1024],
				]
			),
			'organisation' => $this->schema(id: 2, slug: 'organisation', required: ['name'], properties: ['name' => ['type' => 'string'], 'kvk' => ['type' => 'string']]),
			'contact' => $this->schema(
				id: 3,
				slug: 'contact',
				required: ['email', 'organisation'],
				properties: ['email' => ['type' => 'string'], 'organisation' => ['type' => 'string', 'format' => 'uuid']]
			),
		];

		$registers = $this->createMock(RegisterMapper::class);
		$registers->method('find')->willReturnCallback(
			static function (string|int $id): Register {
				$register = new Register();
				$register->setId(10);
				$register->setSlug((string)$id);

				return $register;
			}
		);

		$schemas = $this->createMock(SchemaMapper::class);
		$schemas->method('find')->willReturnCallback(
			function (string|int $id): Schema {
				if (isset($this->schemaBySlug[(string)$id]) === false) {
					throw new DoesNotExistException('no schema ' . $id);
				}

				return $this->schemaBySlug[(string)$id];
			}
		);

		$validateObject = $this->createMock(ValidateObject::class);
		$validateObject->method('validateObject')->willReturnCallback($this->opisValidate(...));

		$this->objects = $this->createMock(ObjectService::class);
		$this->objects->method('saveObject')->willReturnCallback($this->fakeSave(...));
		$this->objects->method('find')->willReturnCallback(fn (string|int $id): ?ObjectEntity => ($this->stored[(string)$id] ?? null));
		$this->objects->method('searchObjects')->willReturnCallback(
			function (array $query = [], bool $_rbac = true, bool $_multitenancy = true): array {
				$this->searches[] = ['query' => $query, 'rbac' => $_rbac, 'multitenancy' => $_multitenancy];
				$owner = ($query['@self']['owner'] ?? null);

				return array_values(array_filter($this->stored, static fn (ObjectEntity $entity): bool => $entity->getOwner() === $owner));
			}
		);
		$this->objects->method('deleteObject')->willReturnCallback(
			function (string $uuid): bool {
				$this->deleted[] = $uuid;
				unset($this->stored[$uuid]);

				return true;
			}
		);

		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn(1_760_000_000);
		$time->method('getDateTime')->willReturnCallback(static fn (): DateTime => new DateTime('2026-10-12T09:15:00+02:00'));
		$events = $this->createMock(IEventDispatcher::class);
		$events->method('dispatchTyped')->willReturnCallback(
			function (Event $event): void {
				$this->dispatched[] = $event;
			}
		);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(
			static fn (string $text, array $parameters = []): string => $parameters === [] ? $text : vsprintf($text, $parameters)
		);
		$temp = $this->createMock(ITempManager::class);
		$temp->method('getTemporaryFile')->willReturnCallback(
			function (): string {
				$path = (string)tempnam(sys_get_temp_dir(), 'or-submit-test');
				$this->temporary[] = $path;

				return $path;
			}
		);

		$appData = new MemoryAppData();
		$this->locks = new MemoryLockingProvider();
		$this->uploads = new FormUploadStore(appData: $appData, time: $time, temp: $temp, rules: new FilePropertyRules(), l10n: $l10n);

		$this->service = new FormSubmitService(
			registers: $registers,
			schemas: $schemas,
			validator: $validateObject,
			objects: $this->objects,
			findings: new FormPayloadFindings(),
			keys: new FormIdempotencyStore(appData: $appData, time: $time, locks: $this->locks, l10n: $l10n),
			uploads: $this->uploads,
			l10n: $l10n,
			logger: $this->createMock(LoggerInterface::class),
			events: $events,
			time: $time
		);
	}//end setUp()

	protected function tearDown(): void {
		foreach ($this->temporary as $path) {
			if (is_file($path) === true) {
				unlink($path);
			}
		}
	}//end tearDown()

	/**
	 * A schema entity.
	 *
	 * @param int                  $id         The id.
	 * @param string               $slug       The slug.
	 * @param array<int, string>   $required   Required properties.
	 * @param array<string, mixed> $properties Properties.
	 */
	private function schema(int $id, string $slug, array $required, array $properties): Schema {
		$schema = new Schema();
		$schema->setId($id);
		$schema->setSlug($slug);
		$schema->setRequired($required);
		$schema->setProperties($properties);
		$schema->setHardValidation(false);

		return $schema;
	}//end schema()

	/**
	 * A real opis validation of the object against the schema, honouring notSupplied excusals.
	 *
	 * @param array<string, mixed>  $object      The object.
	 * @param Schema|int|string|null $schema     The schema.
	 * @param object                $schemaObject Unused.
	 * @param int                   $_depth      Unused.
	 * @param array<string, string> $notSupplied Excused properties.
	 */
	private function opisValidate(array $object, Schema|int|string|null $schema = null, object $schemaObject = new \stdClass(), int $_depth = 0, array $notSupplied = []): ValidationResult {
		$this->assertInstanceOf(Schema::class, $schema);
		$properties = [];
		foreach ($schema->getProperties() as $name => $property) {
			$property = array_diff_key($property, ['x-openregister' => true]);
			if (($property['type'] ?? null) === 'file') {
				$property = new \stdClass();
			}

			$properties[$name] = $property;
		}

		$definition = [
			'type' => 'object',
			'required' => array_values(array_diff($schema->getRequired(), array_keys($notSupplied))),
			'properties' => $properties,
		];
		$validator = new Validator();
		$validator->setMaxErrors(10);

		return $validator->validate(json_decode((string)json_encode($object)), json_decode((string)json_encode($definition)));
	}//end opisValidate()

	/**
	 * The save, with a fake creating listener that stamps identifier and termStartsAt on a case.
	 *
	 * @param array<string, mixed>|ObjectEntity $object The object.
	 */
	private function fakeSave(
		array|ObjectEntity $object,
		?array $extend = [],
		Register|string|int|null $register = null,
		Schema|string|int|null $schema = null,
		?string $uuid = null,
		bool $_rbac = true,
		bool $_multitenancy = true,
		bool $silent = false,
		bool $_validation = true,
		?array $uploadedFiles = null,
		?IUser $currentUser = null,
		bool $failIfExists = false,
		bool $_unowned = false,
		bool $_dedupOverride = false,
		?string $_owner = null,
	): ObjectEntity {
		$this->assertInstanceOf(Schema::class, $schema);
		if ($this->duringSave !== null) {
			$hook = $this->duringSave;
			$this->duringSave = null;
			$hook();
		}

		$this->saves[] = ['object' => $object, 'schema' => $schema->getSlug(), 'rbac' => $_rbac, 'user' => $currentUser, 'unowned' => $_unowned, 'files' => $uploadedFiles, 'uuid' => $uuid, 'owner' => $_owner];
		if (isset($this->refuseSave[$schema->getSlug()]) === true) {
			throw $this->refuseSave[$schema->getSlug()];
		}

		// A destination whose schema does not let the public create: the save
		// refuses an anonymous create under RBAC, as SaveObject does.
		if ($this->publicCreateRefused === true && $_rbac === true && $currentUser === null && $uuid === null) {
			throw new NotAuthorizedException(message: 'The public may not create this object.');
		}

		$data = (array)$object;
		$status = ($data['@self']['status'] ?? null);
		unset($data['@self']);

		// The real status policy judges a save of a stored object, as SaveObject
		// does: a promotion out of draft is allowed once, and an allowance for an
		// object that is no longer a draft is refused.
		$storedBefore = ($this->stored[(string)$uuid] ?? null);
		if ($storedBefore !== null && $status !== null && $this->saveSkipsStatusPolicy === false) {
			$storedBefore->setId(1);
			(new DraftStatusPolicy())->resolveStatus(entity: $storedBefore, selfData: ['status' => $status]);
		}

		if ($status === 'draft') {
			// The fake listener stamps receipt fields only on a received object.
			$entity = new ObjectEntity();
			$entity->setUuid($uuid ?? ('uuid-draft-' . count($this->saves)));
			$entity->setObject($data);
			$entity->setStatus('draft');
			// An update keeps the stored owner, as SaveObject's update path does.
			$entity->setOwner(($this->stored[(string)$uuid] ?? null)?->getOwner() ?? $_owner ?? $currentUser?->getUID());
			$entity->setCreated(new DateTime('2026-10-10T14:03:11+02:00'));
			$this->stored[$entity->getUuid()] = $entity;

			return $entity;
		}

		if ($schema->getSlug() === 'case') {
			// The fake ObjectCreatingEvent listener.
			$data['identifier'] = '2026-0412';
			$data['termStartsAt'] = '2026-10-13T09:00:00+02:00';
			$data['internalNote'] = 'not for the resident';
		}

		$entity = new ObjectEntity();
		$entity->setUuid($uuid ?? ('uuid-' . $schema->getSlug() . '-' . count($this->saves)));
		$entity->setObject($data);
		$entity->setStatus($status);
		$entity->setOwner(($this->stored[(string)$uuid] ?? null)?->getOwner() ?? $_owner ?? $currentUser?->getUID());
		$entity->setCreated(new DateTime('2026-10-10T14:03:11+02:00'));
		$this->stored[$entity->getUuid()] = $entity;

		return $entity;
	}//end fakeSave()

	/**
	 * Spec scenario: the response carries the listener-computed fields, and only marked ones.
	 */
	public function testTheResponseCarriesTheListenerComputedFields(): void {
		$answer = $this->service->submit(
			destination: ['register' => 'dossiq', 'schema' => 'case'],
			mapping: ['fields' => [['field' => 'onderwerp', 'property' => 'title']], 'fixed' => ['caseType' => 'ct-1']],
			payload: ['onderwerp' => 'Kapvergunning', 'sneaky' => 'dropped']
		);

		$this->assertSame('2026-0412', $answer['reference']);
		$this->assertSame('uuid-case-1', $answer['id']);
		$this->assertSame('2026-10-10T14:03:11+02:00', $answer['receivedAt']);
		$this->assertSame(['identifier' => '2026-0412', 'termStartsAt' => '2026-10-13T09:00:00+02:00'], $answer['confirmation']);
		// Control: a property the listener set WITHOUT the confirmation marker is not returned.
		$this->assertArrayNotHasKey('internalNote', $answer['confirmation']);
		// Only mapped fields and fixed values reach the object; the resident does not pick the scope.
		$this->assertSame(['title' => 'Kapvergunning', 'caseType' => 'ct-1'], $this->saves[0]['object']);
	}//end testTheResponseCarriesTheListenerComputedFields()

	/**
	 * An anonymous submit saves under RBAC with no owner; a signed-in subject saves as that user.
	 */
	public function testTheSubjectDecidesRbacAndOwnership(): void {
		$this->service->submit(destination: ['register' => 'dossiq', 'schema' => 'case'], mapping: null, payload: ['title' => 'a', 'caseType' => 'b']);
		$this->assertTrue($this->saves[0]['rbac']);
		$this->assertTrue($this->saves[0]['unowned']);
		$this->assertNull($this->saves[0]['user']);

		$user = $this->createMock(IUser::class);
		$this->service->submit(destination: ['register' => 'dossiq', 'schema' => 'case'], mapping: null, payload: ['title' => 'a', 'caseType' => 'b'], subject: $user);
		$this->assertTrue($this->saves[1]['rbac']);
		$this->assertFalse($this->saves[1]['unowned']);
		$this->assertSame($user, $this->saves[1]['user']);
	}//end testTheSubjectDecidesRbacAndOwnership()

	/**
	 * Without a mapping the payload is the object, minus control and metadata keys.
	 */
	public function testWithoutAMappingThePayloadIsTheObject(): void {
		$this->service->submit(
			destination: ['register' => 'dossiq', 'schema' => 'case'],
			mapping: null,
			payload: ['title' => 'a', 'caseType' => 'b', '_rbac' => false, '@self' => ['owner' => 'x']]
		);

		$this->assertSame(['title' => 'a', 'caseType' => 'b'], $this->saves[0]['object']);
	}//end testWithoutAMappingThePayloadIsTheObject()

	/**
	 * Spec scenario: a schema without hard validation is still validated, and nothing is created.
	 */
	public function testASchemaWithoutHardValidationIsStillValidated(): void {
		$this->assertFalse($this->schemaBySlug['case']->getHardValidation());

		try {
			$this->service->submit(destination: ['register' => 'dossiq', 'schema' => 'case'], mapping: null, payload: ['title' => 'a']);
			$this->fail('A payload missing a required property was accepted.');
		} catch (FormSubmitRefusedException $refused) {
			$this->assertSame(422, $refused->getStatus());
			$this->assertSame('caseType', $refused->getFindings()[0]['property']);
			$this->assertSame('required', $refused->getFindings()[0]['code']);
		}

		$this->assertSame([], $this->saves);
	}//end testASchemaWithoutHardValidationIsStillValidated()

	/**
	 * Spec scenario: a repeated idempotency key repeats the answer and creates nothing.
	 */
	public function testARepeatedIdempotencyKeyRepeatsTheAnswer(): void {
		$first = $this->service->submit(destination: ['register' => 'dossiq', 'schema' => 'case'], mapping: null, payload: ['title' => 'a', 'caseType' => 'b'], idempotencyKey: 'k1', scope: 'form-1');
		$second = $this->service->submit(destination: ['register' => 'dossiq', 'schema' => 'case'], mapping: null, payload: ['title' => 'a', 'caseType' => 'b'], idempotencyKey: 'k1', scope: 'form-1');

		$this->assertSame($first, $second);
		$this->assertCount(1, $this->saves);

		$this->service->submit(destination: ['register' => 'dossiq', 'schema' => 'case'], mapping: null, payload: ['title' => 'a', 'caseType' => 'b'], idempotencyKey: 'k2', scope: 'form-1');
		$this->assertCount(2, $this->saves);
	}//end testARepeatedIdempotencyKeyRepeatsTheAnswer()

	/**
	 * The key is client-chosen: another user, or another payload, with the same key never gets the first answer.
	 */
	public function testAnIdempotencyKeyDoesNotHandTheAnswerToSomebodyElse(): void {
		$destination = ['register' => 'dossiq', 'schema' => 'case'];
		$alice = $this->createMock(IUser::class);
		$alice->method('getUID')->willReturn('alice');
		$bob = $this->createMock(IUser::class);
		$bob->method('getUID')->willReturn('bob');
		$payload = ['title' => 'a', 'caseType' => 'b'];

		$first = $this->service->submit(destination: $destination, mapping: null, payload: $payload, subject: $alice, idempotencyKey: 'k1', scope: 'form-1');

		foreach ([[$bob, $payload], [null, $payload], [$alice, ['title' => 'other', 'caseType' => 'b']]] as [$subject, $body]) {
			try {
				$this->service->submit(destination: $destination, mapping: null, payload: $body, subject: $subject, idempotencyKey: 'k1', scope: 'form-1');
				$this->fail('The key handed over the first answer.');
			} catch (FormSubmitRefusedException $refused) {
				$this->assertSame(409, $refused->getStatus());
				$this->assertStringNotContainsString((string)$first['reference'], $refused->getMessage());
			}
		}

		$this->assertCount(1, $this->saves);
		$this->assertSame($first, $this->service->submit(destination: $destination, mapping: null, payload: $payload, subject: $alice, idempotencyKey: 'k1', scope: 'form-1'));
	}//end testAnIdempotencyKeyDoesNotHandTheAnswerToSomebodyElse()

	/**
	 * Two submits in flight with one key create one object; the key is free again afterwards.
	 */
	public function testTwoConcurrentSubmitsWithOneKeyCreateOneObject(): void {
		$destination = ['register' => 'dossiq', 'schema' => 'case'];
		$payload = ['title' => 'a', 'caseType' => 'b'];
		$status = null;
		// While the first submit is writing, the same request arrives again.
		$this->duringSave = function () use ($destination, $payload, &$status): void {
			try {
				$this->service->submit(destination: $destination, mapping: null, payload: $payload, idempotencyKey: 'k1', scope: 'form-1');
			} catch (FormSubmitRefusedException $refused) {
				$status = $refused->getStatus();
			}
		};

		$first = $this->service->submit(destination: $destination, mapping: null, payload: $payload, idempotencyKey: 'k1', scope: 'form-1');

		$this->assertSame(409, $status);
		$this->assertCount(1, $this->saves);
		$this->assertSame([], $this->locks->held);
		$this->assertSame($first, $this->service->submit(destination: $destination, mapping: null, payload: $payload, idempotencyKey: 'k1', scope: 'form-1'));
	}//end testTwoConcurrentSubmitsWithOneKeyCreateOneObject()

	/**
	 * A refused submit releases its key, so the corrected retry is not locked out.
	 */
	public function testARefusedSubmitReleasesItsKey(): void {
		try {
			$this->service->submit(destination: ['register' => 'dossiq', 'schema' => 'case'], mapping: null, payload: ['title' => 'a'], idempotencyKey: 'k1', scope: 'form-1');
			$this->fail('An incomplete submit was accepted.');
		} catch (FormSubmitRefusedException $refused) {
			$this->assertSame(422, $refused->getStatus());
		}

		$this->assertSame([], $this->locks->held);
		$this->service->submit(destination: ['register' => 'dossiq', 'schema' => 'case'], mapping: null, payload: ['title' => 'a', 'caseType' => 'b'], idempotencyKey: 'k1', scope: 'form-1');
		$this->assertCount(1, $this->saves);
	}//end testARefusedSubmitReleasesItsKey()

	/**
	 * Every write is validated before the first: an invalid second write means no write at all.
	 */
	public function testEveryWriteIsValidatedBeforeTheFirst(): void {
		try {
			$this->service->submitAll(
				writes: $this->organisationThenContact(),
				payload: ['naam' => 'Bakkerij De Korst']
			);
			$this->fail('An invalid second write was accepted.');
		} catch (FormSubmitRefusedException $refused) {
			$this->assertSame(422, $refused->getStatus());
			$this->assertSame('contact', $refused->getFindings()[0]['write']);
			$this->assertSame('email', $refused->getFindings()[0]['property']);
		}

		$this->assertSame([], $this->saves);
	}//end testEveryWriteIsValidatedBeforeTheFirst()

	/**
	 * A later write references an earlier write's id.
	 */
	public function testALaterWriteReferencesAnEarlierWritesId(): void {
		$answer = $this->service->submitAll(writes: $this->organisationThenContact(), payload: ['naam' => 'De Korst', 'mail' => 'info@dekorst.nl']);

		$this->assertSame('uuid-organisation-1', $this->saves[1]['object']['organisation']);
		$this->assertSame(['organisation', 'contact'], array_column($answer['objects'], 'as'));
		$this->assertSame('uuid-organisation-1', $answer['id']);
	}//end testALaterWriteReferencesAnEarlierWritesId()

	/**
	 * Spec scenario: a refused second write deletes the first, and the answer names the contact finding.
	 */
	public function testARefusedSecondWriteDeletesTheFirst(): void {
		$this->refuseSave['contact'] = new CustomValidationException(
			message: 'Fields are not unique: email',
			errors: ['email' => 'The identifying fields (email) are not unique.']
		);

		try {
			$this->service->submitAll(writes: $this->organisationThenContact(), payload: ['naam' => 'De Korst', 'mail' => 'info@dekorst.nl']);
			$this->fail('A refused second write was reported as success.');
		} catch (FormSubmitRefusedException $refused) {
			$this->assertSame(422, $refused->getStatus());
			$this->assertSame(['property' => 'email', 'code' => 'unique', 'message' => 'The identifying fields (email) are not unique.', 'write' => 'contact'], $refused->getFindings()[0]);
		}

		$this->assertSame(['uuid-organisation-1'], $this->deleted);
		$this->assertArrayNotHasKey('uuid-organisation-1', $this->stored);
	}//end testARefusedSecondWriteDeletesTheFirst()

	/**
	 * Q3: a destination that cannot be reached is a 503 "try again later", and nothing stays behind.
	 */
	public function testAnOutageIsATryAgainLaterAndLeavesNothing(): void {
		$this->refuseSave['contact'] = new RuntimeException('SQLSTATE[HY000] [2002] Connection refused');

		try {
			$this->service->submitAll(writes: $this->organisationThenContact(), payload: ['naam' => 'De Korst', 'mail' => 'info@dekorst.nl']);
			$this->fail('An outage was reported as success.');
		} catch (FormSubmitRefusedException $refused) {
			$this->assertSame(503, $refused->getStatus());
			$this->assertStringNotContainsString('SQLSTATE', $refused->getMessage());
		}

		$this->assertSame(['uuid-organisation-1'], $this->deleted);
	}//end testAnOutageIsATryAgainLaterAndLeavesNothing()

	/**
	 * A subject the destination does not let create is a 403.
	 */
	public function testARefusedPermissionIsA403(): void {
		$this->refuseSave['case'] = new NotAuthorizedException(message: 'no create');

		$this->expectException(FormSubmitRefusedException::class);
		$this->expectExceptionCode(0);
		try {
			$this->service->submit(destination: ['register' => 'dossiq', 'schema' => 'case'], mapping: null, payload: ['title' => 'a', 'caseType' => 'b']);
		} catch (FormSubmitRefusedException $refused) {
			$this->assertSame(403, $refused->getStatus());
			throw $refused;
		}
	}//end testARefusedPermissionIsA403()

	/**
	 * A destination that does not exist is a 404, before anything is validated.
	 */
	public function testAnUnknownDestinationIsA404(): void {
		try {
			$this->service->submit(destination: ['register' => 'dossiq', 'schema' => 'nope'], mapping: null, payload: []);
			$this->fail('An unknown destination was accepted.');
		} catch (FormSubmitRefusedException $refused) {
			$this->assertSame(404, $refused->getStatus());
		}
	}//end testAnUnknownDestinationIsA404()

	/**
	 * An upload token lands on the object and is claimed; after a refusal it stays for the retry.
	 */
	public function testAnUploadTokenIsClaimedOnlyOnSuccess(): void {
		$path = (string)tempnam(sys_get_temp_dir(), 'or-submit-upload');
		file_put_contents($path, '%PDF-1.7');
		$this->temporary[] = $path;
		$token = $this->uploads->issue(
			formId: 'form-1',
			property: 'bijlage',
			rule: $this->schemaBySlug['case']->getProperties()['bijlage'],
			file: ['name' => 'a.pdf', 'type' => 'application/pdf', 'tmp_name' => $path, 'error' => UPLOAD_ERR_OK, 'size' => 8]
		)['token'];
		$mapping = ['fields' => [['field' => 'onderwerp', 'property' => 'title'], ['field' => 'bewijs', 'property' => 'bijlage']], 'fixed' => ['caseType' => 'ct']];

		// Refused first: the token survives.
		$this->refuseSave['case'] = new CustomValidationException(message: 'x', errors: ['title' => 'nope']);
		try {
			$this->service->submit(destination: ['register' => 'dossiq', 'schema' => 'case'], mapping: $mapping, payload: ['onderwerp' => 'a', 'bewijs' => ['uploadToken' => $token]], scope: 'form-1');
			$this->fail('Refused save accepted.');
		} catch (FormSubmitRefusedException) {
			$this->assertSame('a.pdf', $this->uploads->materialise(formId: 'form-1', token: $token)['name']);
		}

		unset($this->refuseSave['case']);
		$this->service->submit(destination: ['register' => 'dossiq', 'schema' => 'case'], mapping: $mapping, payload: ['onderwerp' => 'a', 'bewijs' => ['uploadToken' => $token]], scope: 'form-1');

		$files = $this->saves[1]['files'];
		$this->assertSame('a.pdf', $files['bijlage']['name']);
		$this->assertSame('%PDF-1.7', file_get_contents($files['bijlage']['tmp_name']));
		$this->assertArrayNotHasKey('bijlage', $this->saves[1]['object']);

		$this->expectException(FormSubmitRefusedException::class);
		$this->uploads->materialise(formId: 'form-1', token: $token);
	}//end testAnUploadTokenIsClaimedOnlyOnSuccess()

	/**
	 * A required file property is satisfied by its upload token, and still `required` without one.
	 */
	public function testARequiredFilePropertyIsSatisfiedByAnUploadToken(): void {
		$case = $this->schemaBySlug['case'];
		$case->setRequired(['title', 'caseType', 'identifier', 'bijlage']);
		$path = (string)tempnam(sys_get_temp_dir(), 'or-submit-upload');
		file_put_contents($path, '%PDF-1.7');
		$this->temporary[] = $path;
		$token = $this->uploads->issue(
			formId: 'form-1',
			property: 'bijlage',
			rule: $case->getProperties()['bijlage'],
			file: ['name' => 'a.pdf', 'type' => 'application/pdf', 'tmp_name' => $path, 'error' => UPLOAD_ERR_OK, 'size' => 8]
		)['token'];
		$mapping = ['fields' => [['field' => 'onderwerp', 'property' => 'title'], ['field' => 'bewijs', 'property' => 'bijlage']], 'fixed' => ['caseType' => 'ct']];
		$destination = ['register' => 'dossiq', 'schema' => 'case'];

		try {
			$this->service->submit(destination: $destination, mapping: $mapping, payload: ['onderwerp' => 'a'], scope: 'form-1');
			$this->fail('A required file property without an upload was accepted.');
		} catch (FormSubmitRefusedException $refused) {
			$this->assertSame(422, $refused->getStatus());
			$this->assertContains('bijlage', array_map(static fn (array $finding): string => (string)($finding['property'] ?? ''), $refused->getFindings()));
		}

		$this->service->submit(destination: $destination, mapping: $mapping, payload: ['onderwerp' => 'a', 'bewijs' => ['uploadToken' => $token]], scope: 'form-1');
		$this->assertSame('a.pdf', $this->saves[0]['files']['bijlage']['name']);
	}//end testARequiredFilePropertyIsSatisfiedByAnUploadToken()

	/**
	 * A token issued for another property is refused, not attached elsewhere.
	 */
	public function testATokenForAnotherPropertyIsRefused(): void {
		$path = (string)tempnam(sys_get_temp_dir(), 'or-submit-upload');
		file_put_contents($path, 'x');
		$this->temporary[] = $path;
		$token = $this->uploads->issue(formId: 'form-1', property: 'bijlage', rule: ['type' => 'file'], file: ['name' => 'a', 'type' => 'text/plain', 'tmp_name' => $path, 'error' => 0, 'size' => 1])['token'];

		$this->expectException(FormSubmitRefusedException::class);
		$this->service->submit(
			destination: ['register' => 'dossiq', 'schema' => 'case'],
			mapping: ['fields' => [['field' => 'x', 'property' => 'description']], 'fixed' => ['title' => 't', 'caseType' => 'c']],
			payload: ['x' => ['uploadToken' => $token]],
			scope: 'form-1'
		);
	}//end testATokenForAnotherPropertyIsRefused()

	/**
	 * The two-write journey the spec's all-or-none scenario uses.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function organisationThenContact(): array {
		return [
			[
				'as' => 'organisation',
				'register' => 'crm',
				'schema' => 'organisation',
				'mapping' => ['fields' => [['field' => 'naam', 'property' => 'name']]],
			],
			[
				'as' => 'contact',
				'register' => 'crm',
				'schema' => 'contact',
				'mapping' => ['fields' => [['field' => 'mail', 'property' => 'email']], 'fixed' => ['organisation' => ['$write' => 'organisation']]],
			],
		];
	}//end organisationThenContact()
	/**
	 * Spec scenario: a draft without required data is saved, in status draft, with no receipt.
	 *
	 * @spec openspec/changes/form-destination-validator/specs/form-destination/spec.md#requirement-an-object-must-be-able-to-carry-the-explicit-lifecycle-status-draft
	 */
	public function testADraftWithoutRequiredDataIsSaved(): void {
		$user = $this->user(uid: 'alice');
		$draft = $this->service->saveDraft(destination: ['register' => 'dossiq', 'schema' => 'case'], mapping: null, payload: ['title' => 'Half af'], subject: $user);

		$this->assertSame('draft', $draft['status']);
		$this->assertSame('draft', $this->saves[0]['object']['@self']['status']);
		$this->assertTrue($this->stored[$draft['id']]->isDraft());
		$this->assertArrayNotHasKey('termStartsAt', $this->stored[$draft['id']]->getObject());
		$this->assertSame([], $this->dispatched);
	}//end testADraftWithoutRequiredDataIsSaved()

	/**
	 * A signed-in user with a uid.
	 */
	private function user(string $uid): IUser {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);

		return $user;
	}//end user()

	/**
	 * Somebody else's draft is not found, even by its id: the same 404 as an unknown one.
	 */
	public function testSomebodyElsesDraftIsA404(): void {
		$draft = $this->service->saveDraft(destination: ['register' => 'dossiq', 'schema' => 'case'], mapping: null, payload: ['title' => 'Van Alice'], subject: $this->user(uid: 'alice'));

		$this->assertTrue($this->service->isOwnerOfDraft(destination: ['register' => 'dossiq', 'schema' => 'case'], draftId: $draft['id'], subject: $this->user(uid: 'alice')));
		$this->assertFalse($this->service->isOwnerOfDraft(destination: ['register' => 'dossiq', 'schema' => 'case'], draftId: $draft['id'], subject: $this->user(uid: 'bob')));

		$this->expectException(FormSubmitRefusedException::class);
		$this->service->submit(destination: ['register' => 'dossiq', 'schema' => 'case'], mapping: null, payload: ['caseType' => 'x'], subject: $this->user(uid: 'bob'), draftId: $draft['id']);
	}//end testSomebodyElsesDraftIsA404()

	/**
	 * Spec scenario: a type-invalid draft is refused and nothing is stored.
	 */
	public function testATypeInvalidDraftIsRefused(): void {
		try {
			$this->service->saveDraft(destination: ['register' => 'dossiq', 'schema' => 'case'], mapping: null, payload: ['title' => ['not', 'a', 'string']], subject: $this->user(uid: 'alice'));
			$this->fail('A type-invalid draft was saved.');
		} catch (FormSubmitRefusedException $refused) {
			$this->assertSame(422, $refused->getStatus());
			$this->assertSame('type', $refused->getFindings()[0]['code']);
		}

		$this->assertSame([], $this->saves);
	}//end testATypeInvalidDraftIsRefused()

	/**
	 * An anonymous visitor cannot save a draft (no way to find it again without an account).
	 */
	public function testAnAnonymousDraftIs401(): void {
		$this->expectException(FormSubmitRefusedException::class);
		try {
			$this->service->saveDraft(destination: ['register' => 'dossiq', 'schema' => 'case'], mapping: null, payload: [], subject: null);
		} catch (FormSubmitRefusedException $refused) {
			$this->assertSame(401, $refused->getStatus());
			throw $refused;
		}
	}//end testAnAnonymousDraftIs401()

	/**
	 * Spec scenario: leaving draft runs full validation and stamps receipt.
	 */
	public function testLeavingDraftRunsFullValidationAndStampsReceipt(): void {
		$user = $this->user(uid: 'alice');
		$draft = $this->service->saveDraft(destination: ['register' => 'dossiq', 'schema' => 'case'], mapping: null, payload: ['title' => 'Kapvergunning'], subject: $user);

		$answer = $this->service->submit(destination: ['register' => 'dossiq', 'schema' => 'case'], mapping: null, payload: ['caseType' => 'ct-1'], subject: $user, draftId: $draft['id']);

		$promotion = $this->saves[1];
		$this->assertSame($draft['id'], $promotion['uuid']);
		$this->assertSame('active', $promotion['object']['@self']['status']);
		$this->assertSame('Kapvergunning', $promotion['object']['title']);
		$this->assertSame('2026-10-12T09:15:00+02:00', $answer['receivedAt']);
		$this->assertSame('2026-0412', $answer['reference']);
		$this->assertArrayHasKey('termStartsAt', $answer['confirmation']);
		// Leaving draft is the object's creation (decision 227): the created event once, then the receipt.
		$this->assertCount(2, $this->dispatched);
		$this->assertSame(\OCA\OpenRegister\Event\ObjectCreatedEvent::class, get_class($this->dispatched[0]));
		$this->assertInstanceOf(ObjectActivatedEvent::class, $this->dispatched[1]);
	}//end testLeavingDraftRunsFullValidationAndStampsReceipt()

	/**
	 * A draft still missing required data cannot leave draft, and stays a draft.
	 */
	public function testAnIncompleteDraftCannotLeaveDraft(): void {
		$user = $this->user(uid: 'alice');
		$draft = $this->service->saveDraft(destination: ['register' => 'dossiq', 'schema' => 'case'], mapping: null, payload: ['title' => 'Half af'], subject: $user);

		try {
			$this->service->submit(destination: ['register' => 'dossiq', 'schema' => 'case'], mapping: null, payload: [], subject: $user, draftId: $draft['id']);
			$this->fail('An incomplete draft left draft.');
		} catch (FormSubmitRefusedException $refused) {
			$this->assertSame(422, $refused->getStatus());
			$this->assertSame('caseType', $refused->getFindings()[0]['property']);
		}

		$this->assertCount(1, $this->saves);
		$this->assertTrue($this->stored[$draft['id']]->isDraft());
		$this->assertSame([], $this->dispatched);
	}//end testAnIncompleteDraftCannotLeaveDraft()

	/**
	 * A draft id that is not a draft, or not found under the subject's RBAC, is a 404.
	 */
	public function testAnUnknownDraftIsA404(): void {
		$this->service->submit(destination: ['register' => 'dossiq', 'schema' => 'case'], mapping: null, payload: ['title' => 'a', 'caseType' => 'b']);

		foreach (['uuid-case-1', 'nope'] as $id) {
			try {
				$this->service->submit(destination: ['register' => 'dossiq', 'schema' => 'case'], mapping: null, payload: ['title' => 'a', 'caseType' => 'b'], subject: $this->user(uid: 'alice'), draftId: $id);
				$this->fail('Draft ' . $id . ' was accepted.');
			} catch (FormSubmitRefusedException $refused) {
				$this->assertSame(404, $refused->getStatus());
			}
		}
	}//end testAnUnknownDraftIsA404()

	/**
	 * Spec scenario: a portal subject saves, reads, finds and sends its own draft (decision 195).
	 *
	 * @spec openspec/changes/form-destination-validator/specs/form-destination/spec.md#requirement-a-draft-must-be-able-to-be-owned-by-a-scope-value
	 */
	public function testAPortalSubjectSavesReadsFindsAndSendsItsOwnDraft(): void {
		$destination = ['register' => 'dossiq', 'schema' => 'case'];
		$owner = DraftOwner::portalSubject(subjectRef: 'subject-1');

		$draft = $this->service->saveDraft(destination: $destination, mapping: null, payload: ['title' => 'Half af'], subject: null, owner: $owner);

		$this->assertSame('draft', $draft['status']);
		$this->assertSame('portal:subject-1', $this->saves[0]['owner']);
		$this->assertNull($this->saves[0]['user']);
		$this->assertFalse($this->saves[0]['unowned']);
		$this->assertFalse($this->saves[0]['rbac'], 'Starting a scoped draft is authorised by the owner assertion, not by the public create rule.');
		$this->assertSame('portal:subject-1', $this->stored[$draft['id']]->getOwner());

		$read = $this->service->readDraft(destination: $destination, draftId: $draft['id'], subject: null, owner: $owner);
		$this->assertSame(['id' => $draft['id'], 'status' => 'draft', 'data' => ['title' => 'Half af'], 'updatedAt' => '2026-10-10T14:03:11+02:00'], $read);

		$found = $this->service->findDraft(destination: $destination, subject: null, owner: $owner, match: ['title' => 'Half af']);
		$this->assertSame($draft['id'], $found['id'] ?? null);
		$this->assertSame('portal:subject-1', $this->searches[0]['query']['@self']['owner']);
		$this->assertNull($this->service->findDraft(destination: $destination, subject: null, owner: $owner, match: ['title' => 'Anders']));

		$this->service->saveDraft(destination: $destination, mapping: null, payload: ['description' => 'Meer'], subject: null, draftId: $draft['id'], owner: $owner);
		$this->assertFalse($this->saves[1]['rbac'], 'Updating an own scoped draft is authorised by the owner check, not by a user RBAC no subject has.');
		$this->assertSame(['title' => 'Half af', 'description' => 'Meer'], $this->service->readDraft(destination: $destination, draftId: $draft['id'], subject: null, owner: $owner)['data']);

		$answer = $this->service->submit(destination: $destination, mapping: null, payload: ['caseType' => 'ct-1'], subject: null, draftId: $draft['id'], owner: $owner);
		$this->assertSame('active', $this->saves[2]['object']['@self']['status']);
		$this->assertSame($draft['id'], $this->saves[2]['uuid']);
		$this->assertSame('2026-0412', $answer['reference']);
		$this->assertFalse($this->stored[$draft['id']]->isDraft());
	}//end testAPortalSubjectSavesReadsFindsAndSendsItsOwnDraft()

	/**
	 * Spec scenario: a scope-owned draft starts where the public may not create.
	 *
	 * The owner assertion authorises the draft, as it authorises its update and
	 * its send; the destination schema does not have to allow public create.
	 *
	 * @spec openspec/changes/form-destination-validator/specs/form-destination/spec.md#requirement-a-draft-must-be-able-to-be-owned-by-a-scope-value
	 */
	public function testAScopeOwnedDraftStartsWhereThePublicMayNotCreate(): void {
		$this->publicCreateRefused = true;
		$destination = ['register' => 'dossiq', 'schema' => 'case'];
		$owner = DraftOwner::portalSubject(subjectRef: 'subject-1');

		$draft = $this->service->saveDraft(destination: $destination, mapping: null, payload: ['title' => 'Half af'], subject: null, owner: $owner);

		$this->assertSame('draft', $draft['status']);
		$this->assertSame('portal:subject-1', $this->stored[$draft['id']]->getOwner());
		$this->assertFalse($this->saves[0]['rbac']);
		$this->assertSame('draft', $this->saves[0]['object']['@self']['status'], 'The owner assertion authorises a draft, never an object that skips draft.');

		$answer = $this->service->submit(destination: $destination, mapping: null, payload: ['caseType' => 'ct-1'], subject: null, draftId: $draft['id'], owner: $owner);
		$this->assertSame($draft['id'], $answer['id']);
		$this->assertFalse($this->stored[$draft['id']]->isDraft());
	}//end testAScopeOwnedDraftStartsWhereThePublicMayNotCreate()

	/**
	 * The control: without an owner assertion the same destination still refuses.
	 *
	 * A fresh anonymous submit stays under the public create rule (403), an
	 * owner asserted on a fresh submit changes nothing (it names a draft's
	 * owner, and there is no draft), and nobody at all saves no draft (401).
	 *
	 * @spec openspec/changes/form-destination-validator/specs/form-destination/spec.md#requirement-a-draft-must-be-able-to-be-owned-by-a-scope-value
	 */
	public function testWithoutAnOwnedDraftThePublicCreateRuleStillDecides(): void {
		$this->publicCreateRefused = true;
		$destination = ['register' => 'dossiq', 'schema' => 'case'];
		$calls = [
			'anonymous submit' => [403, fn () => $this->service->submit(destination: $destination, mapping: null, payload: ['title' => 'a', 'caseType' => 'b'])],
			'fresh submit naming an owner' => [403, fn () => $this->service->submit(destination: $destination, mapping: null, payload: ['title' => 'a', 'caseType' => 'b'], owner: DraftOwner::portalSubject(subjectRef: 'subject-1'))],
			'draft of nobody' => [401, fn () => $this->service->saveDraft(destination: $destination, mapping: null, payload: ['title' => 'a'], subject: null)],
		];

		foreach ($calls as $what => [$status, $call]) {
			try {
				$call();
				$this->fail($what . ' was accepted.');
			} catch (FormSubmitRefusedException $refused) {
				$this->assertSame($status, $refused->getStatus(), $what);
			}
		}

		$this->assertSame([], $this->stored);
		foreach ($this->saves as $save) {
			$this->assertTrue($save['rbac'], 'Every create without an owned draft asks the RBAC.');
			$this->assertNull($save['owner']);
		}
	}//end testWithoutAnOwnedDraftThePublicCreateRuleStillDecides()

	/**
	 * Spec scenario: anyone else gets 404, whether another portal subject or a signed-in user.
	 *
	 * @spec openspec/changes/form-destination-validator/specs/form-destination/spec.md#requirement-a-draft-must-be-able-to-be-owned-by-a-scope-value
	 */
	public function testAnyoneElseGets404OnAPortalDraft(): void {
		$destination = ['register' => 'dossiq', 'schema' => 'case'];
		$draft = $this->service->saveDraft(destination: $destination, mapping: null, payload: ['title' => 'Van subject 1'], subject: null, owner: DraftOwner::portalSubject(subjectRef: 'subject-1'));
		$others = [
			'another subject' => [null, DraftOwner::portalSubject(subjectRef: 'subject-2')],
			'a signed-in user' => [$this->user(uid: 'alice'), null],
			'a user named like the subject' => [$this->user(uid: 'subject-1'), null],
			'nobody' => [null, null],
		];

		foreach ($others as $who => [$subject, $owner]) {
			$this->assertFalse($this->service->isOwnerOfDraft(destination: $destination, draftId: $draft['id'], subject: $subject, owner: $owner), $who);
			$this->assertNull($this->service->findDraft(destination: $destination, subject: $subject, owner: $owner), $who);
			$calls = [
				'read' => fn () => $this->service->readDraft(destination: $destination, draftId: $draft['id'], subject: $subject, owner: $owner),
				'save' => fn () => $this->service->saveDraft(destination: $destination, mapping: null, payload: ['title' => 'Overschreven'], subject: $subject, draftId: $draft['id'], owner: $owner),
				'send' => fn () => $this->service->submit(destination: $destination, mapping: null, payload: ['caseType' => 'x'], subject: $subject, draftId: $draft['id'], owner: $owner),
			];
			foreach ($calls as $action => $call) {
				try {
					$call();
					$this->fail($who . ' could ' . $action . ' the draft.');
				} catch (FormSubmitRefusedException $refused) {
					// Nobody at all may not save a draft (401); everyone else meets the same 404 an unknown id gets.
					$expected = ($subject === null && $owner === null && $action === 'save') ? 401 : 404;
					$this->assertSame($expected, $refused->getStatus(), $who . ' ' . $action);
				}
			}
		}

		$this->assertCount(1, $this->saves);
		$this->assertSame('Van subject 1', $this->stored[$draft['id']]->getObject()['title']);
		$this->assertTrue($this->stored[$draft['id']]->isDraft());
	}//end testAnyoneElseGets404OnAPortalDraft()

	/**
	 * A user's own draft reads back through readDraft as before; the owner defaults to the subject.
	 */
	public function testAUsersDraftReadsBackThroughTheSubject(): void {
		$destination = ['register' => 'dossiq', 'schema' => 'case'];
		$alice = $this->user(uid: 'alice');
		$draft = $this->service->saveDraft(destination: $destination, mapping: null, payload: ['title' => 'Van Alice'], subject: $alice);

		$this->assertNull($this->saves[0]['owner'], 'A user draft is owned through the session user, as before.');
		$this->assertSame('Van Alice', $this->service->readDraft(destination: $destination, draftId: $draft['id'], subject: $alice)['data']['title']);
		$this->assertSame($draft['id'], $this->service->findDraft(destination: $destination, subject: $alice)['id'] ?? null);
	}//end testAUsersDraftReadsBackThroughTheSubject()

	/**
	 * A draft save never takes its uuid from the payload, so it cannot write over another owner's draft.
	 *
	 * The save takes a payload `id` as the uuid of an existing object and
	 * updates it, and a scope-value owner saves with RBAC off. Goes red with
	 * the id filter removed: the second save then carries `id` and the stored
	 * draft of subject 1 is the one the fake updates.
	 *
	 * @spec openspec/changes/form-destination-validator/specs/form-destination/spec.md#requirement-a-draft-must-be-able-to-be-owned-by-a-scope-value
	 */
	public function testAPayloadIdNeverPicksTheUuidOfADraftSave(): void {
		$destination = ['register' => 'dossiq', 'schema' => 'case'];
		$victim = $this->service->saveDraft(destination: $destination, mapping: null, payload: ['title' => 'Van subject 1'], subject: null, owner: DraftOwner::portalSubject(subjectRef: 'subject-1'));

		$attacker = $this->service->saveDraft(
			destination: $destination,
			mapping: null,
			payload: ['title' => 'Overschreven', 'id' => $victim['id'], 'uuid' => $victim['id']],
			subject: null,
			owner: DraftOwner::portalSubject(subjectRef: 'subject-2')
		);

		$this->assertNotSame($victim['id'], $attacker['id'], 'The second save made its own object.');
		$this->assertNull($this->saves[1]['uuid']);
		$this->assertArrayNotHasKey('id', $this->saves[1]['object']);
		$this->assertArrayNotHasKey('uuid', $this->saves[1]['object']);
		$this->assertSame('Van subject 1', $this->stored[$victim['id']]->getObject()['title'], 'The other draft is unchanged.');
		$this->assertSame('portal:subject-1', $this->stored[$victim['id']]->getOwner());
	}//end testAPayloadIdNeverPicksTheUuidOfADraftSave()

	/**
	 * A fresh submit never takes its uuid from the payload, with or without a mapping.
	 *
	 * A mapping that points a field at the property `id`, and a fixed
	 * `@self.id`, are held to the same rule: the submit never names an
	 * existing object, the draft id is the only way to.
	 *
	 * @spec openspec/changes/form-destination-validator/specs/form-destination/spec.md#requirement-a-submit-must-create-the-destination-in-one-request-and-return-its-reference
	 */
	public function testAPayloadIdNeverPicksTheUuidOfASubmit(): void {
		$destination = ['register' => 'dossiq', 'schema' => 'case'];
		$this->service->submit(destination: $destination, mapping: null, payload: ['title' => 'a', 'caseType' => 'b', 'id' => 'uuid-existing'], subject: null);
		$this->service->submit(
			destination: $destination,
			mapping: [
				'fields' => [['field' => 'mail', 'property' => 'id'], ['field' => 'onderwerp', 'property' => 'title']],
				'fixed' => ['caseType' => 'b', '@self' => ['id' => 'uuid-existing', 'owner' => 'x']],
			],
			payload: ['mail' => 'uuid-existing', 'onderwerp' => 'a'],
			subject: null
		);

		foreach ($this->saves as $save) {
			$this->assertNull($save['uuid']);
			$this->assertArrayNotHasKey('id', $save['object']);
			$this->assertArrayNotHasKey('id', (array)($save['object']['@self'] ?? []));
		}

		$this->assertCount(2, $this->saves);
		$this->assertNotSame('uuid-existing', $this->saves[0]['uuid']);
	}//end testAPayloadIdNeverPicksTheUuidOfASubmit()

	/**
	 * Two requests on one draft: the one that finds it already sent is refused and announces nothing.
	 *
	 * The first request's save leaves draft between the second one's "is it
	 * still a draft" read and its save. The status policy refuses the second
	 * write (an allowance for an object that is no longer a draft), so no
	 * second `ObjectCreatedEvent` goes out.
	 *
	 * @spec openspec/changes/draft-creation-side-effects/specs/object-events/spec.md#requirement-creation-side-effects-must-be-held-for-a-draft-and-run-once-when-it-leaves-draft
	 */
	public function testALostRaceToLeaveDraftAnnouncesNothing(): void {
		$destination = ['register' => 'dossiq', 'schema' => 'case'];
		$alice = $this->user(uid: 'alice');
		$draft = $this->service->saveDraft(destination: $destination, mapping: null, payload: ['title' => 'Kapvergunning'], subject: $alice);
		$this->duringSave = function () use ($draft): void {
			$this->stored[$draft['id']]->setStatus('active');
		};

		try {
			$this->service->submit(destination: $destination, mapping: null, payload: ['caseType' => 'ct-1'], subject: $alice, draftId: $draft['id']);
			$this->fail('The second send of one draft was accepted.');
		} catch (FormSubmitRefusedException $refused) {
			$this->assertSame(404, $refused->getStatus());
		}

		$this->assertSame([], $this->dispatched, 'Nothing is announced a second time.');
	}//end testALostRaceToLeaveDraftAnnouncesNothing()

	/**
	 * A save that did not use the promotion allowance does not announce the creation either.
	 *
	 * The save is doubled as one that never reached the status policy and
	 * returned an object that was already `active`; goes red without the
	 * check on the allowance after the write.
	 *
	 * @spec openspec/changes/draft-creation-side-effects/specs/object-events/spec.md#requirement-creation-side-effects-must-be-held-for-a-draft-and-run-once-when-it-leaves-draft
	 */
	public function testASaveThatDidNotUseThePromotionAnnouncesNothing(): void {
		$destination = ['register' => 'dossiq', 'schema' => 'case'];
		$alice = $this->user(uid: 'alice');
		$draft = $this->service->saveDraft(destination: $destination, mapping: null, payload: ['title' => 'Kapvergunning'], subject: $alice);
		$this->saveSkipsStatusPolicy = true;

		try {
			$this->service->submit(destination: $destination, mapping: null, payload: ['caseType' => 'ct-1'], subject: $alice, draftId: $draft['id']);
			$this->fail('A save that did not leave draft was announced.');
		} catch (FormSubmitRefusedException $refused) {
			$this->assertSame(404, $refused->getStatus());
		}

		$this->assertSame([], $this->dispatched);
	}//end testASaveThatDidNotUseThePromotionAnnouncesNothing()

	/**
	 * A draft save claims its upload tokens: the file sits on the draft, so the token cannot be redeemed again.
	 *
	 * A refused draft save keeps the token for the retry, as a refused submit does. Goes red without the claim.
	 *
	 * @spec openspec/changes/form-destination-validator/specs/form-destination/spec.md#requirement-an-object-must-be-able-to-carry-the-explicit-lifecycle-status-draft
	 */
	public function testADraftSaveClaimsItsUploadTokens(): void {
		$path = (string)tempnam(sys_get_temp_dir(), 'or-submit-upload');
		file_put_contents($path, '%PDF-1.7');
		$this->temporary[] = $path;
		$token = $this->uploads->issue(
			formId: 'form-1',
			property: 'bijlage',
			rule: $this->schemaBySlug['case']->getProperties()['bijlage'],
			file: ['name' => 'a.pdf', 'type' => 'application/pdf', 'tmp_name' => $path, 'error' => UPLOAD_ERR_OK, 'size' => 8]
		)['token'];
		$destination = ['register' => 'dossiq', 'schema' => 'case'];
		$payload = ['title' => 'Half af', 'bijlage' => ['uploadToken' => $token]];
		$alice = $this->user(uid: 'alice');

		// Refused first: the token survives for the retry.
		$this->refuseSave['case'] = new CustomValidationException(message: 'x', errors: ['title' => 'nope']);
		try {
			$this->service->saveDraft(destination: $destination, mapping: null, payload: $payload, subject: $alice, scope: 'form-1');
			$this->fail('Refused draft save accepted.');
		} catch (FormSubmitRefusedException) {
			$this->assertSame('a.pdf', $this->uploads->materialise(formId: 'form-1', token: $token)['name']);
		}

		unset($this->refuseSave['case']);
		$this->service->saveDraft(destination: $destination, mapping: null, payload: $payload, subject: $alice, scope: 'form-1');
		$this->assertSame('a.pdf', $this->saves[1]['files']['bijlage']['name']);

		try {
			$this->uploads->materialise(formId: 'form-1', token: $token);
			$this->fail('The token of a saved draft is still redeemable.');
		} catch (FormSubmitRefusedException $refused) {
			$this->assertSame('upload-token-unknown', $refused->getFindings()[0]['code'] ?? null);
		}
	}//end testADraftSaveClaimsItsUploadTokens()

	/**
	 * Issue a token for the case schema's file property.
	 *
	 * @return string The token.
	 */
	private function bijlageToken(): string {
		$path = (string)tempnam(sys_get_temp_dir(), 'or-submit-upload');
		file_put_contents($path, '%PDF-1.7');
		$this->temporary[] = $path;

		return $this->uploads->issue(
			formId: 'form-1',
			property: 'bijlage',
			rule: $this->schemaBySlug['case']->getProperties()['bijlage'],
			file: ['name' => 'a.pdf', 'type' => 'application/pdf', 'tmp_name' => $path, 'error' => UPLOAD_ERR_OK, 'size' => 8]
		)['token'];
	}//end bijlageToken()

	/**
	 * The next autosave carrying the token its own draft already claimed is accepted, not refused 422.
	 *
	 * Goes red without the claimed-by-this-draft record: the second save then
	 * materialises a deleted token and is refused as unknown.
	 *
	 * @spec openspec/changes/form-destination-validator/specs/form-destination/spec.md#requirement-upload-tokens-must-hold-bytes-only-and-expire
	 */
	public function testAnAutosaveWithTheTokenItsDraftAlreadyClaimedIsAccepted(): void {
		$token = $this->bijlageToken();
		$destination = ['register' => 'dossiq', 'schema' => 'case'];
		$alice = $this->user(uid: 'alice');

		$first = $this->service->saveDraft(destination: $destination, mapping: null, payload: ['title' => 'Half af', 'bijlage' => ['uploadToken' => $token]], subject: $alice, scope: 'form-1');
		$second = $this->service->saveDraft(
			destination: $destination,
			mapping: null,
			payload: ['title' => 'Iets verder', 'bijlage' => ['uploadToken' => $token]],
			subject: $alice,
			draftId: $first['id'],
			scope: 'form-1'
		);

		$this->assertSame($first['id'], $second['id']);
		$this->assertNull($this->saves[1]['files'] ?? null, 'The file stays as the draft holds it; nothing is attached twice.');
		$this->assertArrayNotHasKey('bijlage', $this->saves[1]['object']);
	}//end testAnAutosaveWithTheTokenItsDraftAlreadyClaimedIsAccepted()

	/**
	 * A token another draft claimed stays unknown to this one.
	 *
	 * @spec openspec/changes/form-destination-validator/specs/form-destination/spec.md#requirement-upload-tokens-must-hold-bytes-only-and-expire
	 */
	public function testATokenAnotherDraftClaimedIsStillRefused(): void {
		$token = $this->bijlageToken();
		$destination = ['register' => 'dossiq', 'schema' => 'case'];
		$alice = $this->user(uid: 'alice');
		$bob = $this->user(uid: 'bob');

		$this->service->saveDraft(destination: $destination, mapping: null, payload: ['title' => 'Van alice', 'bijlage' => ['uploadToken' => $token]], subject: $alice, scope: 'form-1');
		$mine = $this->service->saveDraft(destination: $destination, mapping: null, payload: ['title' => 'Van bob'], subject: $bob, scope: 'form-1');

		$this->expectException(FormSubmitRefusedException::class);
		$this->service->saveDraft(destination: $destination, mapping: null, payload: ['title' => 'Van bob', 'bijlage' => ['uploadToken' => $token]], subject: $bob, draftId: $mine['id'], scope: 'form-1');
	}//end testATokenAnotherDraftClaimedIsStillRefused()

	/**
	 * A nested object under a cascading property never names an existing object: its identity keys are dropped.
	 *
	 * The cascade takes a nested `id` (or `@self.id`) as the uuid of an
	 * existing object and updates it. Goes red without withoutNestedIdentity().
	 *
	 * @spec openspec/changes/form-destination-validator/specs/form-destination/spec.md#requirement-a-submit-must-create-the-destination-in-one-request-and-return-its-reference
	 */
	public function testANestedCascadeObjectNeverNamesAnExistingObject(): void {
		$case = $this->schemaBySlug['case'];
		$case->setProperties(
			array_merge(
				$case->getProperties(),
				[
					'parties' => ['type' => 'array', 'items' => ['type' => 'object', 'inversedBy' => 'case']],
					'owner' => ['type' => 'object', 'inversedBy' => 'case'],
					'tags' => ['type' => 'array', 'items' => ['type' => 'object']],
				]
			)
		);

		$this->service->submit(
			destination: ['register' => 'dossiq', 'schema' => 'case'],
			mapping: null,
			payload: [
				'title' => 'a',
				'caseType' => 'b',
				'parties' => [['id' => 'victim-1', 'name' => 'x', 'address' => ['id' => 'victim-3']], ['@self' => ['id' => 'victim-2'], 'name' => 'y']],
				'owner' => ['id' => 'victim-4', 'name' => 'z'],
				'tags' => [['id' => 'plain-data', 'label' => 'kept']],
			],
			subject: null
		);

		$saved = $this->saves[0]['object'];
		$this->assertSame([['name' => 'x', 'address' => []], ['@self' => [], 'name' => 'y']], $saved['parties']);
		$this->assertSame(['name' => 'z'], $saved['owner']);
		$this->assertSame([['id' => 'plain-data', 'label' => 'kept']], $saved['tags'], 'Data under a property that does not cascade is not touched.');
	}//end testANestedCascadeObjectNeverNamesAnExistingObject()
}//end class
