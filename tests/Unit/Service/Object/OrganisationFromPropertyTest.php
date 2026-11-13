<?php

/**
 * An object's organisation taken from the property its schema names.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Object
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Object;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Organisation;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Exception\OrganisationFromPropertyException;
use OCA\OpenRegister\Service\Organisation\OrganisationAnnotation;
use OCA\OpenRegister\Service\Organisation\OrganisationFromProperty;
use OCA\OpenRegister\Service\OrganisationServiceInterface;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * Locks the rule: the unit the property names is the unit the rights follow, with no fallback.
 *
 * @covers \OCA\OpenRegister\Service\Organisation\OrganisationFromProperty
 * @covers \OCA\OpenRegister\Exception\OrganisationFromPropertyException
 *
 * @uses \OCA\OpenRegister\Db\ObjectEntity
 * @uses \OCA\OpenRegister\Db\Organisation
 * @uses \OCA\OpenRegister\Db\Schema
 * @uses \OCA\OpenRegister\Db\Schema\AnnotationVocabulary
 * @uses \OCA\OpenRegister\Service\Organisation\OrganisationAnnotation
 */
class OrganisationFromPropertyTest extends TestCase {

	/**
	 * The organisation the caller is active in.
	 *
	 * @var string
	 */
	private const ACTIVE = 'org-gemeente-voorbeeld';

	/**
	 * The organisation the property names.
	 *
	 * @var string
	 */
	private const NAMED = 'org-omgevingsdienst-voorbeeld';

	/**
	 * A schema declaring that `organization` decides the organisation.
	 *
	 * @return Schema The schema.
	 */
	private function schema(): Schema {
		$schema = new Schema();
		$schema->setSlug('publication');
		$schema->setProperties(['organization' => ['type' => 'string']]);
		$schema->setConfiguration(
			[OrganisationAnnotation::ANNOTATION => [OrganisationAnnotation::FROM_PROPERTY => 'organization']]
		);

		return $schema;
	}//end schema()

	/**
	 * An object with the given data and stored organisation.
	 *
	 * @param array<string, mixed> $data         The object's own values.
	 * @param string|null          $organisation The organisation already on the entity.
	 *
	 * @return ObjectEntity The object.
	 */
	private function object(array $data, ?string $organisation = null): ObjectEntity {
		$object = new ObjectEntity();
		$object->setUuid('11111111-1111-4111-8111-111111111111');
		$object->setObject($data);
		$object->setOrganisation($organisation);

		return $object;
	}//end object()

	/**
	 * The resolver, with the organisations the caller is a member of.
	 *
	 * @param array<int, string> $memberOf  The organisations `hasAccessToOrganisation()` admits.
	 * @param array<int, string> $existing  The organisations that exist.
	 * @param bool               $admin     Whether the caller is an administrator.
	 * @param string|null        $forNew    What `getOrganisationForNewEntity()` would stamp.
	 *
	 * @return OrganisationFromProperty The resolver.
	 */
	private function resolver(
		array $memberOf = [self::ACTIVE, self::NAMED],
		array $existing = [self::ACTIVE, self::NAMED],
		bool $admin = false,
		?string $forNew = self::ACTIVE,
	): OrganisationFromProperty {
		$organisations = $this->createMock(OrganisationServiceInterface::class);
		$organisations->method('hasAccessToOrganisation')
			->willReturnCallback(static fn (string $uuid): bool => in_array($uuid, $memberOf, true));
		$organisations->method('findContextOrganisation')
			->willReturnCallback(
				static function (string $uuid) use ($existing): ?Organisation {
					if (in_array($uuid, $existing, true) === false) {
						return null;
					}

					$organisation = new Organisation();
					$organisation->setUuid($uuid);

					return $organisation;
				}
			);
		$organisations->method('getOrganisationForNewEntity')->willReturn($forNew);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('officer');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('isAdmin')->willReturn($admin);

		return new OrganisationFromProperty(organisations: $organisations, userSession: $session, groupManager: $groups);
	}//end resolver()

	/**
	 * The property decides the organisation, not the caller's active one.
	 *
	 * @return void
	 */
	public function testThePropertySetsTheOrganisation(): void {
		$object = $this->object(data: ['organization' => self::NAMED], organisation: self::ACTIVE);

		$this->resolver()->apply(object: $object, schema: $this->schema(), selfData: []);

		$this->assertSame(expected: self::NAMED, actual: $object->getOrganisation());
	}//end testThePropertySetsTheOrganisation()

