<?php

declare(strict_types=1);

/**
 * ContactsController Unit Tests
 *
 * Tests the contacts match API endpoint including success, validation,
 * and error handling.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Controller
 * @author   Conduction Development Team <dev@conduction.nl>
 * @license  EUPL-1.2
 */

namespace Unit\Controller;

use OCA\OpenRegister\Service\ContactPlacement;
use OCA\OpenRegister\Tests\Unit\Controller\Support\ContactsControllerFactory;
use OCA\OpenRegister\Controller\ContactsController;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\Object\PermissionHandler;
use OCA\OpenRegister\Service\ContactMatchingService;
use OCA\OpenRegister\Service\ContactService;
use OCA\OpenRegister\Service\DeepLinkRegistryService;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\PersonLinkChange;
use OCA\OpenRegister\Service\PersonLinkRef;
use OCA\OpenRegister\Service\PersonLinkService;
use OCA\OpenRegister\Service\PersonLinkTarget;
use OCA\OpenRegister\Tests\Unit\Service\Object\PermissionAskArgs;
use OCP\IL10N;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Test class for ContactsController.
 */
class ContactsControllerTest extends TestCase {

	private IRequest&MockObject $request;
	private ContactService&MockObject $contactService;
	private ObjectService&MockObject $objectService;
	private ContactMatchingService&MockObject $matchingService;
	private DeepLinkRegistryService&MockObject $deepLinkRegistry;
	private IL10N&MockObject $l10n;
	private LoggerInterface&MockObject $logger;
	private PersonLinkService&MockObject $personLinks;
	private PermissionHandler&MockObject $permissions;
	private ContactsController $controller;
	private bool $mayUpdate = true;

	/** @var array<int, string> The actions the permission handler was asked about. */
	private array $askedActions = [];

