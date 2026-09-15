<?php

/**
 * What happens to a record, decided when its business use ends.
 *
 * 🔴 NOTHING NOMINATED AN OBJECT WHEN IT CLOSED. `RetentionService::
 * applyArchivalMetadata()` runs once, at creation, and returns early the moment
 * `archiefnominatie` is set, so the archival future of a record was decided
 * before anybody knew how the case would end. A resultaat is what drives a
 * selectielijst row, and a resultaat does not exist at creation.
 *
 * 🔴 AND A NOMINATION COMPUTED ON DEMAND CANNOT BE ARGUED WITH LATER, because
 * the selectielijst will have changed. So it is derived when the object reaches
 * a terminal lifecycle state, WRITTEN on the object with the row and the rule
 * that produced it, and recomputed only by an explicit act that records who
 * asked and why.
 *
 * 🔴 AN OBJECT NOBODY CAN NOMINATE IS REPORTED, NOT SKIPPED. A record with no
 * selectielijst row and no schema default has no archival future at all, and
 * silence there is the failure mode that keeps personal data past its lawful
 * term. It gets a nomination block saying `unnominatable` and why, which reads
 * back on the object, plus a warning in the log.
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
use OCA\OpenRegister\Service\RetentionService;
use Psr\Log\LoggerInterface;

/**
 * Derives, writes and recomputes an object's archival nomination.
 *
 * @psalm-suppress UnusedClass
 */
class ArchivalNominationService {

	/**
	 * The nomination was derived and written.
	 */
	public const STATUS_NOMINATED = 'nominated';

	/**
	 * Nothing could decide this record's archival future. Reported, never silent.
	 */
	public const STATUS_UNNOMINATABLE = 'unnominatable';

	/**
	 * The schema does not ask for archiving at all.
	 */
	public const STATUS_NOT_APPLICABLE = 'not_applicable';

	/**
	 * The selectielijst row the schema's classification points at decided it.
	 */
	public const RULE_SELECTION_LIST = 'selection_list';

	/**
	 * The schema's own archive defaults decided it.
	 */
	public const RULE_SCHEMA_DEFAULT = 'schema_default';

	/**
	 * The schema's `bewaartermijnOverride`, a deliberate local decision.
	 */
	public const RULE_LOCAL_OVERRIDE = 'local_override';

