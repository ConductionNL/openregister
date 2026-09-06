<?php

/**
 * OpenRegister ArchivalRetentionGuard
 *
 * The consumer side of the archival-immutability rule for the paths that do
 * NOT delete through a controller: the GDPR/AVG erasure services and the
 * referential-integrity cascade.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Archival
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Archival;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use Psr\Log\LoggerInterface;

/**
 * Decide whether a record is retained, and say so in words a handler can use.
 *
 * RETENTION WINS OVER ERASURE. A record on a schema declaring
 * `x-openregister-archival` is held under a legal obligation, and an erasure
 * request does not lift that obligation: GDPR art. 17(3)(b) exempts processing
 * required by law, and for a Dutch government record the Archiefwet is that law.
 * So the erasure paths REFUSE the row, RECORD the refusal, and REPORT it back
 * with the rest of the request's answer.
 *
 * The refusal is per row. A request over a mixed set still erases everything
 * that is not retained: one held record must never block a lawful erasure of
 * the others.
 *
 * FOUR DELETE DOORS ALREADY ASK THIS QUESTION — `objects#destroy`,
 * `deleted#destroy`, `bulk#delete` and `bulk#deleteSchema` via
 * {@see \OCA\OpenRegister\Service\SchemaDeletionService}. They ask it of
 * {@see Schema::hasArchivalAnnotation()}, the single definition of "is
 * archival". This guard is the fifth consumer of that same method, not a fifth
 * definition of the rule: it does not re-read the annotation, and it does not
 * restate the condition. Change the rule in one place and every door moves.
 *
 * FAILS CLOSED. A record whose schema cannot be resolved is refused, because an
 * unresolvable schema is precisely the case where the annotation cannot be read
 * and the record might be retained. That refusal is reported under its own
 * ground so a handler can tell "the law holds this" from "we could not tell".
 *
 * @spec openspec/specs/archival-annotation-vocabulary/spec.md
 */
class ArchivalRetentionGuard {

	/**
	 * Ground: the record is retained under a legal archival obligation.
	 *
	 * @var string
	 */
	public const GROUND_ARCHIVAL = 'ARCHIVAL_RETENTION_OBLIGATION';

	/**
	 * Ground: the record's schema could not be resolved, so it was left alone.
	 *
	 * @var string
	 */
	public const GROUND_UNRESOLVED = 'SCHEMA_UNRESOLVED';

	/**
	 * Context: an erasure request reached this record.
	 *
	 * @var string
	 */
	public const CONTEXT_ERASURE = 'erasure';

	/**
	 * Context: a parent's delete cascaded onto this record.
	 *
	 * @var string
	 */
	public const CONTEXT_CASCADE = 'cascade';

	/**
	 * What each refusal says, per context and ground.
	 *
	 * This text reaches a citizen exercising a legal right, so it names the
	 * obligation rather than the mechanism, and it ends on what happens next.
	 *
	 * @var array<string, array<string, array<string, string>>>
	 */
	private const WORDING = [
		self::CONTEXT_ERASURE => [
			self::GROUND_ARCHIVAL => [
				'message' => 'The law requires us to keep this record, so we did not erase it.',
				'basis' => 'GDPR art. 17(3)(b) and the Archiefwet.',
				'action' => 'Name this record in your answer to the requester. '
					. 'A records officer decides when it may be destroyed.',
			],
			self::GROUND_UNRESOLVED => [
				'message' => 'We could not read the retention rules for this record, so we left it alone.',
				'basis' => 'Precaution, because a retention rule we cannot read may be a legal one.',
				'action' => 'Ask an administrator to repair the schema, then run the request again.',
			],
		],
		self::CONTEXT_CASCADE => [
			self::GROUND_ARCHIVAL => [
				'message' => 'The law requires us to keep this record. Its parent is gone, this record stays.',
				'basis' => 'GDPR art. 17(3)(b) and the Archiefwet.',
				'action' => 'Point this record at a live parent, or record why the reference may dangle.',
			],
			self::GROUND_UNRESOLVED => [
				'message' => 'We could not read the retention rules for this record, so we left it alone.',
				'basis' => 'Precaution, because a retention rule we cannot read may be a legal one.',
				'action' => 'Ask an administrator to repair the schema, then clean up this record by hand.',
			],
		],
	];

	/**
	 * Memoised schema lookups, keyed by the raw schema identifier.
	 *
	 * Holds `false` for an identifier that could not be resolved, so a repeated
	 * miss inside one request does not re-hit the mapper.
	 *
	 * @var array<string, Schema|false>
	 */
	private array $schemaMemo = [];

	/**
	 * Constructor.
	 *
	 * @param SchemaMapper $schemaMapper Schema reader, used unscoped (see resolveSchema()).
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		private readonly SchemaMapper $schemaMapper,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Decide whether an erasure request may destroy this record.
	 *
	 * @param ObjectEntity $object The record an erasure request has reached.
	 *
	 * @return array<string, mixed>|null The withheld report, or null when the erasure may proceed.
	 *
	 * @spec openspec/specs/archival-annotation-vocabulary/spec.md
	 */
	public function erasureRefusal(ObjectEntity $object): ?array {
		return $this->refusalFor(
			uuid: (string)($object->getUuid() ?? ''),
			schemaIdentifier: $object->getSchema(),
			registerIdentifier: $object->getRegister(),
			context: self::CONTEXT_ERASURE
		);
	}//end erasureRefusal()

