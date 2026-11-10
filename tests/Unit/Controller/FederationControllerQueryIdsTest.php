<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/federation-scope-enforcement/spec.md#requirement-a-share-serves-exactly-what-its-scope-grants-req-fse-001
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller;

use OCA\OpenRegister\Tests\Unit\Controller\Support\FederationControllerFactory;
use OCA\OpenRegister\Controller\FederationController;
use OCA\OpenRegister\Db\FederatedShare;
use OCA\OpenRegister\Db\FederatedShareMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\FederationShareService;
use OCA\OpenRegister\Service\ObjectService;
use OCP\IRequest;
use OCP\Security\Bruteforce\IThrottler;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use OCA\OpenRegister\Service\Object\ObjectSaveOptions;

/**
 * A query share whose own filter is an id list reaches by id only what that list names.
 *
 * The findAll double treats `ids` as MagicMapper::findAll does: written over `filters['_ids']`.
 *
 * @covers \OCA\OpenRegister\Controller\FederationController
 * @uses \OCA\OpenRegister\Controller\Support\FederationControllerServices
 * @uses   \OCA\OpenRegister\Db\FederatedShare
 * @uses   \OCA\OpenRegister\Db\ObjectEntity
 * @uses   \OCA\OpenRegister\Service\WriteCause
 */
class FederationControllerQueryIdsTest extends TestCase {

	/** @var list<array<string, mixed>> */
	private array $writes = [];

	private function record(string $uuid, ?string $slug = null): ObjectEntity {
		$record = new ObjectEntity();
		$record->setUuid($uuid);
		$record->setSlug($slug);
		$record->setOrganisation('org-a');
		$record->setObject(['title' => $uuid]);
		return $record;
	}

	/**
	 * @param array<string, mixed> $filter The share's own filter.
	 * @param ObjectEntity         $stored The record the URL id resolves to.
	 * @param list<ObjectEntity>   $table  Every record of the register and schema.
	 */
	private function controller(array $filter, ObjectEntity $stored, array $table): FederationController {
		$share = new FederatedShare();
		$share->setDirection('outgoing');
		$share->setStatus('accepted');
		$share->setScope('query');
		$share->setRegister('zaken');
		$share->setSchema('zaak');
		$share->setOrganisation('org-a');
		$share->setPermissions('read-write');
		$share->setQueryFilter($filter);
		$mapper = $this->createMock(FederatedShareMapper::class);
		$mapper->method('findByToken')->willReturn($share);

		$objects = $this->createMock(ObjectService::class);
		$objects->method('find')->willReturn($stored);
		$objects->method('findAll')->willReturnCallback(static function (array $config) use ($table): array {
			$filters = ($config['filters'] ?? []);
			if (empty($config['ids']) === false) {
				$filters['_ids'] = $config['ids'];
			}

			return array_values(array_filter($table, static function (ObjectEntity $row) use ($filters): bool {
				return empty($filters['_ids']) === true
					|| in_array($row->getUuid(), $filters['_ids'], true) === true
					|| in_array($row->getSlug(), $filters['_ids'], true) === true;
			}));
		});
		$objects->method('saveObject')->willReturnCallback(function (array|ObjectEntity $object, ObjectSaveOptions $options = new ObjectSaveOptions(), bool $_rbac = true, bool $_multitenancy = true) use ($stored): ObjectEntity {
			$args = [$object, $options->extend, $options->target->register, $options->target->schema, $options->uuid, $_rbac, $_multitenancy, $options->policy->silent, $options->policy->validation, $options->uploadedFiles, $options->actor->currentUser, $options->policy->failIfExists, $options->actor->unowned, $options->policy->dedupOverride, $options->actor->owner];
			$this->writes[] = (array)($args[0] ?? []);
			return $stored;
		});
		$objects->method('deleteObject')->willReturnCallback(function (...$args): bool {
			$this->writes[] = ['delete' => ($args[0] ?? null)];
			return true;
		});

		$request = $this->createMock(IRequest::class);
		$request->method('getParams')->willReturn(['title' => 'changed']);

		return FederationControllerFactory::create(
			'openregister',
			$request,
			$mapper,
			$objects,
			$this->createMock(FederationShareService::class),
			$this->createMock(IThrottler::class),
			$this->createMock(LoggerInterface::class)
		);
	}

	public function testAnUnlistedRecordIsNotRead(): void {
		$other = $this->record(uuid: 'obj-b');
		$c     = $this->controller(filter: ['_ids' => ['obj-a']], stored: $other, table: [$this->record(uuid: 'obj-a'), $other]);

		$this->assertSame(404, $c->object(shareToken: 't', id: 'obj-b')->getStatus());
	}

	public function testAnUnlistedRecordIsNotUpdatedOrDeleted(): void {
		$other = $this->record(uuid: 'obj-b');
		$c     = $this->controller(filter: ['_ids' => ['obj-a']], stored: $other, table: [$this->record(uuid: 'obj-a'), $other]);

		$this->assertSame(404, $c->updateObject(shareToken: 't', id: 'obj-b')->getStatus());
		$this->assertSame(404, $c->deleteObject(shareToken: 't', id: 'obj-b')->getStatus());
		$this->assertSame([], $this->writes);
	}

	public function testARecordListedByUuidIsReached(): void {
		$listed = $this->record(uuid: 'obj-a');
		$c      = $this->controller(filter: ['_ids' => ['obj-a']], stored: $listed, table: [$listed]);

		$this->assertSame(200, $c->object(shareToken: 't', id: 'obj-a')->getStatus());
		$this->assertSame(200, $c->updateObject(shareToken: 't', id: 'obj-a')->getStatus());
		$this->assertCount(1, $this->writes);
	}

	public function testARecordListedBySlugIsReached(): void {
		$listed = $this->record(uuid: 'obj-a', slug: 'zaak-een');
		$c      = $this->controller(filter: ['_ids' => ['zaak-een']], stored: $listed, table: [$listed]);

		$this->assertSame(200, $c->object(shareToken: 't', id: 'obj-a')->getStatus());
	}

	public function testAnEmptyIdListSelectsByNothingAsTheCollectionDoes(): void {
		$record = $this->record(uuid: 'obj-a');
		$c      = $this->controller(filter: ['_ids' => [], 'status' => 'open'], stored: $record, table: [$record]);

		$this->assertSame(200, $c->object(shareToken: 't', id: 'obj-a')->getStatus());
	}

	public function testAnIdListThatIsNotAListCoversNothing(): void {
		$record = $this->record(uuid: 'obj-a');
		$c      = $this->controller(filter: ['_ids' => 'obj-a'], stored: $record, table: [$record]);

		$this->assertSame(404, $c->object(shareToken: 't', id: 'obj-a')->getStatus());
	}

	public function testANonScalarListEntryDoesNotBreakTheCheck(): void {
		$record = $this->record(uuid: 'obj-a');
		$c      = $this->controller(filter: ['_ids' => [['nested'], 'obj-z']], stored: $record, table: [$record]);

		$this->assertSame(404, $c->object(shareToken: 't', id: 'obj-a')->getStatus());
	}

	public function testARecordWithoutASlugIsNotMatchedByAnEmptyListEntry(): void {
		$record = $this->record(uuid: 'obj-a');
		$c      = $this->controller(filter: ['_ids' => ['', 'obj-z']], stored: $record, table: [$record]);

		$this->assertSame(404, $c->object(shareToken: 't', id: 'obj-a')->getStatus());
	}
}
