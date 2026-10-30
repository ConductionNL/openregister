<?php

/**
 * Who may use, change and delete a record template (records-saved-templates).
 *
 * The ways this could be wrong while the list still looks right:
 *  - a public or group-shared template offered to someone who may not create
 *    in its schema (the spec's second scenario);
 *  - a group share read as a right to EDIT, so a member rewrites the owner's
 *    template;
 *  - a template for a schema the caller may not create in being stored;
 *  - a missing template and an unusable one answering differently, which tells
 *    a caller what exists.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\RecordTemplate
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/records-saved-templates/specs/record-templates/spec.md#requirement-a-user-can-save-and-share-a-named-record-template
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\RecordTemplate;

use OCA\OpenRegister\Db\RecordTemplate;
use OCA\OpenRegister\Db\RecordTemplateMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Exception\RecordTemplateRefused;
use OCA\OpenRegister\Service\Object\PermissionHandler;
use OCA\OpenRegister\Service\Rbac\ViewerReach;
use OCA\OpenRegister\Service\Rbac\ViewerReachResolver;
use OCA\OpenRegister\Service\RecordTemplate\RecordTemplateService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\Entity;
use OCP\IGroupManager;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * @covers \OCA\OpenRegister\Service\RecordTemplate\RecordTemplateService
 * @covers \OCA\OpenRegister\Db\RecordTemplate
 * @covers \OCA\OpenRegister\Exception\RecordTemplateRefused
 */
class RecordTemplateServiceTest extends TestCase {

	/**
	 * The stored templates, by uuid.
	 *
	 * @var array<string, RecordTemplate>
	 */
	private array $rows = [];

	/**
	 * Who may create in which schema: "user|schema" => bool.
	 *
	 * @var array<string, bool>
	 */
	private array $creators = [];

	/**
	 * A kcc member with create rights on meldingen sees the template shared with kcc.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/records-saved-templates/specs/record-templates/spec.md#scenario-a-caseworker-saves-a-melding-as-a-template
	 */
	public function testAGroupMemberWhoMayCreateSeesTheSharedTemplate(): void {
		$this->creators = ['anna|meldingen' => true, 'bert|meldingen' => true];
		$service = $this->service();
		$service->create(data: $this->melding(), reach: $this->reach(userId: 'anna', groups: ['kcc']));

		$listed = $service->listFor(reach: $this->reach(userId: 'bert', groups: ['kcc']), schema: 'meldingen');

		self::assertCount(1, $listed);
		self::assertSame('Melding wateroverlast', $listed[0]->getName());
		self::assertSame('read', $listed[0]->getAccess());
		self::assertSame(['categorie' => 'water', 'prioriteit' => 'hoog'], $listed[0]->jsonSerialize()['values']);
	}//end testAGroupMemberWhoMayCreateSeesTheSharedTemplate()

	/**
	 * A kcc member with read-only access to meldingen is not offered it, and cannot read it directly.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/records-saved-templates/specs/record-templates/spec.md#scenario-a-user-without-create-rights-is-not-offered-the-template
	 */
	public function testAMemberWithoutCreateRightsIsNotOfferedTheTemplate(): void {
		$this->creators = ['anna|meldingen' => true, 'carla|meldingen' => false];
		$service = $this->service();
		$stored = $service->create(data: $this->melding(['isPublic' => true]), reach: $this->reach(userId: 'anna', groups: ['kcc']));
		$carla = $this->reach(userId: 'carla', groups: ['kcc']);

		self::assertSame([], $service->listFor(reach: $carla, schema: 'meldingen'));
		$this->assertRefused(status: 404, action: fn () => $service->get(uuid: (string)$stored->getUuid(), reach: $carla));
	}//end testAMemberWithoutCreateRightsIsNotOfferedTheTemplate()

	/**
	 * A private template is the owner's only; outside the group it is not listed.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/records-saved-templates/specs/record-templates/spec.md#requirement-a-user-can-save-and-share-a-named-record-template
	 */
	public function testAPrivateTemplateIsTheOwnersOnly(): void {
		$this->creators = ['anna|meldingen' => true, 'bert|meldingen' => true];
		$service = $this->service();
		$service->create(data: $this->melding(['sharedWith' => []]), reach: $this->reach(userId: 'anna'));

		self::assertCount(1, $service->listFor(reach: $this->reach(userId: 'anna')));
		self::assertSame('owner', $service->listFor(reach: $this->reach(userId: 'anna'))[0]->getAccess());
		self::assertSame([], $service->listFor(reach: $this->reach(userId: 'bert', groups: ['kcc'])));
	}//end testAPrivateTemplateIsTheOwnersOnly()

