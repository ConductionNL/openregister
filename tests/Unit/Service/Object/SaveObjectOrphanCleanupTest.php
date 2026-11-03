<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Orphan cleanup soft-deletes only a row the stored parent owned and the caller may delete.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Object
 * @license  EUPL-1.2
 * @link     https://github.com/ConductionNL/openregister
 *
 * @spec openspec/specs/referential-integrity/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Object;

use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\File\FolderManagementHandler;
use OCA\OpenRegister\Service\Object\CacheHandler;
use OCA\OpenRegister\Service\Object\PermissionHandler;
use OCA\OpenRegister\Service\Object\SaveObject;
use OCA\OpenRegister\Service\Object\SaveObject\ComputedFieldHandler;
use OCA\OpenRegister\Service\Object\SaveObject\FilePropertyHandler;
use OCA\OpenRegister\Service\Object\SaveObject\LinkedEntityPropertyHandler;
use OCA\OpenRegister\Service\Object\SaveObject\MetadataHydrationHandler;
use OCA\OpenRegister\Service\Object\TranslationHandler;
use OCA\OpenRegister\Service\OrganisationService;
use OCA\OpenRegister\Service\PropertyRbacHandler;
use OCA\OpenRegister\Service\SettingsService;
use OCA\OpenRegister\Service\TmloService;
use OCA\OpenRegister\Service\TranslationProjectionService;
use OCA\OpenRegister\Service\TranslationStatusService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IURLGenerator;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use ReflectionClass;

/**
 * Every case runs the real SaveObject::saveObject. The mapper finds a row by uuid or slug, as
 * the real one does, and the test reads which rows were soft-deleted.
 *
 * @covers \OCA\OpenRegister\Service\Object\SaveObject
 * @uses   \OCA\OpenRegister\Db\ObjectEntity
 * @uses   \OCA\OpenRegister\Db\ObjectHandling
 * @uses   \OCA\OpenRegister\Db\Register
 * @uses   \OCA\OpenRegister\Db\Schema
 * @uses   \OCA\OpenRegister\Service\Object\DraftStatusPolicy
 * @uses   \OCA\OpenRegister\Service\Object\ObjectVersionHandler
 * @uses   \OCA\OpenRegister\Service\OrganisationContext
 * @uses   \OCA\OpenRegister\Service\WritePhaseProbe
 * @uses   \OCA\OpenRegister\Service\Object\SaveObject\ObjectSlugBatch
 * @uses   \OCA\OpenRegister\Service\Object\SaveObject\ObjectSlugClientValue
 * @uses   \OCA\OpenRegister\Service\Object\SaveObject\ObjectSlugHandler
 * @uses   \OCA\OpenRegister\Service\Object\SaveObject\ObjectSlugLog
 * @uses   \OCA\OpenRegister\Service\Object\SaveObject\ObjectSlugSource
 * @uses   \OCA\OpenRegister\Service\Object\SaveObject\ObjectSlugStore
 */
class SaveObjectOrphanCleanupTest extends TestCase {

	private const PARENT = '11111111-1111-1111-1111-111111111111';

	private const ROW = '99999999-9999-9999-9999-999999999999';

	/**
	 * The parent's stored data on an update.
	 */
	private array $stored = [];

	/**
	 * The row the mapper finds, by uuid or by `row-slug`.
	 */
	private ObjectEntity $row;

	/**
	 * Whether the caller's organisations hold the row.
	 */
	private bool $held = true;

	/**
	 * Whether the permission handler grants the delete.
	 */
	private bool $mayDelete = true;

	/**
	 * Every delete check, as [action, owner, rbac, row uuid, user].
	 *
	 * @var list<array>
	 */
	private array $asked = [];

	/**
	 * Every identifier the mapper was asked for.
	 *
	 * @var list<string>
	 */
	private array $finds = [];

	protected function setUp(): void {
		$this->row = new ObjectEntity();
		$this->row->setUuid(self::ROW);
		$this->row->setOwner('carol');
		$this->row->setOrganisation('org-a');
		$this->row->setObject(['incident' => self::PARENT]);
	}//end setUp()