	/**
	 * Constructor.
	 *
	 * @param RetentionService $retentionService Owns the selectielijst lookup and the date arithmetic.
	 * @param LoggerInterface  $logger           Where an unnominatable record is reported.
	 */
	public function __construct(
		private readonly RetentionService $retentionService,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Is this lifecycle value one the schema declares as an end?
	 *
	 * The vocabulary is `x-openregister-lifecycle.final`, the same list the
	 * transition engine locks moves out of. Reading it here rather than keeping
	 * a second list is the point: a state that stops being an end stops
	 * nominating on the same day.
	 *
	 * @param Schema $schema The object's schema.
	 * @param string $state  The lifecycle value the object just reached.
	 *
	 * @return bool True when the schema calls this state an end.
	 *
	 * @spec openspec/changes/archiving-as-a-process-with-sign-off/specs/retention-management/spec.md
	 */
	public function isTerminalState(Schema $schema, string $state): bool {
		if (trim($state) === '') {
			return false;
		}

		$configuration = ($schema->getConfiguration() ?? []);
		$annotation = ($configuration['x-openregister-lifecycle'] ?? null);
		if (is_array($annotation) === false) {
			return false;
		}

		$final = ($annotation['final'] ?? []);
		if (is_array($final) === false) {
			return false;
		}

		return in_array($state, $final, true);
	}//end isTerminalState()

	/**
	 * Derive this object's archival future and write it down.
	 *
	 * The object is mutated and returned; persisting it is the caller's job, so
	 * a nomination taken during a write joins that write rather than racing it.
	 *
	 * @param ObjectEntity $object  The record whose business use has ended.
	 * @param Schema       $schema  Its schema, holding the archive block.
	 * @param string       $trigger What caused this: `closure` or `recompute`.
	 * @param string|null  $actor   Who asked, for a recomputation.
	 * @param string|null  $reason  Why they asked, for a recomputation.
	 *
	 * @return array<string, mixed> The nomination block that was written.
	 *
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity) One branch per source of a decision,
	 *              and the reason an object could not be nominated has to name which
	 *              source was missing.
	 *
	 * @spec openspec/changes/archiving-as-a-process-with-sign-off/specs/retention-management/spec.md
	 */
	public function nominate(
		ObjectEntity $object,
		Schema $schema,
		string $trigger,
		?string $actor = null,
		?string $reason = null,
	): array {
		$archive = $schema->getArchive();
		if ($archive === [] || ($archive['enabled'] ?? false) === false) {
			return ['status' => self::STATUS_NOT_APPLICABLE];
		}

		$derived = $this->derive(object: $object, schema: $schema, archive: $archive);

		$block = [
			'status' => $derived['status'],
			'at' => (new DateTimeImmutable())->format('c'),
			'trigger' => $trigger,
		];

		if ($actor !== null) {
			$block['actor'] = $actor;
		}

		if ($reason !== null) {
			$block['reason'] = $reason;
		}

		if ($derived['status'] === self::STATUS_UNNOMINATABLE) {
			$block['unnominatableReason'] = $derived['reason'];

			$this->logger->warning(
				'[ArchivalNominationService] ' . (string)$object->getUuid()
				. ' reached a terminal state and cannot be nominated: ' . $derived['reason']
			);

			$this->write(object: $object, derived: [], block: $block);

			return $block;
		}

		$block['rule'] = $derived['rule'];
		if ($derived['selectionListRow'] !== null) {
			$block['selectionListRow'] = $derived['selectionListRow'];
		}

		$this->write(object: $object, derived: $derived, block: $block);

		return $block;
	}//end nominate()

	/**
	 * Decide the appraisal, the retention period and where each came from.
	 *
	 * @param ObjectEntity         $object  The record.
	 * @param Schema               $schema  Its schema.
	 * @param array<string, mixed> $archive The schema's archive block.
	 *
	 * @return array<string, mixed> The derivation, or a status of unnominatable with a reason.
	 *
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity) See nominate().
	 */
	private function derive(ObjectEntity $object, Schema $schema, array $archive): array {
		$classification = $this->text(value: ($archive['classification'] ?? null));

		$appraisal = null;
		$period = null;
		$rule = null;
		$row = null;
		$source = null;

		if ($classification !== null) {
			$entry = $this->retentionService->lookupSelectielijstEntry($classification);
			if ($entry !== null) {
				$appraisal = $this->text(value: ($entry['archiefnominatie'] ?? null));
				$period = $this->text(value: ($entry['bewaartermijn'] ?? null));
				$source = $this->text(value: ($entry['bron'] ?? null));
				$row = $this->text(value: ($entry['categorie'] ?? null)) ?? $classification;
				$rule = self::RULE_SELECTION_LIST;
			}
		}

		if ($appraisal === null) {
			$appraisal = $this->text(value: ($archive['defaultNominatie'] ?? null));
			$period = $this->text(value: ($archive['defaultBewaartermijn'] ?? null));
			if ($appraisal !== null) {
				$rule = self::RULE_SCHEMA_DEFAULT;
			}
		}

		if ($appraisal === null) {
			return [
				'status' => self::STATUS_UNNOMINATABLE,
				'reason' => $this->missingSourceReason(classification: $classification),
			];
		}

		$override = $this->text(value: ($archive['bewaartermijnOverride'] ?? null));
		if ($override !== null) {
			$period = $override;
			$rule = self::RULE_LOCAL_OVERRIDE;
		}

		// A record kept forever has no disposal date to count, and demanding
		// one would make every permanently preserved dossier unnominatable.
		$disposalDate = null;
		if ($period !== null) {
			$disposalDate = $this->retentionService->calculateArchiveActionDate(
				object: $object,
				schema: $schema,
				retentionPeriod: $period
			);

			if ($disposalDate === null
				&& in_array($appraisal, Appraisal::RETAIN_PERMANENTLY_ALIASES, true) === false
			) {
				return [
					'status' => self::STATUS_UNNOMINATABLE,
					'reason' => 'the retention period ' . $period
						. ' could not be counted from any date on this record',
				];
			}
		}

		return [
			'status' => self::STATUS_NOMINATED,
			'rule' => $rule,
			'appraisal' => $appraisal,
			'period' => $period,
			'disposalDate' => $disposalDate,
			'classification' => $classification,
			'source' => $source,
			'selectionListRow' => $row,
		];
	}//end derive()

	/**
	 * Say which source was missing, rather than that one was.
	 *
	 * @param string|null $classification The classification the schema declared, if any.
	 *
	 * @return string The reason.
	 */
	private function missingSourceReason(?string $classification): string {
		if ($classification !== null) {
			return 'no selectielijst row matches category ' . $classification
				. ', and the schema declares no default nomination';
		}

		return 'the schema names no selectielijst category and declares no default nomination';
	}//end missingSourceReason()

	/**
	 * Write the derivation and the nomination block onto the record.
	 *
	 * The history is appended to rather than replaced: a recomputation that
	 * overwrote the nomination it replaced would take away the one thing that
	 * explains why a disposal date moved.
	 *
	 * @param ObjectEntity         $object  The record.
	 * @param array<string, mixed> $derived The derivation, empty when unnominatable.
	 * @param array<string, mixed> $block   The nomination block.
	 *
	 * @return void
	 */
	private function write(ObjectEntity $object, array $derived, array $block): void {
		$retention = ($object->getRetention() ?? []);
		if (is_array($retention) === false) {
			$retention = [];
		}

		if ($derived !== []) {
			$retention['archiefnominatie'] = $derived['appraisal'];
			$retention['archiefstatus'] = RecordState::SEMI_STATIC;

			if ($derived['period'] !== null) {
				$retention['bewaartermijn'] = $derived['period'];
			}

			if ($derived['disposalDate'] !== null) {
				$retention['archiefactiedatum'] = $derived['disposalDate'];
			}

			if ($derived['classification'] !== null) {
				$retention['classification'] = $derived['classification'];
			}

			if ($derived['source'] !== null) {
				$retention['selectielijstBron'] = $derived['source'];
			}

			if ($derived['selectionListRow'] !== null) {
				$retention['selectielijstRow'] = $derived['selectionListRow'];
			}
		}//end if

		$retention['nomination'] = $block;

		$history = ($retention['nominationHistory'] ?? []);
		if (is_array($history) === false) {
			$history = [];
		}

		$history[] = $block;
		$retention['nominationHistory'] = $history;

		$object->setRetention($retention);
	}//end write()

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