	/**
	 * A group member may use it but not change or delete it; the owner and an administrator may.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/records-saved-templates/specs/record-templates/spec.md#requirement-a-user-can-save-and-share-a-named-record-template
	 */
	public function testOnlyTheOwnerOrAnAdministratorChangesIt(): void {
		$this->creators = ['anna|meldingen' => true, 'bert|meldingen' => true, 'admin|meldingen' => true];
		$service = $this->service();
		$uuid = (string)$service->create(data: $this->melding(['sharedWith' => [['group' => 'kcc', 'mode' => 'write']]]), reach: $this->reach(userId: 'anna'))->getUuid();
		$bert = $this->reach(userId: 'bert', groups: ['kcc']);

		$this->assertRefused(status: 403, action: fn () => $service->change(uuid: $uuid, data: ['name' => 'Van Bert'], reach: $bert, partial: true));
		$this->assertRefused(status: 403, action: fn () => $service->delete(uuid: $uuid, reach: $bert));
		self::assertSame('Melding wateroverlast', $this->rows[$uuid]->getName());

		$renamed = $service->change(uuid: $uuid, data: ['name' => 'Wateroverlast kelder'], reach: $this->reach(userId: 'anna'), partial: true);
		self::assertSame('Wateroverlast kelder', $renamed->getName());
		self::assertSame(['categorie' => 'water', 'prioriteit' => 'hoog'], $renamed->getValues(), 'A PATCH keeps the fields it does not send.');

		$service->delete(uuid: $uuid, reach: $this->reach(userId: 'admin', isAdmin: true));
		self::assertArrayNotHasKey($uuid, $this->rows);
	}//end testOnlyTheOwnerOrAnAdministratorChangesIt()

	/**
	 * A template for a schema the caller may not create in is not stored.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/records-saved-templates/specs/record-templates/spec.md#requirement-a-user-can-save-and-share-a-named-record-template
	 */
	public function testNoTemplateForASchemaTheCallerMayNotCreateIn(): void {
		$this->creators = ['carla|meldingen' => false];
		$service = $this->service();

		$this->assertRefused(status: 403, action: fn () => $service->create(data: $this->melding(), reach: $this->reach(userId: 'carla')));
		self::assertSame([], $this->rows);
	}//end testNoTemplateForASchemaTheCallerMayNotCreateIn()

	/**
	 * Unusable input is refused with every finding, and nothing is stored.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/records-saved-templates/specs/record-templates/spec.md#requirement-a-user-can-save-and-share-a-named-record-template
	 */
	public function testUnusableInputIsRefusedWithItsFindings(): void {
		$this->creators = ['anna|meldingen' => true];
		$service = $this->service();

		try {
			$service->create(
				data: ['name' => '', 'register' => 'zaken', 'schema' => 'meldingen', 'values' => ['a', 'b'], 'isPublic' => 'yes', 'sharedWith' => [['group' => 'ghosts', 'mode' => 'read']]],
				reach: $this->reach(userId: 'anna')
			);
			self::fail('Unusable input must be refused.');
		} catch (RecordTemplateRefused $e) {
			self::assertSame(400, $e->getCode());
			$codes = array_column($e->getFindings(), 'code');
			self::assertContains('name.required', $codes);
			self::assertContains('values.not-an-object', $codes);
			self::assertContains('isPublic.not-a-boolean', $codes);
			self::assertCount(4, $codes, 'The unknown group is the fourth finding: ' . implode(', ', $codes));
		}

		self::assertSame([], $this->rows);
	}//end testUnusableInputIsRefusedWithItsFindings()

	/**
	 * A missing template answers 404, the same as one the caller may not use.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/records-saved-templates/specs/record-templates/spec.md#requirement-a-user-can-save-and-share-a-named-record-template
	 */
	public function testAMissingTemplateIs404(): void {
		$service = $this->service();

		$this->assertRefused(status: 404, action: fn () => $service->get(uuid: 'nope', reach: $this->reach(userId: 'anna')));
	}//end testAMissingTemplateIs404()

