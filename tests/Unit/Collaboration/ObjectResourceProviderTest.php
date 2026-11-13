<?php

/**
 * Unit tests for ObjectResourceProvider.
 *
 * An object joins a collaboration collection only when its schema opts in, and
 * a user sees it there only when the object read path returns it to them.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Collaboration
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/platform-collaboration-resources/specs/platform-collaboration-resources/spec.md#requirement-any-object-may-be-a-collaboration-resource-req-pcr-001
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Collaboration;

use OCA\OpenRegister\Collaboration\ObjectResourceProvider;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindOptions;
use OCA\OpenRegister\Service\Reference\ObjectPreviewFormatterInterface;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\Collaboration\Reference\IReference;
use OCP\Collaboration\Resources\IResource;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * @covers \OCA\OpenRegister\Collaboration\ObjectResourceProvider
 */
class ObjectResourceProviderTest extends TestCase {

	private const UUID = '5f0c2a6e-1b2c-4d3e-8f9a-0b1c2d3e4f5a';

	private ObjectOperationsInterface&MockObject $objects;

	private SchemaMapperInterface&MockObject $schemas;

	private ObjectPreviewFormatterInterface&MockObject $formatter;

	private IUserSession&MockObject $session;

	/**
	 * Schema id => configuration; a missing id cannot be found.
	 *
	 * @var array<int, array<string, mixed>|null>
	 */
	private array $configurations = [];

	protected function setUp(): void {
		$this->objects   = $this->createMock(ObjectOperationsInterface::class);
		$this->schemas   = $this->createMock(SchemaMapperInterface::class);
		$this->formatter = $this->createMock(ObjectPreviewFormatterInterface::class);
		$this->session   = $this->createMock(IUserSession::class);
		$this->configurations = [
			7 => ['collaborationResources' => true],
			8 => ['collaborationResources' => 'yes'],
			9 => null,
		];
		$this->schemas->method('find')->willReturnCallback(
			function (string|int $id): Schema {
				if (array_key_exists((int)$id, $this->configurations) === false) {
					throw new DoesNotExistException('no schema');
				}

				$schema = $this->createMock(Schema::class);
				$schema->method('getConfiguration')->willReturn($this->configurations[(int)$id]);
				return $schema;
			}
		);
	}//end setUp()

	/**
	 * The provider under test, with an absolute URL builder.
	 *
	 * @return ObjectResourceProvider
	 */
	private function provider(): ObjectResourceProvider {
		$urls = $this->createMock(IURLGenerator::class);
		$urls->method('getAbsoluteURL')->willReturnCallback(static fn (string $path): string => 'https://cloud.example' . $path);

		return new ObjectResourceProvider(
			objects: $this->objects,
			schemas: $this->schemas,
			formatter: $this->formatter,
			urls: $urls,
			userSession: $this->session,
		);
	}//end provider()

	/**
	 * A resource with an id.
	 *
	 * @param string $id The resource id.
	 *
	 * @return IResource
	 */
	private function resource(string $id): IResource {
		$resource = $this->createMock(IResource::class);
		$resource->method('getId')->willReturn($id);
		$resource->method('getType')->willReturn(ObjectResourceProvider::TYPE);
		return $resource;
	}//end resource()

	/**
	 * A user with a uid.
	 *
	 * @param string $uid The uid.
	 *
	 * @return IUser
	 */
	private function user(string $uid): IUser {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		return $user;
	}//end user()

	public function testTheTypeIsTheObjectType(): void {
		$this->assertSame('openregister-object', $this->provider()->getType());
	}//end testTheTypeIsTheObjectType()

	public function testAResourceIdNamesRegisterSchemaAndObject(): void {
		$this->assertSame(['register' => 3, 'schema' => 7, 'uuid' => self::UUID], ObjectResourceProvider::parse(resourceId: '3/7/' . self::UUID));
		$this->assertNull(ObjectResourceProvider::parse(resourceId: '3/7'));
		$this->assertNull(ObjectResourceProvider::parse(resourceId: 'x/7/' . self::UUID));
		$this->assertNull(ObjectResourceProvider::parse(resourceId: '3/7/' . self::UUID . '/extra'));
		$this->assertNull(ObjectResourceProvider::parse(resourceId: '3/7/a b'));
	}//end testAResourceIdNamesRegisterSchemaAndObject()