	protected function setUp(): void {
		parent::setUp();

		$this->request = $this->createMock(IRequest::class);
		$this->contactService = $this->createMock(ContactService::class);
		$this->objectService = $this->createMock(ObjectService::class);
		$this->matchingService = $this->createMock(ContactMatchingService::class);
		$this->deepLinkRegistry = $this->createMock(DeepLinkRegistryService::class);
		$this->l10n = $this->createMock(IL10N::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->personLinks = $this->createMock(PersonLinkService::class);
		$this->permissions = $this->createMock(PermissionHandler::class);
		$this->permissions->method('hasPermission')->willReturnCallback(PermissionAskArgs::spread(
			function (Schema $schema, string $action): bool {
				$this->askedActions[] = $action;
				return $action === 'update' ? $this->mayUpdate : true;
			}
		));
		$this->objectService->method('getCurrentSchemaEntity')->willReturn(new Schema());

		$this->l10n->method('t')->willReturnCallback(
			static function (string $text): string {
				return $text;
			}
		);

		$this->controller = ContactsControllerFactory::create(
			'openregister',
			$this->request,
			$this->contactService,
			$this->objectService,
			$this->matchingService,
			$this->deepLinkRegistry,
			$this->l10n,
			$this->logger,
			$this->personLinks,
			$this->permissions
		);
	}

	// -------------------------------------------------------------------------
	// Successful match
	// -------------------------------------------------------------------------

	public function testMatchReturns200WithCorrectJsonStructure(): void {
		$this->request->method('getParam')
			->willReturnCallback(static function (string $key, $default = '') {
				return match ($key) {
					'email' => 'jan@example.nl',
					'name' => 'Jan de Vries',
					'organization' => '',
					default => $default,
				};
			});

		$this->matchingService->method('matchContact')
			->willReturn([
				[
					'uuid' => 'result-1',
					'register' => ['id' => 1, 'title' => 'Main'],
					'schema' => ['id' => 2, 'title' => 'Medewerkers'],
					'title' => 'Jan de Vries',
					'matchType' => 'email',
					'confidence' => 1.0,
					'properties' => ['email' => 'jan@example.nl'],
					'cached' => false,
				],
			]);

		$this->deepLinkRegistry->method('resolveUrl')->willReturn('/apps/procest/#/cases/result-1');
		$this->deepLinkRegistry->method('resolveIcon')->willReturn('/apps/procest/img/app.svg');

		$response = $this->controller->match();

		$this->assertSame(200, $response->getStatus());
		$data = $response->getData();
		$this->assertArrayHasKey('matches', $data);
		$this->assertArrayHasKey('total', $data);
		$this->assertSame(1, $data['total']);
		$this->assertSame('/apps/procest/#/cases/result-1', $data['matches'][0]['url']);
		$this->assertSame('/apps/procest/img/app.svg', $data['matches'][0]['icon']);
	}

	// -------------------------------------------------------------------------
	// Missing parameters
	// -------------------------------------------------------------------------

	public function testMatchReturns400WhenNoEmailOrNameProvided(): void {
		$this->request->method('getParam')
			->willReturnCallback(static function (string $key, $default = '') {
				return match ($key) {
					'email' => '',
					'name' => '',
					'organization' => 'Gemeente Tilburg',
					default => $default,
				};
			});

		$response = $this->controller->match();

		$this->assertSame(400, $response->getStatus());
		$data = $response->getData();
		$this->assertArrayHasKey('error', $data);
		$this->assertSame(0, $data['total']);
	}

	// -------------------------------------------------------------------------
	// Internal server error
	// -------------------------------------------------------------------------

	public function testMatchReturns500OnInternalError(): void {
		$this->request->method('getParam')
			->willReturnCallback(static function (string $key, $default = '') {
				return match ($key) {
					'email' => 'jan@example.nl',
					default => $default,
				};
			});

		$this->matchingService->method('matchContact')
			->willThrowException(new \RuntimeException('Database error'));

		$response = $this->controller->match();

		$this->assertSame(500, $response->getStatus());
		$data = $response->getData();
		$this->assertArrayHasKey('error', $data);
	}

	// -------------------------------------------------------------------------
	// Email-only match
	// -------------------------------------------------------------------------

	// -------------------------------------------------------------------------
	// Tier-2: destroy by contact uid + create-new endpoint
	// -------------------------------------------------------------------------

	/**
	 * Tier-2: destroy with a non-numeric `{contactUid}` resolves through
	 * `unlinkContactByUid` (not the legacy id-based path).
	 */
	public function testDestroyRoutesNonNumericUidThroughUnlinkByUid(): void {
		$object = new \OCA\OpenRegister\Db\ObjectEntity();
		$object->setUuid('abc-123');

		$this->objectService->method('getObject')->willReturn($object);

		// people-on-objects: a non-numeric uid names a person, whose links on
		// this object go through PersonLinkService (one role, or all of them).
		$this->personLinks->expects($this->once())
			->method('removeLinks')
			->with(new PersonLinkRef('abc-123', 'jan-uid', null))
			->willReturn(1);

		$response = $this->controller->destroy('reg', 'sch', 'oid', 'jan-uid');

		$this->assertSame(200, $response->getStatus());
		$this->assertSame(['success' => true, 'removed' => 1], $response->getData());
	}

	/**
	 * Tier-2: destroy with a numeric `{contactUid}` falls back to the
	 * legacy id-based unlink path (back-compat).
	 */
	public function testDestroyRoutesNumericIdThroughLegacyUnlink(): void {
		$object = new \OCA\OpenRegister\Db\ObjectEntity();
		$object->setUuid('abc-123');

		$this->objectService->method('getObject')->willReturn($object);

		$this->contactService->expects($this->once())
			->method('unlinkContact')
			->with(42, 'abc-123');

		$response = $this->controller->destroy('reg', 'sch', 'oid', '42');

		$this->assertSame(200, $response->getStatus());
		$this->assertSame(['success' => true], $response->getData());
	}

	/**
	 * A numeric link id of another object's link answers 404 and deletes nothing.
	 */
	public function testDestroyRefusesANumericIdOfAnotherObjectsLink(): void {
		$object = new \OCA\OpenRegister\Db\ObjectEntity();
		$object->setUuid('abc-123');

		$this->objectService->method('getObject')->willReturn($object);

		$this->contactService->expects($this->once())
			->method('unlinkContact')
			->with(77, 'abc-123')
			->willThrowException(new \Exception('Contact link not found', 404));

		$response = $this->controller->destroy('reg', 'sch', 'oid', '77');

		$this->assertSame(404, $response->getStatus());
		$this->assertSame(['error' => 'Contact link not found'], $response->getData());
	}

	/**
	 * An object without a uuid has no links to remove: both destroy paths answer 404 and remove nothing.
	 */
	public function testDestroyOnAnObjectWithoutAUuidRemovesNothing(): void {
		$this->objectService->method('getObject')->willReturn(new \OCA\OpenRegister\Db\ObjectEntity());
		$this->contactService->expects($this->never())->method('unlinkContact');
		$this->personLinks->expects($this->never())->method('removeLinks');

		$this->assertSame(404, $this->controller->destroy('reg', 'sch', 'oid', '42')->getStatus());
		$this->assertSame(404, $this->controller->destroy('reg', 'sch', 'oid', 'jan-uid')->getStatus());
	}

	/**
	 * Tier-2: createNew rejects payloads carrying a contactUri (link
	 * shape) with a 400.
	 */
	public function testCreateNewRejectsLinkPayload(): void {
		$object = new \OCA\OpenRegister\Db\ObjectEntity();
		$object->setUuid('abc-123');
		$object->setRegister('5');
		$object->setSchema('7');

		$this->objectService->method('getObject')->willReturn($object);
		$this->request->method('getParams')->willReturn([
			'contactUri' => 'jan.vcf',
			'addressbookId' => 1,
			'displayName' => 'Jan',
		]);

		$response = $this->controller->createNew('reg', 'sch', 'oid');

		$this->assertSame(400, $response->getStatus());
	}

	/**
	 * Tier-2: createNew requires `displayName` (or back-compat
	 * `fullName`).
	 */
	public function testCreateNewRequiresDisplayName(): void {
		$object = new \OCA\OpenRegister\Db\ObjectEntity();
		$object->setUuid('abc-123');
		$object->setRegister('5');
		$object->setSchema('7');

		$this->objectService->method('getObject')->willReturn($object);
		$this->request->method('getParams')->willReturn([
			'email' => 'jan@example.nl',
		]);

		$response = $this->controller->createNew('reg', 'sch', 'oid');

		$this->assertSame(400, $response->getStatus());
	}

	/**
	 * Tier-2: createNew passes the schema id through to
	 * `createAndLinkContact`.
	 */
	public function testCreateNewThreadsSchemaId(): void {
		$object = new \OCA\OpenRegister\Db\ObjectEntity();
		$object->setUuid('abc-123');
		$object->setRegister('5');
		$object->setSchema('7');

		$this->objectService->method('getObject')->willReturn($object);
		$this->request->method('getParams')->willReturn([
			'displayName' => 'Jan de Vries',
			'email' => 'jan@example.nl',
			'role' => 'applicant',
		]);

		$link = new \OCA\OpenRegister\Db\ContactLink();
		$link->setObjectUuid('abc-123');

		$this->contactService->expects($this->once())
			->method('createAndLinkContact')
			->with(
				$this->equalTo(new ContactPlacement('abc-123', 5, 7, 'applicant')),
				$this->callback(static function (array $data): bool {
					return $data['displayName'] === 'Jan de Vries'
						&& $data['email'] === 'jan@example.nl'
						&& $data['role'] === 'applicant';
				})
			)
			->willReturn($link);

		$response = $this->controller->createNew('reg', 'sch', 'oid');

		$this->assertSame(201, $response->getStatus());
	}

	public function testMatchWorksWithEmailOnly(): void {
		$this->request->method('getParam')
			->willReturnCallback(static function (string $key, $default = '') {
				return match ($key) {
					'email' => 'test@example.nl',
					'name' => '',
					'organization' => '',
					default => $default,
				};
			});

		$this->matchingService->method('matchContact')
			->with('test@example.nl', null, null)
			->willReturn([]);

		$this->deepLinkRegistry->method('resolveUrl')->willReturn(null);
		$this->deepLinkRegistry->method('resolveIcon')->willReturn(null);

		$response = $this->controller->match();

		$this->assertSame(200, $response->getStatus());
		$this->assertSame(0, $response->getData()['total']);
	}

	/**
	 * people-on-objects: a payload naming a user goes to the link service,
	 * with the object's register and schema threaded through.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/people-on-objects/specs/people-on-objects/spec.md#requirement-a-link-on-an-object-is-a-user-or-a-contact-in-a-role-for-a-period
	 */
	public function testCreateLinksAUserThroughThePersonLinkService(): void {
		$object = new \OCA\OpenRegister\Db\ObjectEntity();
		$object->setUuid('abc-123');
		$object->setRegister('5');
		$object->setSchema('7');
		$this->objectService->method('getObject')->willReturn($object);
		$this->request->method('getParams')->willReturn(['userId' => 'jan', 'role' => 'handler']);

		$link = new \OCA\OpenRegister\Db\ContactLink();
		$link->setContactUid('user:jan');
		$this->contactService->expects($this->never())->method('createAndLinkContact');
		$this->personLinks->expects($this->once())
			->method('linkPerson')
			->with(new PersonLinkTarget('abc-123', 5, 7), ['userId' => 'jan', 'role' => 'handler'])
			->willReturn($link);

		$response = $this->controller->create('reg', 'sch', 'oid');

		$this->assertSame(201, $response->getStatus());
	}//end testCreateLinksAUserThroughThePersonLinkService()

	/**
	 * people-on-objects: a role the schema does not declare comes back as the
	 * service's 400, not a generic one.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/people-on-objects/specs/people-on-objects/spec.md#requirement-a-schema-declares-the-roles-its-objects-carry
	 */
	public function testCreatePassesTheLinkServicesStatusThrough(): void {
		$object = new \OCA\OpenRegister\Db\ObjectEntity();
		$object->setUuid('abc-123');
		$object->setRegister('5');
		$object->setSchema('7');
		$this->objectService->method('getObject')->willReturn($object);
		$this->request->method('getParams')->willReturn(['userId' => 'ghost']);
		$this->personLinks->method('linkPerson')->willThrowException(new \Exception('User not found', 404));

		$response = $this->controller->create('reg', 'sch', 'oid');

		$this->assertSame(404, $response->getStatus());
		$this->assertSame(['error' => 'User not found'], $response->getData());
	}//end testCreatePassesTheLinkServicesStatusThrough()

	/**
	 * people-on-objects: the listing is the link service's, grouped and with
	 * the schema's vocabulary.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/people-on-objects/specs/people-on-objects/spec.md#requirement-a-schema-declares-the-roles-its-objects-carry
	 */
	public function testIndexAnswersTheGroupedListing(): void {
		$object = new \OCA\OpenRegister\Db\ObjectEntity();
		$object->setUuid('abc-123');
		$object->setSchema('7');
		$this->objectService->method('getObject')->willReturn($object);
		$listing = [
			'results' => [['contactUid' => 'user:jan', 'role' => 'handler']],
			'total' => 1,
			'byRole' => ['handler' => [['contactUid' => 'user:jan', 'role' => 'handler']]],
			'roles' => [['key' => 'handler', 'label' => 'Handler']],
		];
		$this->personLinks->expects($this->once())
			->method('listForObject')
			->with('abc-123', 7)
			->willReturn($listing);

		$response = $this->controller->index('reg', 'sch', 'oid');

		$this->assertSame(200, $response->getStatus());
		$this->assertSame($listing, $response->getData());
	}//end testIndexAnswersTheGroupedListing()

	/**
	 * people-on-objects: update forwards only the four editable fields, and
	 * `currentRole` names which link when the person holds several.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/people-on-objects/specs/people-on-objects/spec.md#requirement-a-link-can-be-updated-and-removed-per-role
	 */
	public function testUpdateForwardsTheEditableFieldsOnly(): void {
		$object = new \OCA\OpenRegister\Db\ObjectEntity();
		$object->setUuid('abc-123');
		$object->setSchema('7');
		$this->objectService->method('getObject')->willReturn($object);
		$this->request->method('getParams')->willReturn(
			['validUntil' => '2026-12-31', 'note' => 'Until the handover', 'displayName' => 'ignored']
		);
		$this->request->method('getParam')->willReturnCallback(
			static function (string $name) {
				return $name === 'currentRole' ? 'handler' : null;
			}
		);
		$this->personLinks->expects($this->once())
			->method('changeLink')
			->with(new PersonLinkChange(new PersonLinkRef('abc-123', 'user:jan', 'handler'), 7, ['validUntil' => '2026-12-31', 'note' => 'Until the handover']))
			->willReturn(new \OCA\OpenRegister\Db\ContactLink());

		$response = $this->controller->update('reg', 'sch', 'oid', 'user:jan');

		$this->assertSame(200, $response->getStatus());
	}//end testUpdateForwardsTheEditableFieldsOnly()

	/**
	 * people-on-objects: `?role=` removes one role of a person, not every link.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/people-on-objects/specs/people-on-objects/spec.md#requirement-a-link-can-be-updated-and-removed-per-role
	 */
	public function testDestroyWithARoleRemovesThatRoleOnly(): void {
		$object = new \OCA\OpenRegister\Db\ObjectEntity();
		$object->setUuid('abc-123');
		$this->objectService->method('getObject')->willReturn($object);
		$this->request->method('getParam')->willReturnCallback(
			static function (string $name) {
				return $name === 'role' ? 'advisor' : null;
			}
		);
		$this->personLinks->expects($this->once())
			->method('removeLinks')
			->with(new PersonLinkRef('abc-123', 'user:jan', 'advisor'))
			->willReturn(1);

		$response = $this->controller->destroy('reg', 'sch', 'oid', 'user:jan');

		$this->assertSame(['success' => true, 'removed' => 1], $response->getData());
	}//end testDestroyWithARoleRemovesThatRoleOnly()


	/**
	 * Every contacts write on an object the caller may only read answers 403 and changes nothing.
	 */
	public function testContactWritesNeedTheObjectUpdateRight(): void {
		$object = new \OCA\OpenRegister\Db\ObjectEntity();
		$object->setUuid('abc-123');
		$object->setOwner('someone-else');
		$this->objectService->method('getObject')->willReturn($object);
		$this->request->method('getParams')->willReturn(['userId' => 'jan', 'role' => 'applicant', 'fullName' => 'Jan']);
		$this->mayUpdate = false;

		$this->personLinks->expects($this->never())->method('linkPerson');
		$this->personLinks->expects($this->never())->method('changeLink');
		$this->personLinks->expects($this->never())->method('removeLinks');
		$this->contactService->expects($this->never())->method('unlinkContact');
		$this->contactService->expects($this->never())->method('createAndLinkContact');

		$responses = [
			'create' => $this->controller->create('reg', 'sch', 'oid'),
			'createNew' => $this->controller->createNew('reg', 'sch', 'oid'),
			'update' => $this->controller->update('reg', 'sch', 'oid', 'jan-uid'),
			'destroy uid' => $this->controller->destroy('reg', 'sch', 'oid', 'jan-uid'),
			'destroy id' => $this->controller->destroy('reg', 'sch', 'oid', '42'),
		];

		foreach ($responses as $action => $response) {
			$this->assertSame(403, $response->getStatus(), $action);
		}

		$this->assertSame(array_fill(0, 5, 'update'), $this->askedActions, 'the right asked is update, not read');
	}

	/**
	 * Reading an object's contacts needs no update right.
	 */
	public function testListingContactsNeedsOnlyRead(): void {
		$object = new \OCA\OpenRegister\Db\ObjectEntity();
		$object->setUuid('abc-123');
		$this->objectService->method('getObject')->willReturn($object);
		$this->mayUpdate = false;

		$this->assertNotSame(403, $this->controller->index('reg', 'sch', 'oid')->getStatus());
	}
}
