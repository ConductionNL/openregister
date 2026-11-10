<?php

/**
 * Behaviour tests for RecordTemplateService — the branches the scenario test leaves.
 *
 * RecordTemplateServiceTest walks the spec scenarios. These tests pin what it
 * does not: an administrator reaching a template nobody shared with them, a
 * change that moves a template to another schema, an empty schema, a schema
 * lookup that fails for a reason other than "not found", the per-request
 * cache of create rights, and the filters `listFor` hands to the mapper.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\RecordTemplate
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/records-saved-templates/specs/record-templates/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\RecordTemplate;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Db\ListQuery;
use OCA\OpenRegister\Db\RecordTemplate;
use OCA\OpenRegister\Db\RecordTemplateMapperInterface;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Exception\RecordTemplateRefused;
use OCA\OpenRegister\Service\Object\PermissionCheckInterface;
use OCA\OpenRegister\Service\Rbac\ViewerReach;
use OCA\OpenRegister\Service\Rbac\ViewerReachResolver;
use OCA\OpenRegister\Service\RecordTemplate\TemplateListOptions;
use OCA\OpenRegister\Service\RecordTemplate\TemplateChange;
use OCA\OpenRegister\Service\RecordTemplate\RecordTemplateCollaborators;
use OCA\OpenRegister\Service\RecordTemplate\RecordTemplateService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\Entity;
use OCP\IGroupManager;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

final class RecordTemplateServiceBehaviourTest extends TestCase {

	/**
	 * Who may create in which schema: "user|schema" => bool.
	 *
	 * @var array<string, bool>
	 */
	private array $creators = [];

	/**
	 * How often create rights were asked, by "user|schema".
	 *
	 * @var array<string, int>
	 */
	private array $asked = [];

	/**
	 * Schema ids whose lookup throws a non-404 error.
	 *
	 * @var string[]
	 */
	private array $broken = [];

	/**
	 * The template the mapper holds.
	 *
	 * @var RecordTemplate|null
	 */
	private ?RecordTemplate $row = null;

	private RecordTemplateMapperInterface&MockObject $mapper;

	private LoggerInterface&MockObject $logger;

	private function template(string $owner, string $schema, array $sharedWith = [], bool $isPublic = false): RecordTemplate {
		$template = new RecordTemplate();
		$template->setUuid('tpl-1');
		$template->setName('Melding');
		$template->setRegister('zaken');
		$template->setSchema($schema);
		$template->setOwner($owner);
		$template->setIsPublic($isPublic);
		$template->setSharedWith($sharedWith);
		$template->setValues(['categorie' => 'water']);
		return $template;
	}

	private function reach(string $userId, array $groups = [], bool $isAdmin = false): ViewerReach {
		return new ViewerReach(userId: $userId, groups: $groups, isAdmin: $isAdmin);
	}

	private function service(): RecordTemplateService {
		$this->mapper = $this->createMock(RecordTemplateMapperInterface::class);
		$this->mapper->method('findByUuid')->willReturnCallback(function (string $uuid): RecordTemplate {
			if ($this->row === null || $this->row->getUuid() !== $uuid) {
				throw new DoesNotExistException('none');
			}
			return $this->row;
		});
		$this->mapper->method('update')->willReturnCallback(static fn (Entity $entity): Entity => $entity);
		$this->mapper->method('insert')->willReturnCallback(static fn (Entity $entity): Entity => $entity);

		$schemas = $this->createMock(SchemaMapperInterface::class);
		$schemas->method('find')->willReturnCallback(function (string|int $id): Schema {
			if ((string) $id === 'gone') {
				throw new DoesNotExistException('gone');
			}
			if (in_array((string) $id, $this->broken, true) === true) {
				throw new RuntimeException('database went away');
			}
			$schema = new Schema();
			$schema->setSlug((string) $id);
			return $schema;
		});

		$permissions = $this->createMock(PermissionCheckInterface::class);
		$permissions->method('hasPermission')->willReturnCallback(
			function (Schema $schema, string $action, ?string $userId = null): bool {
				$key = $userId . '|' . $schema->getSlug();
				$this->asked[$key] = ($this->asked[$key] ?? 0) + 1;
				return $action === 'create' && ($this->creators[$key] ?? false) === true;
			}
		);

		$groups = $this->createMock(IGroupManager::class);
		$groups->method('groupExists')->willReturn(true);

		$this->logger = $this->createMock(LoggerInterface::class);

		return new RecordTemplateService(
			new RecordTemplateCollaborators($this->mapper, $schemas, $this->logger),
			$permissions,
			new ViewerReachResolver(new \OCA\OpenRegister\Service\Rbac\CallerContext($this->createMock(IUserSession::class), $groups), $this->createMock(LoggerInterface::class))
		);
	}

	public function testAnAdministratorReachesAnUnsharedTemplateWithWriteAccess(): void {
		$this->creators = ['root|meldingen' => true];
		$service = $this->service();
		$this->row = $this->template('anna', 'meldingen');

		$found = $service->get('tpl-1', $this->reach('root', [], true));

		$this->assertSame('write', $found->getAccess());
	}

	public function testANonAdministratorWithoutAShareIsNotOfferedIt(): void {
		$this->creators = ['root|meldingen' => true];
		$service = $this->service();
		$this->row = $this->template('anna', 'meldingen');

		try {
			$service->get('tpl-1', $this->reach('root'));
			$this->fail('An unshared template must be refused.');
		} catch (RecordTemplateRefused $e) {
			$this->assertSame(404, $e->getCode());
		}
	}

	public function testAPublicTemplateIsReadForAnyCreator(): void {
		$this->creators = ['bert|meldingen' => true];
		$service = $this->service();
		$this->row = $this->template('anna', 'meldingen', [], true);

		$this->assertSame('read', $service->get('tpl-1', $this->reach('bert'))->getAccess());
	}

	public function testAnEmptySchemaIsNeverOfferedAndRightsAreNotAsked(): void {
		$this->creators = ['anna|' => true];
		$service = $this->service();
		$this->row = $this->template('anna', '');
		$this->mapper->method('findCandidates')->willReturn([$this->row]);

		$this->assertSame([], $service->listFor($this->reach('anna')));
		$this->assertSame([], $this->asked);
	}

	public function testListForHandsTheFiltersToTheMapperAndAsksRightsOncePerSchema(): void {
		$this->creators = ['anna|meldingen' => true];
		$service = $this->service();
		$first = $this->template('anna', 'meldingen');
		$second = $this->template('anna', 'meldingen');
		$other = $this->template('anna', 'vergunningen');
		$this->mapper->expects($this->once())->method('findCandidates')
			->with('anna', ListQuery::unbounded(filters: ['register' => 'zaken', 'schema' => 'meldingen']))
			->willReturn([$first, $second, $other]);

		$listed = $service->listFor($this->reach('anna'), new TemplateListOptions('zaken', 'meldingen'));

		$this->assertSame([$first, $second], $listed);
		$this->assertSame(['anna|meldingen' => 1, 'anna|vergunningen' => 1], $this->asked);
	}

	public function testABrokenSchemaLookupIsLoggedAndOffersNothing(): void {
		$this->broken = ['meldingen'];
		$service = $this->service();
		$this->row = $this->template('anna', 'meldingen');
		$this->mapper->method('findCandidates')->willReturn([$this->row]);
		$this->logger->expects($this->once())->method('warning')->with(
			'[RecordTemplateService] Could not read create rights; the template is not offered.',
			['schema' => 'meldingen', 'exception' => 'database went away']
		);

		$this->assertSame([], $service->listFor($this->reach('anna')));
	}

	public function testMovingATemplateToASchemaTheOwnerMayNotCreateInIsRefused(): void {
		$this->creators = ['anna|meldingen' => true, 'anna|geheim' => false];
		$service = $this->service();
		$this->row = $this->template('anna', 'meldingen');
		$this->mapper->expects($this->never())->method('update');

		try {
			$service->change(new TemplateChange('tpl-1', ['schema' => 'geheim'], true), $this->reach('anna'));
			$this->fail('Moving into a schema without create rights must be refused.');
		} catch (RecordTemplateRefused $e) {
			$this->assertSame(403, $e->getCode());
		}

		$this->assertSame('meldingen', $this->row->getSchema());
	}

	public function testMovingATemplateToASchemaTheOwnerMayCreateInIsSaved(): void {
		$this->creators = ['anna|meldingen' => true, 'anna|vergunningen' => true];
		$service = $this->service();
		$this->row = $this->template('anna', 'meldingen');
		$this->mapper->expects($this->once())->method('update');

		$changed = $service->change(new TemplateChange('tpl-1', ['schema' => 'vergunningen'], true), $this->reach('anna'));

		$this->assertSame('vergunningen', $changed->getSchema());
		$this->assertSame('owner', $changed->getAccess());
		$this->assertSame(1, $this->asked['anna|vergunningen']);
	}

	public function testKeepingTheSameSchemaDoesNotReAskCreateRights(): void {
		$this->creators = ['anna|meldingen' => true];
		$service = $this->service();
		$this->row = $this->template('anna', 'meldingen');

		$changed = $service->change(new TemplateChange('tpl-1', ['schema' => 'meldingen', 'name' => 'Nieuw'], true), $this->reach('anna'));

		$this->assertSame('Nieuw', $changed->getName());
		$this->assertSame(['anna|meldingen' => 1], $this->asked);
	}

	public function testCreateStampsTheCallerAsOwnerAndReturnsOwnerAccess(): void {
		$this->creators = ['anna|meldingen' => true];
		$service = $this->service();
		$this->mapper->expects($this->once())->method('insert')->with(
			$this->callback(static fn (RecordTemplate $t): bool => $t->getOwner() === 'anna' && $t->getSchema() === 'meldingen')
		)->willReturnArgument(0);

		$stored = $service->create(
			['name' => 'Melding', 'register' => 'zaken', 'schema' => 'meldingen', 'values' => ['a' => 1]],
			$this->reach('anna')
		);

		$this->assertSame('anna', $stored->getOwner());
		$this->assertSame('owner', $stored->getAccess());
	}

	public function testDeleteHandsTheAdministeredTemplateToTheMapper(): void {
		$this->creators = ['anna|meldingen' => true];
		$service = $this->service();
		$this->row = $this->template('anna', 'meldingen');
		$this->mapper->expects($this->once())->method('delete')->with($this->identicalTo($this->row))->willReturnArgument(0);

		$service->delete('tpl-1', $this->reach('anna'));
	}

	public function testDeleteByAWriteShareeWhoIsNotOwnerIsRefused(): void {
		$this->creators = ['bert|meldingen' => true];
		$service = $this->service();
		$this->row = $this->template('anna', 'meldingen', [['user' => 'bert', 'mode' => 'write']]);
		$this->mapper->expects($this->never())->method('delete');

		try {
			$service->delete('tpl-1', $this->reach('bert'));
			$this->fail('A sharee may not delete.');
		} catch (RecordTemplateRefused $e) {
			$this->assertSame(403, $e->getCode());
		}
	}
	public function testAnUnknownUuidIsRefusedAs404(): void {
		$service = $this->service();
		$this->row = $this->template('anna', 'meldingen');

		try {
			$service->get('tpl-unknown', $this->reach('anna'));
			$this->fail('A missing template must be refused.');
		} catch (RecordTemplateRefused $e) {
			$this->assertSame(404, $e->getCode());
		}
	}

	public function testASchemaThatNoLongerExistsOffersNothingWithoutAWarning(): void {
		$service = $this->service();
		$this->row = $this->template('anna', 'gone');
		$this->mapper->method('findCandidates')->willReturn([$this->row]);
		$this->logger->expects($this->never())->method('warning');

		$this->assertSame([], $service->listFor($this->reach('anna')));
	}

	public function testCreateWithASharedWithListKeepsTheValidatedShares(): void {
		$this->creators = ['anna|meldingen' => true];
		$service = $this->service();

		$stored = $service->create(
			['name' => 'Melding', 'register' => 'zaken', 'schema' => 'meldingen', 'values' => ['a' => 1], 'sharedWith' => [['group' => 'kcc', 'mode' => 'read']]],
			$this->reach('anna')
		);

		$this->assertSame([['group' => 'kcc', 'mode' => 'read']], $stored->getSharedWith());
	}
}