	public function testOnlyASchemaDeclaringTrueMayJoin(): void {
		$provider = $this->provider();

		$this->assertTrue($provider->mayJoin(schemaId: 7));
		$this->assertFalse($provider->mayJoin(schemaId: 8), 'a truthy value that is not true is not an opt-in');
		$this->assertFalse($provider->mayJoin(schemaId: 9), 'a schema that declares nothing stays out');
		$this->assertFalse($provider->mayJoin(schemaId: 404), 'a schema that cannot be read stays out');
	}//end testOnlyASchemaDeclaringTrueMayJoin()

	public function testTheSignedInUserSeesWhatTheReadPathReturns(): void {
		$this->session->method('getUser')->willReturn($this->user('anna'));
		$this->objects->expects($this->once())->method('find')
			->with(
				self::UUID,
				$this->callback(static fn (ObjectFindOptions $o): bool => $o->register === 3 && $o->schema === 7 && $o->render === false && $o->audit === false),
				true,
				true
			)
			->willReturn($this->createMock(ObjectEntity::class));

		$this->assertTrue($this->provider()->canAccessResource($this->resource('3/7/' . self::UUID), $this->user('anna')));
	}//end testTheSignedInUserSeesWhatTheReadPathReturns()

	public function testACollectionDoesNotWidenAccess(): void {
		$this->session->method('getUser')->willReturn($this->user('anna'));
		$this->objects->method('find')->willReturnOnConsecutiveCalls(null, $this->throwException(new RuntimeException('denied')));
		$provider = $this->provider();

		$this->assertFalse($provider->canAccessResource($this->resource('3/7/' . self::UUID), $this->user('anna')), 'not returned by the read path');
		$this->assertFalse($provider->canAccessResource($this->resource('3/7/' . self::UUID), $this->user('anna')), 'refused by the read path');
	}//end testACollectionDoesNotWidenAccess()

	public function testNobodyElseNoGuestNoOptOutAndNoMalformedIdIsAdmitted(): void {
		$this->objects->expects($this->never())->method('find');
		$this->session->method('getUser')->willReturnOnConsecutiveCalls($this->user('anna'), null, $this->user('anna'));
		$provider = $this->provider();

		$this->assertFalse($provider->canAccessResource($this->resource('3/7/' . self::UUID), $this->user('bram')), 'the read path answers only for the signed-in user');
		$this->assertFalse($provider->canAccessResource($this->resource('3/7/' . self::UUID), $this->user('anna')), 'no session');
		$this->assertFalse($provider->canAccessResource($this->resource('3/9/' . self::UUID), $this->user('anna')), 'the schema did not opt in');
		$this->assertFalse($provider->canAccessResource($this->resource('3/7/' . self::UUID), null), 'a guest');
		$this->assertFalse($provider->canAccessResource($this->resource('nonsense'), $this->user('anna')), 'not an object id');
	}//end testNobodyElseNoGuestNoOptOutAndNoMalformedIdIsAdmitted()

	public function testTheRichObjectIsTheObjectsCard(): void {
		$reference = $this->createMock(IReference::class);
		$reference->method('getRichObject')->willReturn(
			['title' => 'Zaak 2026-118', 'url' => 'https://cloud.example/apps/dossiq/zaken/x', 'icon_url' => 'https://cloud.example/icon.svg', 'description' => 'ignored']
		);
		$this->formatter->expects($this->once())->method('buildReference')
			->with('https://cloud.example/apps/openregister/api/objects/3/7/' . self::UUID)
			->willReturn($reference);

		$this->assertSame(
			[
				'type' => 'openregister-object',
				'id' => '3/7/' . self::UUID,
				'name' => 'Zaak 2026-118',
				'link' => 'https://cloud.example/apps/dossiq/zaken/x',
				'iconUrl' => 'https://cloud.example/icon.svg',
			],
			$this->provider()->getResourceRichObject($this->resource('3/7/' . self::UUID))
		);
	}//end testTheRichObjectIsTheObjectsCard()

	public function testNoCardForAnOptedOutSchemaAnUnreadableObjectOrAMalformedId(): void {
		$this->formatter->expects($this->once())->method('buildReference')->willReturn(null);
		$provider = $this->provider();

		$this->assertSame([], $provider->getResourceRichObject($this->resource('3/9/' . self::UUID)));
		$this->assertSame([], $provider->getResourceRichObject($this->resource('3/7/' . self::UUID)));
		$this->assertSame([], $provider->getResourceRichObject($this->resource('3/7')));
	}//end testNoCardForAnOptedOutSchemaAnUnreadableObjectOrAMalformedId()
}//end class