	/**
	 * A reference property is read through: its `uuid`, else its `id`, names the organisation.
	 *
	 * @return void
	 */
	public function testAReferenceValueIsReadThrough(): void {
		$byUuid = $this->object(data: ['organization' => ['uuid' => self::NAMED, 'title' => 'Omgevingsdienst']]);
		$byId = $this->object(data: ['organization' => ['id' => self::NAMED]]);

		$this->resolver()->apply(object: $byUuid, schema: $this->schema(), selfData: []);
		$this->resolver()->apply(object: $byId, schema: $this->schema(), selfData: []);

		$this->assertSame(expected: self::NAMED, actual: $byUuid->getOrganisation());
		$this->assertSame(expected: self::NAMED, actual: $byId->getOrganisation());
	}//end testAReferenceValueIsReadThrough()

	/**
	 * A caller who is not a member of the named organisation is refused 403, and nothing is set.
	 *
	 * The fallback to the caller's active organisation is the disagreement this
	 * change removes, so its absence is the assertion that matters: the object
	 * must not come back scoped to the organisation the caller happens to be in.
	 *
	 * @return void
	 */
	public function testANonMemberIsRefusedWithoutFallback(): void {
		$object = $this->object(data: ['organization' => self::NAMED], organisation: self::ACTIVE);
		$resolver = $this->resolver(memberOf: [self::ACTIVE]);

		try {
			$resolver->apply(object: $object, schema: $this->schema(), selfData: []);
			$this->fail('A non-member save was not refused.');
		} catch (OrganisationFromPropertyException $refusal) {
			$this->assertSame(expected: 403, actual: $refusal->getStatusCode());
			$this->assertSame(expected: OrganisationFromPropertyException::NOT_A_MEMBER, actual: $refusal->getBody()['error']);
			$this->assertSame(expected: 'organization', actual: $refusal->getBody()['property']);
			$this->assertSame(expected: self::NAMED, actual: $refusal->getBody()['organisation']);
		}

		$this->assertSame(expected: self::ACTIVE, actual: $object->getOrganisation());
	}//end testANonMemberIsRefusedWithoutFallback()

	/**
	 * An administrator may give an object to an organisation they are not a member of.
	 *
	 * @return void
	 */
	public function testAnAdministratorMayNameAnyOrganisation(): void {
		$object = $this->object(data: ['organization' => self::NAMED]);

		$this->resolver(memberOf: [], admin: true)->apply(object: $object, schema: $this->schema(), selfData: []);

		$this->assertSame(expected: self::NAMED, actual: $object->getOrganisation());
	}//end testAnAdministratorMayNameAnyOrganisation()

	/**
	 * A `@self.organisation` that differs from the property is refused 422, naming both.
	 *
	 * @return void
	 */
	public function testAContradictingSelfOrganisationIsRefused(): void {
		$object = $this->object(data: ['organization' => self::NAMED]);

		try {
			$this->resolver()->apply(
				object: $object,
				schema: $this->schema(),
				selfData: ['organisation' => self::ACTIVE]
			);
			$this->fail('A contradicting @self.organisation was not refused.');
		} catch (OrganisationFromPropertyException $refusal) {
			$body = $refusal->getBody();
			$this->assertSame(expected: 422, actual: $refusal->getStatusCode());
			$this->assertSame(expected: OrganisationFromPropertyException::CONTRADICTION, actual: $body['error']);
			$this->assertSame(expected: self::NAMED, actual: $body['organisation']);
			$this->assertSame(expected: self::ACTIVE, actual: $body['selfOrganisation']);
		}

		$this->assertNull(actual: $object->getOrganisation());
	}//end testAContradictingSelfOrganisationIsRefused()

	/**
	 * A `@self.organisation` equal to the property is no contradiction.
	 *
	 * @return void
	 */
	public function testASelfOrganisationEqualToThePropertyIsAccepted(): void {
		$object = $this->object(data: ['organization' => self::NAMED]);

		$this->resolver()->apply(object: $object, schema: $this->schema(), selfData: ['organisation' => self::NAMED]);

		$this->assertSame(expected: self::NAMED, actual: $object->getOrganisation());
	}//end testASelfOrganisationEqualToThePropertyIsAccepted()

	/**
	 * A property naming an organisation that does not exist is refused 422.
	 *
	 * @return void
	 */
	public function testAnUnknownOrganisationIsRefused(): void {
		$object = $this->object(data: ['organization' => 'org-nobody']);

		try {
			$this->resolver(memberOf: ['org-nobody'])->apply(object: $object, schema: $this->schema(), selfData: []);
			$this->fail('An unknown organisation was not refused.');
		} catch (OrganisationFromPropertyException $refusal) {
			$this->assertSame(expected: 422, actual: $refusal->getStatusCode());
			$this->assertSame(expected: OrganisationFromPropertyException::UNKNOWN, actual: $refusal->getBody()['error']);
			$this->assertSame(expected: 'org-nobody', actual: $refusal->getBody()['organisation']);
		}

		$this->assertNull(actual: $object->getOrganisation());
	}//end testAnUnknownOrganisationIsRefused()