	/**
	 * A schema whose rights cannot be read offers nothing.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/records-saved-templates/specs/record-templates/spec.md#scenario-a-user-without-create-rights-is-not-offered-the-template
	 */
	public function testAnUnreadableSchemaOffersNothing(): void {
		$this->creators = ['anna|meldingen' => true];
		$service = $this->service();
		$service->create(data: $this->melding(['schema' => 'meldingen', 'isPublic' => true]), reach: $this->reach(userId: 'anna'));
		$this->rows[array_key_first($this->rows)]->setSchema('gone');

		self::assertSame([], $this->service()->listFor(reach: $this->reach(userId: 'anna')));
	}//end testAnUnreadableSchemaOffersNothing()

	/**
	 * A refusal with the expected status.
	 *
	 * @param int      $status The expected status.
	 * @param callable $action The action.
	 *
	 * @return void
	 */
	private function assertRefused(int $status, callable $action): void {
		try {
			$action();
			self::fail('Expected a refusal with ' . $status . '.');
		} catch (RecordTemplateRefused $e) {
			self::assertSame($status, $e->getCode(), $e->getMessage());
		}
	}//end assertRefused()

	/**
	 * The template the spec names, shared with kcc for reading.
	 *
	 * @param array<string, mixed> $override Fields to change.
	 *
	 * @return array<string, mixed> The request body.
	 */
	private function melding(array $override=[]): array {
		return $override + [
			'name' => 'Melding wateroverlast',
			'description' => 'Standaard bij water in de kelder',
			'register' => 'zaken',
			'schema' => 'meldingen',
			'values' => ['categorie' => 'water', 'prioriteit' => 'hoog'],
			'sharedWith' => [['group' => 'kcc', 'mode' => 'read']],
		];
	}//end melding()

	/**
	 * A caller.
	 *
	 * @param string             $userId  The uid.
	 * @param array<int, string> $groups  Their groups.
	 * @param bool               $isAdmin Whether they administer the instance.
	 *
	 * @return ViewerReach The reach.
	 */
	private function reach(string $userId, array $groups=[], bool $isAdmin=false): ViewerReach {
		return new ViewerReach(userId: $userId, groups: $groups, isAdmin: $isAdmin);
	}//end reach()

	/**
	 * The service over an in-memory store and a create-rights table.
	 *
	 * @return RecordTemplateService The service.
	 */
	private function service(): RecordTemplateService {
		$mapper = $this->createMock(RecordTemplateMapper::class);
		$mapper->method('insert')->willReturnCallback(function (Entity $template): RecordTemplate {
			$template->setUuid('tpl-' . (count($this->rows) + 1));
			$this->rows[(string)$template->getUuid()] = $template;
			return $template;
		});
		$mapper->method('update')->willReturnCallback(fn (Entity $template): Entity => $template);
		$mapper->method('delete')->willReturnCallback(function (Entity $template): Entity {
			unset($this->rows[(string)$template->getUuid()]);
			return $template;
		});
		$mapper->method('findByUuid')->willReturnCallback(function (string $uuid): RecordTemplate {
			if (isset($this->rows[$uuid]) === false) {
				throw new DoesNotExistException('none');
			}

			return $this->rows[$uuid];
		});
		$mapper->method('findCandidates')->willReturnCallback(
			fn (string $userId, ?string $register=null, ?string $schema=null): array => array_values(
				array_filter(
					$this->rows,
					static fn (RecordTemplate $row): bool => ($schema === null || $row->getSchema() === $schema)
				)
			)
		);

		$schemas = $this->createMock(SchemaMapper::class);
		$schemas->method('find')->willReturnCallback(function (string|int $id): Schema {
			if ($id === 'gone') {
				throw new DoesNotExistException('gone');
			}

			$schema = new Schema();
			$schema->setSlug((string)$id);
			return $schema;
		});

		$permissions = $this->createMock(PermissionHandler::class);
		$permissions->method('hasPermission')->willReturnCallback(
			fn (Schema $schema, string $action, ?string $userId=null): bool => ($action === 'create' && ($this->creators[$userId . '|' . $schema->getSlug()] ?? false) === true)
		);

		$groups = $this->createMock(IGroupManager::class);
		$groups->method('groupExists')->willReturnCallback(static fn (string $gid): bool => $gid === 'kcc');
		$viewers = new ViewerReachResolver(
			userSession: $this->createMock(IUserSession::class),
			groupManager: $groups,
			logger: new NullLogger()
		);

		return new RecordTemplateService(
			mapper: $mapper,
			schemas: $schemas,
			permissions: $permissions,
			viewers: $viewers,
			logger: new NullLogger()
		);
	}//end service()
}//end class
