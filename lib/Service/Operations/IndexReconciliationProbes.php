<?php

/**
 * IndexReconciliationProbes: the five checks of the search index against what it indexes.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Operations
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/search-quality-operators-and-facets/specs/zoeken-filteren/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Operations;

use OCA\OpenRegister\Kernel\Contracts\AppIdentity;
use OCP\DB\QueryBuilder\ICompositeExpression;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IAppConfig;
use OCP\IDBConnection;

/**
 * The index probes of the consistency check (REQ-SQF-006).
 *
 * Every probe is a read in the shape {@see ConsistencyCheckService} runs: a title,
 * a sentence, the table its repair acts on, what the repair does and a query
 * returning the rows it objects to. The check refuses any of them that is not a
 * SELECT, so the read-only guarantee covers these the same as the shipped ones.
 *
 * The file probes read files in the same scope as the extraction backlog
 * (`FileMapper::findUntrackedFiles()`): non-empty files under a user's `files/`
 * folder, outside trash, versions, app data, caches and thumbnails. A file that
 * extraction skipped still has its metadata chunk, so "no chunk" is "never
 * extracted and no skip recorded".
 *
 * Objects live in one table per register and schema, so a chunk or an embedding
 * of an object cannot be anti-joined against one table here; the probes read the
 * file sources, which is where the backlog and the orphans of a deleted file are.
 *
 * @spec openspec/changes/search-quality-operators-and-facets/specs/zoeken-filteren/spec.md
 */
class IndexReconciliationProbes {

	/**
	 * Repair kind: delete the rows by their `id`.
	 */
	public const REPAIR_DELETE = 'delete';

	/**
	 * Repair kind: queue a text extraction for each file id the rows name.
	 */
	public const REPAIR_QUEUE_FILE_EXTRACTION = 'queue-file-extraction';

	/**
	 * Repair kind: mark the chunks unvectorised so the vectorisation job embeds them again.
	 */
	public const REPAIR_MARK_FOR_VECTORISATION = 'mark-for-vectorisation';

	/**
	 * The chunk table.
	 */
	private const CHUNKS = 'openregister_chunks';

	/**
	 * The embedding table.
	 */
	private const VECTORS = 'openregister_vectors';

	/**
	 * The scope of a file the index should hold, as the extraction backlog reads it.
	 *
	 * @var array<string, string>
	 */
	private const FILE_SCOPE = [
		'dirType'     => 'httpd/unix-directory',
		'homePattern' => 'home::%',
		'trashPat'    => '%files_trashbin%',
		'appdataPat'  => 'appdata_%',
		'versionPat'  => '%files_versions%',
		'cachePat'    => '%cache%',
		'thumbPat'    => '%thumbnails%',
		'filesPat'    => 'files/%',
	];

	/**
	 * Constructor.
	 *
	 * @param IAppConfig $appConfig Where the file settings say whether vectorisation is on.
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
	) {
	}//end __construct()

	/**
	 * The five probes, keyed by slug.
	 *
	 * @return array<string, array<string, mixed>> The probes.
	 *
	 * @spec openspec/changes/search-quality-operators-and-facets/specs/zoeken-filteren/spec.md
	 */
	public function probes(): array {
		$vectorisationOff = null;
		if ($this->vectorisationEnabled() === false) {
			$vectorisationOff = 'Vectorisation is switched off, so missing embeddings are not checked.';
		}

		return [
			'index-file-missing' => [
				'title' => 'Files the index never read',
				'description' => 'A file in extraction scope with no chunks and no recorded skip.',
				'repair' => 'Queue a text extraction for each file.',
				'repairKind' => self::REPAIR_QUEUE_FILE_EXTRACTION,
				'repairColumn' => 'fileid',
				'table' => 'filecache',
				'query' => self::missingFilesQuery(...),
			],
			'index-chunk-orphaned' => [
				'title' => 'Chunks of a file that is gone',
				'description' => 'A chunk whose source file no longer exists.',
				'repair' => 'Delete the chunks.',
				'repairKind' => self::REPAIR_DELETE,
				'table' => self::CHUNKS,
				'query' => self::orphanedChunksQuery(...),
			],
			'index-chunk-stale' => [
				'title' => 'Chunks older than their file',
				'description' => 'A chunk written before its source file last changed.',
				'repair' => 'Queue a text extraction for each file.',
				'repairKind' => self::REPAIR_QUEUE_FILE_EXTRACTION,
				'repairColumn' => 'source_id',
				'table' => self::CHUNKS,
				'query' => self::staleChunksQuery(...),
			],
			'index-embedding-missing' => [
				'title' => 'Chunks marked embedded without an embedding',
				'description' => 'A content chunk recorded as vectorised while no embedding exists for it, with vectorisation on.',
				'repair' => 'Mark the chunks for vectorisation again.',
				'repairKind' => self::REPAIR_MARK_FOR_VECTORISATION,
				'table' => self::CHUNKS,
				'skip' => $vectorisationOff,
				'query' => self::missingEmbeddingsQuery(...),
			],
			'index-embedding-orphaned' => [
				'title' => 'Embeddings of a chunk that is gone',
				'description' => 'An embedding of a file chunk that no longer exists.',
				'repair' => 'Delete the embeddings.',
				'repairKind' => self::REPAIR_DELETE,
				'table' => self::VECTORS,
				'query' => self::orphanedEmbeddingsQuery(...),
			],
		];
	}//end probes()

