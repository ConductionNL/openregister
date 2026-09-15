<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller;

use Exception;
use OCA\OpenRegister\Controller\PartyController;
use OCA\OpenRegister\Db\ContactLink;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\Party\PartyIndicatorGuard;
use OCA\OpenRegister\Service\Party\PartyRoleService;
use OCA\OpenRegister\Service\Party\PartySearchService;
use OCA\OpenRegister\Service\Party\PartyService;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * The wire contract of the party endpoints: what each one answers, and with
 * which status, before anything below it is reached.
 *
 * @spec openspec/changes/party-roles-beyond-the-requester/specs/party-model/spec.md
 */
class PartyControllerTest extends TestCase {

	/**
	 * The request.
	 *
	 * @var IRequest&MockObject
	 */
	private $request;

	/**
	 * The object layer.
	 *
	 * @var ObjectService&MockObject
	 */
	private $objects;

	/**
	 * The parties on an object.
	 *
	 * @var PartyRoleService&MockObject
	 */
	private $roles;

	/**
	 * The party records.
	 *
	 * @var PartyService&MockObject
	 */
	private $parties;

	/**
	 * The capped search.
	 *
	 * @var PartySearchService&MockObject
	 */
	private $search;

	/**
	 * The controller under test.
	 *
	 * @var PartyController
	 */
	private PartyController $controller;

	/**
	 * The parameters the request answers with.
	 *
	 * @var array<string, mixed>
	 */
	private array $params = [];

	/**
	 * Build the controller on doubles.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->request = $this->createMock(IRequest::class);
		$this->request->method('getParam')->willReturnCallback(
			fn (string $key, mixed $default = null): mixed => ($this->params[$key] ?? $default)
		);
		$this->request->method('getParams')->willReturnCallback(fn (): array => $this->params);

		$this->objects = $this->createMock(ObjectService::class);
		$this->roles = $this->createMock(PartyRoleService::class);
		$this->parties = $this->createMock(PartyService::class);
		$this->search = $this->createMock(PartySearchService::class);

		$this->controller = new PartyController(
			'openregister',
			$this->request,
			$this->objects,
			$this->roles,
			$this->parties,
			$this->createMock(PartyIndicatorGuard::class),
			$this->search
		);
	}//end setUp()

	/**
	 * The object the object layer hands back for this test.
	 *
	 * @param ObjectEntity|null $object The object, null for "not found".
	 *
	 * @return ObjectEntity|null The object.
	 */
	private function objectLayerAnswers(?ObjectEntity $object): ?ObjectEntity {
		$this->objects->method('getObject')->willReturn($object);

		return $object;
	}//end objectLayerAnswers()

	/**
	 * A case object.
	 *
	 * @return ObjectEntity The object.
	 */
	private function caseObject(): ObjectEntity {
		$object = new ObjectEntity();
		$object->setUuid('case-1');
		$object->setRegister('1');
		$object->setSchema('7');
		$object->setObject([]);

		return $object;
	}//end caseObject()

	/**
	 * A stored party link.
	 *
	 * @param string $partyUuid The party.
	 *
	 * @return ContactLink The link.
	 */
	private function link(string $partyUuid): ContactLink {
		$link = new ContactLink();
		$link->setObjectUuid('case-1');
		$link->setPartyUuid($partyUuid);
		$link->setContactUid(ContactLink::partyUid(partyUuid: $partyUuid));
		$link->setRole('aanvrager');
		$link->setPrimaryParty(true);

		return $link;
	}//end link()

	/**
	 * Replacing the primary party answers the new link, and the object, party
	 * and role reach the service exactly as the request spelled them.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/party-roles-beyond-the-requester/specs/party-model/spec.md#requirement-a-party-holds-a-typed-role-on-an-object-for-a-period-req-prm-001
	 */
	public function testReplacePrimaryAnswersTheNewLink(): void {
		$object = $this->objectLayerAnswers($this->caseObject());
		$this->params = ['partyUuid' => 'party-b', 'role' => 'aanvrager'];

		$this->roles->expects($this->once())
			->method('replacePrimaryParty')
			->with($object, 'party-b', 'aanvrager')
			->willReturn($this->link(partyUuid: 'party-b'));

		$response = $this->controller->replacePrimary(register: 'zaken', schema: 'zaak', id: 'case-1');

		$this->assertSame(200, $response->getStatus());
		$this->assertSame('party-b', $response->getData()['partyUuid']);
		$this->assertTrue($response->getData()['primaryParty']);
	}//end testReplacePrimaryAnswersTheNewLink()