	/**
	 * The save handler over the recording mapper and permission handler.
	 *
	 * @return SaveObject
	 */
	private function handler(): SaveObject {
		$mapper = $this->createMock(MagicMapper::class);
		$mapper->method('find')->willReturnCallback(
			function (string|int $identifier): ObjectEntity {
				$this->finds[] = (string)$identifier;
				if ((string)$identifier === self::PARENT) {
					$parent = new ObjectEntity();
					$parent->setId(5);
					$parent->setUuid(self::PARENT);
					$parent->setRegister('10');
					$parent->setObject($this->stored);
					return $parent;
				}

				if ((string)$identifier === self::ROW || (string)$identifier === 'row-slug') {
					return $this->row;
				}

				throw new DoesNotExistException('none');
			}
		);
		$mapper->method('callerOrganisationsHold')->willReturnCallback(fn (): bool => $this->held);

		$permissions = $this->createMock(PermissionHandler::class);
		$permissions->method('hasPermission')->willReturnCallback(
			function (Schema $schema, string $action, ?string $userId = null, ?string $objectOwner = null, bool $_rbac = true, ?ObjectEntity $object = null): bool {
				$this->asked[] = [$schema->getSlug(), $action, $userId, $objectOwner, $_rbac, $object?->getUuid()];
				return $_rbac === false || $this->mayDelete;
			}
		);

		$translations = $this->createMock(TranslationHandler::class);
		$translations->method('normalizeTranslationsForSave')->willReturnArgument(0);

		$handler = new SaveObject(
			$mapper,
			$mapper,
			$this->createMock(MetadataHydrationHandler::class),
			$this->createMock(FilePropertyHandler::class),
			$this->createMock(LinkedEntityPropertyHandler::class),
			$this->createMock(IUserSession::class),
			$this->createMock(AuditTrailMapper::class),
			$this->createMock(SchemaMapper::class),
			$this->createMock(RegisterMapper::class),
			$this->createMock(IURLGenerator::class),
			$this->createMock(OrganisationService::class),
			$this->createMock(CacheHandler::class),
			$this->createMock(SettingsService::class),
			$this->createMock(PropertyRbacHandler::class),
			$this->createMock(ComputedFieldHandler::class),
			$translations,
			$this->createMock(TranslationProjectionService::class),
			$this->createMock(TranslationStatusService::class),
			new NullLogger(),
			$this->createMock(TmloService::class),
			$this->createMock(FolderManagementHandler::class),
			$permissions
		);

		return $handler;
	}//end handler()

	/**
	 * Save an incident with this `notes` list, and answer whether the row was soft-deleted.
	 *
	 * @param array       $notes      The incoming list.
	 * @param array       $items      The extra `items` keys of the property.
	 * @param string|null $uuid       The incident to update, null to create one.
	 * @param bool        $rbac       The RBAC mode of the save.
	 *
	 * @return bool
	 */
	private function saveRemovesRow(array $notes, array $items = ['inversedBy' => 'incident'], ?string $uuid = self::PARENT, bool $rbac = true): bool {
		$handler = $this->handler();
		$note = new Schema();
		$note->setId(2);
		$note->setSlug('note');
		$incident = new Schema();
		$incident->setId(1);
		$incident->setSlug('incident');
		$incident->setProperties(
			['notes' => ['type' => 'array', 'items' => ['$ref' => 'note'] + $items, 'objectConfiguration' => ['handling' => 'related-object']]]
		);
		$register = new Register();
		$register->setId(10);

		$reflection = new ReflectionClass(SaveObject::class);
		$reflection->getProperty('schemaReferenceCache')->setValue($handler, ['note' => '2']);
		$reflection->getProperty('schemaCache')->setValue($handler, ['2' => $note, '1' => $incident]);
		$reflection->getProperty('registerCache')->setValue($handler, ['10' => $register]);

		try {
			$handler->saveObject(register: $register, schema: $incident, data: ['notes' => $notes], uuid: $uuid, _rbac: $rbac);
		} catch (\TypeError $e) {
			// The doubles return no persisted entity after the cascade, which runs before that.
			$this->addToAssertionCount(1);
		}

		return $this->row->isSoftDeleted();
	}//end saveRemovesRow()