	/**
	 * Whether the file settings switch vectorisation on.
	 *
	 * @return bool True when it is on.
	 */
	private function vectorisationEnabled(): bool {
		$stored = json_decode($this->appConfig->getValueString(AppIdentity::APP_ID, 'fileManagement', ''), true);

		return is_array($stored) === true && ($stored['vectorizationEnabled'] ?? false) === true;
	}//end vectorisationEnabled()

	/**
	 * Files in extraction scope that have no chunk at all.
	 *
	 * @param IQueryBuilder $qb The builder.
	 *
	 * @return IQueryBuilder The query.
	 *
	 * @spec openspec/changes/search-quality-operators-and-facets/specs/zoeken-filteren/spec.md
	 */
	public static function missingFilesQuery(IQueryBuilder $qb): IQueryBuilder {
		$qb->select('fc.fileid', 'fc.path')
			->from('filecache', 'fc')
			->leftJoin('fc', 'mimetypes', 'mt', $qb->expr()->eq('fc.mimetype', 'mt.id'))
			->leftJoin('fc', 'storages', 'st', $qb->expr()->eq('fc.storage', 'st.numeric_id'))
			->leftJoin('fc', self::CHUNKS, 'ch', self::chunkOfFile(qb: $qb, fileColumn: 'fc.fileid'))
			->where($qb->expr()->isNull('ch.id'))
			->andWhere($qb->expr()->neq('mt.mimetype', $qb->createNamedParameter(self::FILE_SCOPE['dirType'])))
			->andWhere($qb->expr()->like('st.id', $qb->createNamedParameter(self::FILE_SCOPE['homePattern'])));

		foreach (['trashPat', 'appdataPat', 'versionPat', 'cachePat', 'thumbPat'] as $excluded) {
			$qb->andWhere($qb->expr()->notLike('fc.path', $qb->createNamedParameter(self::FILE_SCOPE[$excluded])));
		}

		return $qb->andWhere($qb->expr()->like('fc.path', $qb->createNamedParameter(self::FILE_SCOPE['filesPat'])))
			->andWhere($qb->expr()->gt('fc.size', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)))
			->orderBy('fc.fileid', 'ASC');
	}//end missingFilesQuery()

	/**
	 * File chunks whose file is no longer in the file cache.
	 *
	 * @param IQueryBuilder $qb The builder.
	 *
	 * @return IQueryBuilder The query.
	 *
	 * @spec openspec/changes/search-quality-operators-and-facets/specs/zoeken-filteren/spec.md
	 */
	public static function orphanedChunksQuery(IQueryBuilder $qb): IQueryBuilder {
		return $qb->select('ch.id', 'ch.source_id', 'ch.chunk_index')
			->from(self::CHUNKS, 'ch')
			->leftJoin('ch', 'filecache', 'fc', $qb->expr()->eq('fc.fileid', 'ch.source_id'))
			->where(self::isFileChunk(qb: $qb))
			->andWhere($qb->expr()->isNull('fc.fileid'))
			->orderBy('ch.id', 'ASC');
	}//end orphanedChunksQuery()

	/**
	 * File chunks written before their file last changed.
	 *
	 * The file cache keeps its modification time as a unix timestamp and the chunk
	 * its write time as a datetime, so the timestamp is converted in the database's
	 * own dialect.
	 *
	 * @param IQueryBuilder $qb The builder.
	 *
	 * @return IQueryBuilder The query.
	 *
	 * @spec openspec/changes/search-quality-operators-and-facets/specs/zoeken-filteren/spec.md
	 */
	public static function staleChunksQuery(IQueryBuilder $qb): IQueryBuilder {
		$modified = $qb->createFunction(self::unixToDatetime(provider: $qb->getConnection()->getDatabaseProvider(), column: 'fc.mtime'));

		return $qb->select('ch.id', 'ch.source_id', 'ch.updated_at')
			->from(self::CHUNKS, 'ch')
			->innerJoin('ch', 'filecache', 'fc', $qb->expr()->eq('fc.fileid', 'ch.source_id'))
			->where(self::isFileChunk(qb: $qb))
			->andWhere($qb->expr()->lt('ch.updated_at', $modified))
			->orderBy('ch.id', 'ASC');
	}//end staleChunksQuery()

	/**
	 * Content chunks of files recorded as vectorised that have no embedding.
	 *
	 * A chunk not yet vectorised is waiting for the vectorisation job, not
	 * missing, so only the ones the job already counts as done are reported.
	 *
	 * @param IQueryBuilder $qb The builder.
	 *
	 * @return IQueryBuilder The query.
	 *
	 * @spec openspec/changes/search-quality-operators-and-facets/specs/zoeken-filteren/spec.md
	 */
	public static function missingEmbeddingsQuery(IQueryBuilder $qb): IQueryBuilder {
		return $qb->select('ch.id', 'ch.source_id', 'ch.chunk_index')
			->from(self::CHUNKS, 'ch')
			->leftJoin('ch', self::VECTORS, 'v', self::embeddingOfChunk(qb: $qb))
			->where(self::isFileChunk(qb: $qb))
			->andWhere($qb->expr()->gte('ch.chunk_index', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('ch.vectorized', $qb->createNamedParameter(true, IQueryBuilder::PARAM_BOOL)))
			->andWhere($qb->expr()->isNull('v.id'))
			->orderBy('ch.id', 'ASC');
	}//end missingEmbeddingsQuery()

	/**
	 * Embeddings of a file chunk that no longer exists.
	 *
	 * @param IQueryBuilder $qb The builder.
	 *
	 * @return IQueryBuilder The query.
	 *
	 * @spec openspec/changes/search-quality-operators-and-facets/specs/zoeken-filteren/spec.md
	 */
	public static function orphanedEmbeddingsQuery(IQueryBuilder $qb): IQueryBuilder {
		return $qb->select('v.id', 'v.entity_id', 'v.chunk_index')
			->from(self::VECTORS, 'v')
			->leftJoin('v', self::CHUNKS, 'ch', self::embeddingOfChunk(qb: $qb))
			->where($qb->expr()->eq('v.entity_type', $qb->createNamedParameter('file')))
			->andWhere($qb->expr()->isNull('ch.id'))
			->orderBy('v.id', 'ASC');
	}//end orphanedEmbeddingsQuery()

	/**
	 * The join condition of a file's chunks.
	 *
	 * @param IQueryBuilder $qb         The builder.
	 * @param string        $fileColumn The column holding the file id.
	 *
	 * @return ICompositeExpression The condition.
	 */
	private static function chunkOfFile(IQueryBuilder $qb, string $fileColumn): ICompositeExpression {
		return $qb->expr()->andX(
			$qb->expr()->eq('ch.source_id', $fileColumn),
			$qb->expr()->eq('ch.source_type', $qb->createNamedParameter('file'))
		);
	}//end chunkOfFile()

	/**
	 * The condition that a chunk comes from a file.
	 *
	 * @param IQueryBuilder $qb The builder.
	 *
	 * @return string The condition.
	 */
	private static function isFileChunk(IQueryBuilder $qb): string {
		return (string) $qb->expr()->eq('ch.source_type', $qb->createNamedParameter('file'));
	}//end isFileChunk()

	/**
	 * The join condition of a file chunk and its embedding: the embedding names the
	 * file id as text and the chunk by its index.
	 *
	 * @param IQueryBuilder $qb The builder.
	 *
	 * @return ICompositeExpression The condition.
	 */
	private static function embeddingOfChunk(IQueryBuilder $qb): ICompositeExpression {
		return $qb->expr()->andX(
			$qb->expr()->eq('v.entity_type', 'ch.source_type'),
			$qb->expr()->eq('v.entity_id', $qb->expr()->castColumn('ch.source_id', IQueryBuilder::PARAM_STR)),
			$qb->expr()->eq('v.chunk_index', 'ch.chunk_index')
		);
	}//end embeddingOfChunk()

	/**
	 * A unix timestamp column as a datetime, in the given database's dialect.
	 *
	 * @param string $provider The database provider (`mysql`, `pgsql`, `sqlite`, `oci`).
	 * @param string $column   The column holding the timestamp.
	 *
	 * @return string The SQL expression.
	 *
	 * @spec openspec/changes/search-quality-operators-and-facets/specs/zoeken-filteren/spec.md
	 */
	public static function unixToDatetime(string $provider, string $column): string {
		return match ($provider) {
			IDBConnection::PLATFORM_POSTGRES => 'TO_TIMESTAMP(' . $column . ')',
			IDBConnection::PLATFORM_SQLITE => "DATETIME(" . $column . ", 'unixepoch')",
			IDBConnection::PLATFORM_ORACLE => "(TIMESTAMP '1970-01-01 00:00:00' + NUMTODSINTERVAL(" . $column . ", 'SECOND'))",
			default => 'FROM_UNIXTIME(' . $column . ')',
		};
	}//end unixToDatetime()
}//end class