	/**
	 * A request naming no party is a 400, and nothing below the controller is
	 * reached. Replacing the primary party with nothing is not a no-op, it is
	 * a mistake.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/party-roles-beyond-the-requester/specs/party-model/spec.md#requirement-a-party-holds-a-typed-role-on-an-object-for-a-period-req-prm-001
	 */
	public function testReplacePrimaryWithoutAPartyIs400(): void {
		$this->objectLayerAnswers($this->caseObject());
		$this->params = ['role' => 'aanvrager'];

		$this->roles->expects($this->never())->method('replacePrimaryParty');

		$response = $this->controller->replacePrimary(register: 'zaken', schema: 'zaak', id: 'case-1');

		$this->assertSame(400, $response->getStatus());
		$this->assertSame('partyUuid is required', $response->getData()['error']);
	}//end testReplacePrimaryWithoutAPartyIs400()

	/**
	 * An object the caller may not read is a 404, not a party listing.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/party-roles-beyond-the-requester/specs/party-model/spec.md#requirement-a-party-holds-a-typed-role-on-an-object-for-a-period-req-prm-001
	 */
	public function testAnUnreadableObjectIs404(): void {
		$this->objectLayerAnswers(null);
		$this->params = ['partyUuid' => 'party-b'];

		$this->roles->expects($this->never())->method('replacePrimaryParty');

		$response = $this->controller->replacePrimary(register: 'zaken', schema: 'zaak', id: 'case-1');

		$this->assertSame(404, $response->getStatus());
	}//end testAnUnreadableObjectIs404()

	/**
	 * A refusal from below keeps the status it named, so an undeclared party
	 * kind reaches the client as a 400 rather than a 500.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/party-roles-beyond-the-requester/specs/party-model/spec.md#requirement-a-party-holds-a-typed-role-on-an-object-for-a-period-req-prm-001
	 */
	public function testARefusalKeepsItsStatus(): void {
		$this->objectLayerAnswers($this->caseObject());
		$this->params = ['partyUuid' => 'party-b'];

		$this->roles->method('replacePrimaryParty')
			->willThrowException(new Exception('Party kind "person" is not one of: organisation', 400));

		$response = $this->controller->replacePrimary(register: 'zaken', schema: 'zaak', id: 'case-1');

		$this->assertSame(400, $response->getStatus());
		$this->assertStringContainsString('organisation', $response->getData()['error']);
	}//end testARefusalKeepsItsStatus()

	/**
	 * Adding a party answers 201 with the stored link.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/party-roles-beyond-the-requester/specs/party-model/spec.md#requirement-a-party-holds-a-typed-role-on-an-object-for-a-period-req-prm-001
	 */
	public function testCreateAnswers201(): void {
		$this->objectLayerAnswers($this->caseObject());
		$this->params = ['partyUuid' => 'party-a', 'role' => 'gemachtigde'];

		$this->roles->method('addParty')->willReturn($this->link(partyUuid: 'party-a'));

		$response = $this->controller->create(register: 'zaken', schema: 'zaak', id: 'case-1');

		$this->assertSame(201, $response->getStatus());
		$this->assertSame('party-a', $response->getData()['partyUuid']);
	}//end testCreateAnswers201()

	/**
	 * A query over the cap reaches the client as the refusal the service
	 * named, carrying no party.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/party-roles-beyond-the-requester/specs/party-model/spec.md#requirement-a-person-query-over-the-administered-cap-is-refused-req-prm-004
	 */
	public function testACappedQueryIsRefusedWithNoResults(): void {
		$this->params = ['q' => 'jan'];
		$this->search->method('search')
			->willThrowException(new Exception('The query matches 40 parties, over the administered cap of 10.', 403));

		$response = $this->controller->search();

		$this->assertSame(403, $response->getStatus());
		$this->assertArrayNotHasKey('results', $response->getData());
		$this->assertStringContainsString('cap of 10', $response->getData()['error']);
	}//end testACappedQueryIsRefusedWithNoResults()

	/**
	 * An address nobody holds is a 404, never a newly invented party.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/party-roles-beyond-the-requester/specs/party-model/spec.md#requirement-a-party-without-an-account-carries-its-own-fields-and-is-reachable-req-prm-002
	 */
	public function testAnUnknownAddressIs404(): void {
		$this->params = ['address' => 'niemand@example.org'];
		$this->parties->method('resolveByAddress')->willReturn(null);

		$response = $this->controller->resolve();

		$this->assertSame(404, $response->getStatus());
	}//end testAnUnknownAddressIs404()
}//end class
