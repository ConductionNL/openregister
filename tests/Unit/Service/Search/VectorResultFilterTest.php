<?php

/**
 * VectorResultFilter keeps only the hits the caller may open, and narrows objects to views.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Search
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/search-file-text-and-vector-facade
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Search;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Files\Service\File\FileReadScope;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindOptions;
use OCA\OpenRegister\Search\Db\View;
use OCA\OpenRegister\Search\Service\ViewService;
use OCA\OpenRegister\Service\Search\VectorResultFilter;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Results of two users: alice searches, bob's file and a record alice may not read must not come back.
 */
#[CoversClass(VectorResultFilter::class)]
#[UsesClass(ObjectEntity::class)]
#[UsesClass(ObjectFindOptions::class)]
#[UsesClass(View::class)]
class VectorResultFilterTest extends TestCase {

	private FileReadScope&MockObject $files;

	private ObjectOperationsInterface&MockObject $objects;

	private ViewService&MockObject $views;

	private IUserSession&MockObject $session;

	private VectorResultFilter $filter;

	protected function setUp(): void {
		parent::setUp();
		$this->files = $this->createMock(FileReadScope::class);
		$this->objects = $this->createMock(ObjectOperationsInterface::class);
		$this->views = $this->createMock(ViewService::class);
		$this->session = $this->createMock(IUserSession::class);

		$alice = $this->createMock(IUser::class);
		$alice->method('getUID')->willReturn('alice');
		$this->session->method('getUser')->willReturn($alice);

		// Alice can open file 10 (hers); file 20 is bob's.
		$this->files->method('readableResults')->willReturnCallback(
			static fn (array $results): array => array_values(array_filter($results, static fn (array $r): bool => (string)$r['entity_id'] === '10'))
		);

		// Alice may read object obj-a; obj-b is refused by RBAC, obj-c is not found.
		$this->objects->method('find')->willReturnCallback(
			static function (int|string $id): ?ObjectEntity {
				if ($id === 'obj-b') {
					throw new RuntimeException('forbidden');
				}

				if ($id === 'obj-a') {
					return new ObjectEntity();
				}

				return null;
			}
		);

		$this->filter = new VectorResultFilter($this->files, $this->objects, $this->views, $this->session);
	}

	/**
	 * @param string               $type     The entity type.
	 * @param int|string           $id       The entity id.
	 * @param array<string, mixed> $metadata The metadata.
	 *
	 * @return array<string, mixed>
	 */
	private static function hit(string $type, int|string $id, array $metadata = []): array {
		return ['entity_type' => $type, 'entity_id' => $id, 'chunk_text' => $type . ' ' . $id, 'similarity' => 0.9, 'metadata' => $metadata];
	}

	public function testAnotherUsersFileAndAnUnreadableRecordAreDropped(): void {
		$results = [
			self::hit('file', 20),
			self::hit('object', 'obj-a'),
			self::hit('file', 10),
			self::hit('object', 'obj-b'),
			self::hit('object', 'obj-c'),
		];

		$kept = $this->filter->readable($results);

		$this->assertSame([['object', 'obj-a'], ['file', 10]], array_map(static fn (array $r): array => [$r['entity_type'], $r['entity_id']], $kept));
	}

	public function testAnUnknownTypeOrMissingIdIsDropped(): void {
		$this->assertSame([], $this->filter->readable([self::hit('chat', 'c1'), ['entity_type' => 'object', 'entity_id' => '']]));
	}

	public function testEachEntityIsCheckedOnceHoweverManyChunksRank(): void {
		$objects = $this->createMock(ObjectOperationsInterface::class);
		$objects->expects($this->once())->method('find')->willReturn(new ObjectEntity());
		$filter = new VectorResultFilter($this->files, $objects, $this->views, $this->session);

		$kept = $filter->readable([self::hit('object', 'obj-a'), self::hit('object', 'obj-a'), self::hit('object', 'obj-a')]);

		$this->assertCount(3, $kept);
	}

	public function testTheObjectCheckRunsAsTheCallerInItsRegisterAndSchema(): void {
		$objects = $this->createMock(ObjectOperationsInterface::class);
		$objects->expects($this->once())->method('find')
			->with(
				'obj-a',
				$this->callback(static fn (ObjectFindOptions $o): bool => $o->register === 3 && $o->schema === 'beleid' && $o->audit === false),
				true,
				true
			)
			->willReturn(new ObjectEntity());
		$filter = new VectorResultFilter($this->files, $objects, $this->views, $this->session);

		$filter->readable([self::hit('object', 'obj-a', ['register' => 3, 'schema' => 'beleid'])]);
	}

	public function testWithoutACallerNothingIsReadable(): void {
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn(null);
		$filter = new VectorResultFilter($this->files, $this->objects, $this->views, $session);

		$this->assertSame([], $filter->readable([self::hit('file', 10), self::hit('object', 'obj-a')]));
	}

	public function testViewsNarrowObjectsToTheirRegistersAndSchemasAndLeaveFiles(): void {
		$view = new View();
		$view->setQuery(['registers' => [3], 'schemas' => ['beleid']]);
		$this->views->method('find')->with(7, 'alice')->willReturn($view);

		$results = [
			self::hit('object', 'in', ['register' => '3', 'schema' => 'beleid']),
			self::hit('object', 'other-schema', ['register' => 3, 'schema' => 'vergunning']),
			self::hit('object', 'no-placement'),
			self::hit('file', 10),
		];

		$kept = $this->filter->inViews($results, [7]);

		$this->assertSame(['in', 10], array_column($kept, 'entity_id'));
	}

	public function testAViewTheCallerCannotUseLeavesNoObjects(): void {
		$this->views->method('find')->willThrowException(new DoesNotExistException('View not found or access denied'));

		$kept = $this->filter->inViews([self::hit('object', 'obj-a', ['register' => 3]), self::hit('file', 10)], [99]);

		$this->assertSame([10], array_column($kept, 'entity_id'));
	}

	public function testNoViewsMeansNoNarrowing(): void {
		$results = [self::hit('object', 'obj-a'), self::hit('file', 10)];

		$this->views->expects($this->never())->method('find');
		$this->assertSame($results, $this->filter->inViews($results, []));
	}
}