	/**
	 * An empty property is filled with the organisation the save stamps, so the two agree.
	 *
	 * @return void
	 */
	public function testAnEmptyPropertyIsFilledWithTheStampedOrganisation(): void {
		$object = $this->object(data: ['organization' => '']);

		$this->resolver()->apply(object: $object, schema: $this->schema(), selfData: []);

		$this->assertSame(expected: self::ACTIVE, actual: $object->getOrganisation());
		$this->assertSame(expected: self::ACTIVE, actual: $object->getObject()['organization']);
	}//end testAnEmptyPropertyIsFilledWithTheStampedOrganisation()

	/**
	 * An absent property is filled from the organisation already on the entity.
	 *
	 * The client-supplied `@self.organisation` a member may set is already on
	 * the entity by this point, so it is what the property must agree with.
	 *
	 * @return void
	 */
	public function testAnAbsentPropertyIsFilledFromTheEntitysOrganisation(): void {
		$object = $this->object(data: ['title' => 'A publication'], organisation: self::NAMED);

		$this->resolver()->apply(object: $object, schema: $this->schema(), selfData: []);

		$this->assertSame(expected: self::NAMED, actual: $object->getOrganisation());
		$this->assertSame(expected: self::NAMED, actual: $object->getObject()['organization']);
	}//end testAnAbsentPropertyIsFilledFromTheEntitysOrganisation()

	/**
	 * Filling an empty property does not store the uuid `getObject()` injects as `id`.
	 *
	 * @return void
	 */
	public function testFillingDoesNotStoreTheInjectedId(): void {
		$object = $this->object(data: ['organization' => null]);

		$this->resolver()->apply(object: $object, schema: $this->schema(), selfData: []);

		$stored = (new \ReflectionProperty(ObjectEntity::class, 'object'))->getValue($object);

		$this->assertSame(expected: self::ACTIVE, actual: ($stored['organization'] ?? null));
		$this->assertArrayNotHasKey(key: 'id', array: $stored);
	}//end testFillingDoesNotStoreTheInjectedId()

	/**
	 * An update that changes the property moves the object to the organisation it now names.
	 *
	 * @return void
	 */
	public function testAnUpdateThatChangesThePropertyMovesTheObject(): void {
		$stored = $this->object(data: ['organization' => self::ACTIVE], organisation: self::ACTIVE);
		$stored->setObject(['organization' => self::NAMED]);

		$this->resolver()->apply(object: $stored, schema: $this->schema(), selfData: []);

		$this->assertSame(expected: self::NAMED, actual: $stored->getOrganisation());
		$this->assertSame(expected: self::NAMED, actual: OrganisationFromProperty::named(object: $stored, property: 'organization'));
	}//end testAnUpdateThatChangesThePropertyMovesTheObject()

	/**
	 * A schema that declares nothing keeps today's stamping: the resolver changes nothing.
	 *
	 * @return void
	 */
	public function testASchemaWithoutTheAnnotationIsLeftAlone(): void {
		$schema = new Schema();
		$schema->setProperties(['organization' => ['type' => 'string']]);
		$schema->setConfiguration([]);
		$object = $this->object(data: ['organization' => self::NAMED], organisation: self::ACTIVE);

		$this->resolver()->apply(object: $object, schema: $schema, selfData: []);

		$this->assertSame(expected: self::ACTIVE, actual: $object->getOrganisation());
	}//end testASchemaWithoutTheAnnotationIsLeftAlone()

	/**
	 * A stored object whose property and organisation disagree is reported; one that agrees is not.
	 *
	 * @return void
	 */
	public function testADisagreementIsReportedForReconciliation(): void {
		$disagreeing = $this->object(data: ['organization' => self::NAMED], organisation: self::ACTIVE);
		$agreeing = $this->object(data: ['organization' => self::NAMED], organisation: self::NAMED);
		$silent = $this->object(data: ['title' => 'no organisation named'], organisation: self::ACTIVE);

		$this->assertSame(
			expected: self::NAMED,
			actual: OrganisationFromProperty::disagreement(object: $disagreeing, property: 'organization')
		);
		$this->assertNull(actual: OrganisationFromProperty::disagreement(object: $agreeing, property: 'organization'));
		$this->assertNull(actual: OrganisationFromProperty::disagreement(object: $silent, property: 'organization'));
	}//end testADisagreementIsReportedForReconciliation()
}//end class