	/**
	 * A scope that lacks what the bounded lookup or the ownership test needs.
	 *
	 * @return array<string, array{0: array}>
	 */
	public static function incompleteScopes(): array {
		$whole = ['register' => new Register(), 'schema' => new Schema(), 'inverse' => 'incident', 'parent' => self::PARENT];

		return [
			'no register' => [['register' => null] + $whole],
			'no schema' => [['schema' => null] + $whole],
			'no inverse relation' => [['inverse' => null] + $whole],
		];
	}//end incompleteScopes()

	/**
	 * @dataProvider incompleteScopes
	 *
	 * @param array $scope The orphan scope.
	 */
	public function testAnIncompleteScopeLooksNothingUp(array $scope): void {
		(new ReflectionClass(SaveObject::class))->getMethod('deleteOrphanedRelatedObjects')->invoke($this->handler(), [self::ROW], $scope);

		$this->assertSame([], $this->finds);
		$this->assertFalse($this->row->isSoftDeleted());
	}

	public function testACreateNamingARowBySlugDeletesNothing(): void {
		$this->assertFalse($this->saveRemovesRow(notes: ['row-slug'], uuid: null));
		$this->assertSame([], $this->asked);
	}

	public function testAnUpdateNamingARowBySlugDeletesNothing(): void {
		$this->assertFalse($this->saveRemovesRow(notes: ['row-slug']));
		$this->assertSame([], $this->asked);
	}

	public function testAStoredReferenceToAnOwnedRowIsDeletedWhenRemoved(): void {
		$this->stored = ['notes' => [self::ROW]];

		$this->assertTrue($this->saveRemovesRow(notes: ['22222222-2222-2222-2222-222222222222']));
		$this->assertSame([['note', 'delete', null, 'carol', true, self::ROW]], $this->asked);
	}

	public function testEmptyingTheListDeletesAnOwnedRow(): void {
		$this->stored = ['notes' => [self::ROW]];

		$this->assertTrue($this->saveRemovesRow(notes: []));
	}

	public function testARowThatNamesTheParentInAListIsOwned(): void {
		$this->stored = ['notes' => [self::ROW]];
		$this->row->setObject(['incident' => ['other', self::PARENT]]);

		$this->assertTrue($this->saveRemovesRow(notes: []));
	}

	public function testAKeptReferenceIsNotDeleted(): void {
		$this->stored = ['notes' => [self::ROW]];

		$this->assertFalse($this->saveRemovesRow(notes: [self::ROW]));
	}

	public function testAPlainPointerWithoutAnInverseRelationIsNotDeleted(): void {
		$this->stored = ['notes' => [self::ROW]];

		$this->assertFalse($this->saveRemovesRow(notes: [], items: []));
		$this->assertSame([], $this->asked);
	}

	public function testARowNamingAnotherParentIsNotDeleted(): void {
		$this->stored = ['notes' => [self::ROW]];
		$this->row->setObject(['incident' => 'another-parent']);

		$this->assertFalse($this->saveRemovesRow(notes: []));
	}

	public function testAStoredSlugThatFindsARowIsNotDeleted(): void {
		$this->stored = ['notes' => ['row-slug']];

		$this->assertFalse($this->saveRemovesRow(notes: []));
	}

	public function testARowOutsideTheCallersOrganisationsIsNotDeleted(): void {
		$this->stored = ['notes' => [self::ROW]];
		$this->held = false;

		$this->assertFalse($this->saveRemovesRow(notes: []));
		$this->assertSame([], $this->asked);
	}

	public function testARowTheCallerMayNotDeleteOnlyLosesTheReference(): void {
		$this->stored = ['notes' => [self::ROW]];
		$this->mayDelete = false;

		$this->assertFalse($this->saveRemovesRow(notes: []));
	}

	public function testASaveWithRbacOffDeletesAnOwnedRowAnywhere(): void {
		$this->stored = ['notes' => [self::ROW]];
		$this->held = false;
		$this->mayDelete = false;

		$this->assertTrue($this->saveRemovesRow(notes: [], rbac: false));
		$this->assertSame([['note', 'delete', null, 'carol', false, self::ROW]], $this->asked);
	}
}//end class
