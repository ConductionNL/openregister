<?php

/**
 * Where a schema says what happens to its records, in both the places it can.
 *
 * 🔴 TWO PLACES DECLARE ARCHIVING AND THEY NEVER MET. The `archive` column is
 * openregister's own block, filled in through the schema editor. The
 * `x-openregister-archival` annotation is the vocabulary openregister
 * publishes and its consumers write, shaped `{retention: {default, rules}}`
 * and carried in `configuration`. A schema that declared retention the
 * documented way was answered `not_applicable`, because nomination read only
 * the column. Reading both is the fix, and it lives here rather than inside
 * the nomination service so that "what did this schema declare" is one
 * question with one answer.
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
 *
 * @spec openspec/changes/archiving-as-a-process-with-sign-off/specs/retention-management/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Archival;

use DateTimeImmutable;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Reads a schema's archival declaration as one archive block.
 *
 * @psalm-suppress UnusedClass
 */
class ArchivalDeclarationReader {

	/**
	 * Constructor.
	 *
	 * The retention evaluator is defaulted rather than required because it is
	 * a pure function of the annotation and the row; the parameter exists so a
	 * test can substitute one.
	 *
	 * @param LoggerInterface    $logger    Where an annotation that decides nothing is reported.
	 * @param RetentionEvaluator $retention Picks the retention an annotation gives this row.
	 */
	public function __construct(
		private readonly LoggerInterface $logger,
		private readonly RetentionEvaluator $retention = new RetentionEvaluator(),
	) {
	}//end __construct()

	/**
	 * The archival declaration that applies to this record, wherever it lives.
	 *
	 * The `archive` column still wins when it is filled in, because it is the
	 * more specific statement.
	 *
	 * @param ObjectEntity $object The record, whose data the retention rules are matched against.
	 * @param Schema       $schema Its schema.
	 *
	 * @return array<string, mixed> An archive block, or an empty array when nothing asks for archiving.
	 *
	 * @spec openspec/changes/archiving-as-a-process-with-sign-off/specs/retention-management/spec.md
	 */
	public function read(ObjectEntity $object, Schema $schema): array {
		// `?? []` is not belt-and-braces: `Schema`'s class docblock declares
		// `@method array|null getArchive()` while the real method is
		// `getArchive(): array`. Psalm believes the tag, phpstan believes the
		// signature, and a method that returns the value straight through is
		// where the two disagree out loud. Normalising here costs nothing and
		// keeps the contradiction out of this file's contract. The stale tag
		// itself belongs to a sweep of `Schema.php`, which is moving upstream.
		$archive = ($schema->getArchive() ?? []);
		if ($archive !== [] && ($archive['enabled'] ?? false) === true) {
			return $archive;
		}

		$configuration = ($schema->getConfiguration() ?? []);
		$annotation = ($configuration['x-openregister-archival'] ?? null);
		if (is_array($annotation) === false) {
			return [];
		}

		return $this->translateAnnotation(object: $object, annotation: $annotation);
	}//end read()

	/**
	 * Read an `x-openregister-archival` block as an archive block.
	 *
	 * The retention the annotation gives THIS row is the period, matched rules
	 * and all, which is why the object is needed and a schema-wide translation
	 * would be wrong. The appraisal is destruction unless the schema names
	 * another one, because that is what the annotation means: rows leave when
	 * their term runs out, and `ArchivalRetentionTask` is the thing that
	 * removes them. A record that ought to be transferred instead is not lost
	 * by that: the nomination is a proposal, and transfer is one of the three
	 * answers a reviewer may give it.
	 *
	 * @param ObjectEntity         $object     The record.
	 * @param array<string, mixed> $annotation The `x-openregister-archival` block.
	 *
	 * @return array<string, mixed> An archive block, or an empty array when the annotation decides nothing.
	 *
	 * @spec openspec/specs/archival-annotation-vocabulary/spec.md
	 */
	private function translateAnnotation(ObjectEntity $object, array $annotation): array {
		$data = ($object->getObject() ?? []);
		if (is_array($data) === false) {
			$data = [];
		}

		try {
			$evaluation = $this->retention->evaluate(
				annotation: $annotation,
				row: $data,
				createdAt: ($object->getCreated() ?? new DateTimeImmutable())
			);
		} catch (Throwable $error) {
			$this->logger->warning(
				'[ArchivalDeclarationReader] ' . (string)$object->getUuid()
				. ' carries an x-openregister-archival block that decides no retention: '
				. $error->getMessage()
			);

			return [];
		}

		$period = $this->text(value: ($evaluation['effectiveRetention'] ?? null));
		if ($period === null) {
			return [];
		}

		$block = [
			'enabled' => true,
			'defaultNominatie' => $this->declaredAppraisal(annotation: $annotation),
			'defaultBewaartermijn' => $period,
			'defaultRule' => ArchivalNominationService::RULE_ARCHIVAL_ANNOTATION,
		];

		$classification = $this->text(value: ($annotation['category'] ?? null));
		if ($classification !== null) {
			$block['classification'] = $classification;
		}

		return $block;
	}//end translateAnnotation()

	/**
	 * The appraisal an annotation names, or destruction when it names none.
	 *
	 * `category` and `action` are keys the vocabulary validator reports as
	 * unknown-but-harmless rather than refusing, and several apps already ship
	 * them. Honouring a spelling that is already stored costs nothing; ignoring
	 * it would nominate a record for destruction that its own schema says to
	 * keep.
	 *
	 * @param array<string, mixed> $annotation The `x-openregister-archival` block.
	 *
	 * @return string The canonical appraisal.
	 */
	private function declaredAppraisal(array $annotation): string {
		$declared = $this->text(value: ($annotation['action'] ?? null));
		if ($declared === null) {
			return Appraisal::DESTROY;
		}

		return (Appraisal::CANONICAL[strtolower($declared)] ?? Appraisal::DESTROY);
	}//end declaredAppraisal()

	/**
	 * A non-empty trimmed string, or null.
	 *
	 * @param mixed $value The candidate.
	 *
	 * @return string|null The string, or null when it says nothing.
	 */
	private function text(mixed $value): ?string {
		if (is_string($value) === false && is_int($value) === false) {
			return null;
		}

		$text = trim((string)$value);
		if ($text === '') {
			return null;
		}

		return $text;
	}//end text()
}//end class
