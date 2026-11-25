<?php

/**
 * A content-search hit in a member of a zip attachment names the archive,
 * the member path and the owning object, and obeys the owner's read rights.
 *
 * The member path travels from the chunk's stored position reference
 * (`position_reference.member`, written by archive extraction) through the
 * keyword hit's metadata onto the result row and into the unified search
 * provider's subline.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service
 *
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/archive-members-are-searchable/specs/text-extraction/spec.md#requirement-each-member-is-extracted-and-searchable-on-its-own-req-ams-002
 */

declare(strict_types=1);

// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Service;

use Doctrine\DBAL\Platforms\MariaDBPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Files\Db\ChunkMapper;
use OCA\OpenRegister\Files\Db\FileMapper;
use OCA\OpenRegister\Kernel\Contracts\Core\AppDeepLinksInterface;
use OCA\OpenRegister\Kernel\Contracts\Core\RegisterSchemaLabelsInterface;
use OCA\OpenRegister\Kernel\Contracts\KeywordChunkSearch;
use OCA\OpenRegister\Service\Object\ContentSearchHandler;
use OCA\OpenRegister\Service\Object\ContentSearchWidening;
use OCA\OpenRegister\Service\Object\MetadataArmPage;
use OCA\OpenRegister\Service\Search\ObjectSearchResultFormatter;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class ArchiveMemberSearchTest extends TestCase {

	private const MEMBER = 'brieven/bezwaar-2024.pdf';

	private const MEMBER_TEXT = 'Bezwaar tegen de kapvergunning Lindelaan';

	/**
	 * A chunk row of an archive member, as the database returns it.
	 *
	 * @param float $score The row's score.
	 *
	 * @return array<string, mixed>
	 */
	private static function memberRow(float $score): array {
		return [
			'id' => 1,
			'source_type' => 'file',
			'source_id' => 300,
			'text_content' => self::MEMBER_TEXT,
			'chunk_index' => 0,
			'position_reference' => json_encode(['start' => 0, 'end' => 41, 'member' => self::MEMBER]),
			'score' => $score,
		];
	}//end memberRow()

	/**
	 * The ranked keyword search on PostgreSQL reads the member path off the chunk.
	 *
	 * @return void
	 */
	public function testTheRankedKeywordHitCarriesTheMemberPath(): void {
		$result = $this->createMock(IResult::class);
		$result->method('fetchAll')->willReturn([self::memberRow(score: 0.7), ['position_reference' => null] + self::memberRow(score: 0.2)]);
		$db = $this->createMock(IDBConnection::class);
		$db->method('getDatabasePlatform')->willReturn(new PostgreSQLPlatform());
		$sql = '';
		$db->method('executeQuery')->willReturnCallback(
			static function (string $query) use (&$sql, $result): IResult {
				$sql = $query;
				return $result;
			}
		);

		$hits = (new ChunkMapper($db, $this->createMock(LoggerInterface::class)))
			->searchByKeyword(search: new KeywordChunkSearch(query: 'kapvergunning Lindelaan', limit: 10));

		$this->assertStringContainsString('position_reference', $sql);
		$this->assertSame(['member' => self::MEMBER], $hits[0]['metadata']);
		$this->assertSame([], $hits[1]['metadata'], 'a chunk that is not a member keeps empty metadata');
	}//end testTheRankedKeywordHitCarriesTheMemberPath()

	/**
	 * The unranked fallback (MariaDB, SQLite) selects and reads the member path too.
	 *
	 * @return void
	 */
	public function testTheUnrankedKeywordHitCarriesTheMemberPath(): void {
		$result = $this->createMock(IResult::class);
		$result->method('fetchAll')->willReturn([self::memberRow(score: 0.0)]);
		$expr = $this->createMock(IExpressionBuilder::class);
		$expr->method('iLike')->willReturn('text_content ILIKE x');
		$qb = $this->createMock(IQueryBuilder::class);
		$selected = [];
		$qb->method('select')->willReturnCallback(
			static function (...$columns) use (&$selected, &$qb): IQueryBuilder {
				$selected = $columns;
				return $qb;
			}
		);
		foreach (['from', 'where', 'andWhere', 'orderBy', 'setMaxResults'] as $chained) {
			$qb->method($chained)->willReturnSelf();
		}

		$qb->method('expr')->willReturn($expr);
		$qb->method('createNamedParameter')->willReturnArgument(0);
		$qb->method('executeQuery')->willReturn($result);
		$db = $this->createMock(IDBConnection::class);
		$db->method('getDatabasePlatform')->willReturn(new MariaDBPlatform());
		$db->method('escapeLikeParameter')->willReturnArgument(0);
		$db->method('getQueryBuilder')->willReturn($qb);

		$hits = (new ChunkMapper($db, $this->createMock(LoggerInterface::class)))
			->searchByKeyword(search: new KeywordChunkSearch(query: 'kapvergunning', limit: 10, allowUnrankedFallback: true));

		$this->assertContains('position_reference', $selected);
		$this->assertSame(['member' => self::MEMBER], $hits[0]['metadata']);
	}//end testTheUnrankedKeywordHitCarriesTheMemberPath()

	/**
	 * A hit in a member names the zip, its file id, the member path and the owning object.
	 *
	 * @return void
	 */
	public function testAMemberHitNamesItsPath(): void {
		$owner = $this->object(id: 7);
		$objects = $this->createMock(MagicMapper::class);
		$objects->method('find')->willReturn($owner);

		$rows = $this->search(objects: $objects);

		$this->assertCount(1, $rows);
		$self = $rows[0]->jsonSerialize()['@self'];
		$this->assertSame('obj-uuid-7', $rows[0]->getUuid(), 'the row is the owning object');
		$this->assertSame('dossier-2024.zip', $self['matchedFile']);
		$this->assertSame(300, $self['matchedFileId']);
		$this->assertSame(self::MEMBER, $self['matchedMember']);
		$this->assertStringNotContainsString(self::MEMBER_TEXT, (string) json_encode($rows[0]->jsonSerialize()), 'no member text reaches the row');
	}//end testAMemberHitNamesItsPath()

	/**
	 * A caller who may not read the owning object gets no hit, and the owner is looked up under RBAC.
	 *
	 * @return void
	 */
	public function testAMemberHitObeysTheArchiveFileRights(): void {
		$objects = $this->createMock(MagicMapper::class);
		$objects->expects($this->atLeastOnce())->method('find')
			->with($this->anything(), $this->anything(), $this->anything(), $this->anything(), true, true)
			->willThrowException(new DoesNotExistException('not readable'));

		$rows = $this->search(objects: $objects);

		$this->assertSame([], $rows);
	}//end testAMemberHitObeysTheArchiveFileRights()

	/**
	 * A hit in a plain file names the file but no member.
	 *
	 * @return void
	 */
	public function testAPlainFileHitNamesNoMember(): void {
		$objects = $this->createMock(MagicMapper::class);
		$objects->method('find')->willReturn($this->object(id: 7));

		$rows = $this->search(objects: $objects, metadata: []);

		$self = $rows[0]->jsonSerialize()['@self'];
		$this->assertSame('dossier-2024.zip', $self['matchedFile']);
		$this->assertArrayNotHasKey('matchedMember', $self);
	}//end testAPlainFileHitNamesNoMember()

	/**
	 * The unified search provider's subline names the archive and the member.
	 *
	 * @return void
	 */
	public function testTheUnifiedSearchSublineNamesTheArchiveAndTheMember(): void {
		$hit = [
			'title' => 'Case 7',
			'@self' => ['id' => 'u-7', 'register' => 3, 'schema' => 5, 'matchedFile' => 'dossier-2024.zip', 'matchedFileId' => 300, 'matchedMember' => self::MEMBER],
		];

		$entry = $this->formatter()->format($hit, null)->jsonSerialize();

		$this->assertSame('Dossiq · Zaken · Zaak · dossier-2024.zip › '.self::MEMBER, $entry['subline']);
	}//end testTheUnifiedSearchSublineNamesTheArchiveAndTheMember()

	/**
	 * A provider result without a member keeps its subline as it was.
	 *
	 * @return void
	 */
	public function testAResultWithoutAMemberKeepsItsSubline(): void {
		$hit = ['title' => 'Case 7', '@self' => ['id' => 'u-7', 'register' => 3, 'schema' => 5, 'matchedFile' => 'besluit.pdf']];

		$entry = $this->formatter()->format($hit, null)->jsonSerialize();

		$this->assertSame('Dossiq · Zaken · Zaak', $entry['subline']);
	}//end testAResultWithoutAMemberKeepsItsSubline()

	/**
	 * Run a content search whose only chunk hit is in file 300.
	 *
	 * @param MagicMapper          $objects  The object mapper double.
	 * @param array<string, mixed> $metadata The hit's metadata.
	 *
	 * @return ObjectEntity[] The result rows.
	 */
	private function search(MagicMapper $objects, array $metadata=['member' => self::MEMBER]): array {
		$chunks = $this->createMock(ChunkMapper::class);
		$chunks->method('searchByKeyword')->willReturn(
			[
				[
					'entity_type' => 'file', 'entity_id' => '300', 'score' => 0.7, 'chunk_text' => self::MEMBER_TEXT,
					'chunk_index' => 0, 'metadata' => $metadata,
				],
			]
		);
		$files = $this->createMock(FileMapper::class);
		$files->method('findOwningObjectUuid')->with(300)->willReturn('obj-uuid-7');
		$files->method('getFile')->with(300)->willReturn(['fileid' => 300, 'name' => 'dossier-2024.zip']);

		$handler = new ContentSearchHandler($chunks, $files, $objects, $this->createMock(LoggerInterface::class));

		return $handler->widen(
			widening: new ContentSearchWidening(
				query: ['_search' => 'kapvergunning Lindelaan'],
				page: new MetadataArmPage(results: [], total: 0, limit: 20)
			)
		)['results'];
	}//end search()

	/**
	 * An owning object.
	 *
	 * @param int $id The object id.
	 *
	 * @return ObjectEntity
	 */
	private function object(int $id): ObjectEntity {
		$object = new ObjectEntity();
		$object->setId($id);
		$object->setUuid('obj-uuid-'.$id);
		$object->setRegister('1');
		$object->setSchema('1');

		return $object;
	}//end object()

	/**
	 * A result formatter over port doubles.
	 *
	 * @return ObjectSearchResultFormatter
	 */
	private function formatter(): ObjectSearchResultFormatter {
		$urls = $this->createMock(IURLGenerator::class);
		$urls->method('linkToRoute')->willReturn('/or/3/5/u-7');
		$deepLinks = $this->createMock(AppDeepLinksInterface::class);
		$deepLinks->method('resolveDisplayName')->willReturn('Dossiq');
		$deepLinks->method('resolveUrl')->willReturn('/apps/dossiq/cases/c-7');
		$deepLinks->method('resolveIcon')->willReturn('icon-dossiq');
		$labels = $this->createMock(RegisterSchemaLabelsInterface::class);
		$labels->method('resolveRegisterName')->willReturn('Zaken');
		$labels->method('resolveSchemaName')->willReturn('Zaak');

		return new ObjectSearchResultFormatter($urls, $deepLinks, $labels);
	}//end formatter()
}//end class