	/**
	 * Decide whether a parent's delete may cascade onto this record.
	 *
	 * A RETAINED RECORD STAYS LIVE EVEN WHEN ITS PARENT GOES. The cascade skips
	 * it and the parent delete still proceeds, so a retained child never blocks
	 * the delete that reached it.
	 *
	 * @param string $uuid UUID of the cascade target.
	 * @param string|int|null $schemaIdentifier Schema identifier carried on the cascade target.
	 *
	 * @return array<string, mixed>|null The retained report, or null when the cascade may proceed.
	 *
	 * @spec openspec/specs/archival-annotation-vocabulary/spec.md
	 */
	public function cascadeRefusal(string $uuid, string|int|null $schemaIdentifier): ?array {
		return $this->refusalFor(
			uuid: $uuid,
			schemaIdentifier: $schemaIdentifier,
			registerIdentifier: null,
			context: self::CONTEXT_CASCADE
		);
	}//end cascadeRefusal()

	/**
	 * Build the refusal for one record, or null when it may be deleted.
	 *
	 * @param string $uuid Record UUID.
	 * @param string|int|null $schemaIdentifier Schema identifier of the record.
	 * @param string|int|null $registerIdentifier Register identifier of the record, when known.
	 * @param string $context One of the CONTEXT_* constants.
	 *
	 * @return array<string, mixed>|null
	 */
	private function refusalFor(
		string $uuid,
		string|int|null $schemaIdentifier,
		string|int|null $registerIdentifier,
		string $context,
	): ?array {
		$schema = $this->resolveSchema(schemaIdentifier: $schemaIdentifier);

		if ($schema === null) {
			$this->logger->warning(
				message: '[ArchivalRetentionGuard] Left a record alone because its schema could not be resolved',
				context: [
					'file' => __FILE__,
					'line' => __LINE__,
					'uuid' => $uuid,
					'schema' => $schemaIdentifier,
					'operation' => $context,
				]
			);

			return $this->report(
				uuid: $uuid,
				schemaLabel: (string)($schemaIdentifier ?? 'unknown'),
				registerIdentifier: $registerIdentifier,
				ground: self::GROUND_UNRESOLVED,
				context: $context
			);
		}

		// THE SINGLE DEFINITION OF "IS ARCHIVAL", asked here and nowhere else in
		// this class. Restating the condition is what let a test agree with
		// itself in openregister#3428; this guard has no opinion of its own.
		if ($schema->hasArchivalAnnotation() === false) {
			return null;
		}

		return $this->report(
			uuid: $uuid,
			schemaLabel: ($schema->getSlug() ?? (string)$schema->getId()),
			registerIdentifier: $registerIdentifier,
			ground: self::GROUND_ARCHIVAL,
			context: $context
		);
	}//end refusalFor()

	/**
	 * Resolve a schema identifier to its entity, unscoped and memoised.
	 *
	 * READ UNSCOPED, DELIBERATELY. Every caller here runs either as a cron with
	 * no session or as a privacy officer sweeping across tenants, so an RBAC- or
	 * tenant-scoped read would answer "not found" for a schema that plainly
	 * exists. Since the guard fails closed, that miss would refuse every row in
	 * the run and quietly stop the sweep.
	 *
	 * @param string|int|null $schemaIdentifier The raw identifier from the record.
	 *
	 * @return Schema|null The schema, or null when it cannot be resolved.
	 */
	private function resolveSchema(string|int|null $schemaIdentifier): ?Schema {
		if ($schemaIdentifier === null || $schemaIdentifier === '' || $schemaIdentifier === 0) {
			return null;
		}

		$key = (string)$schemaIdentifier;
		if (array_key_exists($key, $this->schemaMemo) === true) {
			$memo = $this->schemaMemo[$key];
			if ($memo === false) {
				return null;
			}

			return $memo;
		}

		try {
			$schema = $this->schemaMapper->find(
				$schemaIdentifier,
				_rbac: false,
				_multitenancy: false
			);
		} catch (\Throwable $e) {
			$this->schemaMemo[$key] = false;
			return null;
		}

		$this->schemaMemo[$key] = $schema;

		return $schema;
	}//end resolveSchema()

	/**
	 * Put the refusal into words a handler can pass on to the requester.
	 *
	 * The `message` answers the data subject: why the record is still there. The
	 * `action` tells the handler what to do about it, because a refusal nobody
	 * acts on is the same as a silent skip.
	 *
	 * @param string $uuid Record UUID.
	 * @param string $schemaLabel Schema slug or identifier to name in the report.
	 * @param string|int|null $registerIdentifier Register identifier, when known.
	 * @param string $ground One of the GROUND_* constants.
	 * @param string $context One of the CONTEXT_* constants.
	 *
	 * @return array<string, mixed>
	 */
	private function report(
		string $uuid,
		string $schemaLabel,
		string|int|null $registerIdentifier,
		string $ground,
		string $context,
	): array {
		$wording = self::WORDING[$context][$ground];

		$report = [
			'uuid' => $uuid,
			'schema' => $schemaLabel,
			'ground' => $ground,
			'operation' => $context,
			'message' => $wording['message'],
			'basis' => $wording['basis'],
			'action' => $wording['action'],
		];

		if ($registerIdentifier !== null && $registerIdentifier !== '') {
			$report['register'] = (string)$registerIdentifier;
		}

		return $report;
	}//end report()

}//end class
