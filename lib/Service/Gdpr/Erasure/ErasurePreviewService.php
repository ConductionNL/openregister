<?php

/**
 * ErasurePreviewService — what an erasure would touch, before it touches it.
 *
 * OpenRegister could already erase a data subject across every register: the
 * discovery, the mode parameter, the legal hold and the archival refusal all
 * live in {@see \OCA\OpenRegister\Service\Gdpr\DataSubjectRequestService}. What
 * it could not do is ANSWER FIRST. `erase(dryRun: true)` reports one list of
 * things it would have erased, which is the half a gemeente does not need: the
 * lawful answer to a data subject under the Archiefwet is "deels niet, en dit
 * is waarom", and that sentence needs the protected count and its ground.
 *
 * So this service classifies every hit into one of three buckets and counts
 * four kinds per bucket, and it writes nothing at all while it does so.
 *
 * WHAT IT REUSES RATHER THAN REBUILDS:
 *   - the cross-register discovery, through DataSubjectRequestService::findSubjectObjects();
 *   - the retention refusal, through ArchivalRetentionGuard::erasureRefusal(),
 *     which already names the legal hold, the Archiefwet obligation and the
 *     unresolvable schema with a sentence to pass back to the requester;
 *   - the immutable archival status, through RetentionService::validateNotImmutable();
 *   - the per-object destruction scope, through DestructionScopeService::preview(),
 *     which is exactly "what goes with the object" and therefore exactly what
 *     an erasable object's files and timeline rows cost.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Service
 * @package   OCA\OpenRegister\Service\Gdpr\Erasure
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git-id>
 * @link      https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Gdpr\Erasure;

use DateTimeImmutable;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\Gdpr\DataSubjectRequestService;
use OCA\OpenRegister\Service\Gdpr\SubjectQuery;
use OCA\OpenRegister\Service\Gdpr\SubjectMatch;

/**
 * Counts what an erasure would touch, split three ways, writing nothing.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Gdpr\Erasure
 *
 * @spec openspec/specs/gdpr-data-subject-rights/spec.md
 */
class ErasurePreviewService implements ErasurePreviewServiceInterface {
	/**
	 * Wire the authorities the preview asks.
	 *
	 * @param DataSubjectRequestService $subjects     Cross-register discovery.
	 * @param ErasureProtectionResolver $protection   Legal hold, retention and immutable status, in that order.
	 * @param ErasureCounters $counters Co-subjects, the destruction scope and the party records.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly DataSubjectRequestService $subjects,
		private readonly ErasureProtectionResolver $protection,
		private readonly ErasureCounters $counters,
	) {
	}//end __construct()

	/**
	 * Preview an erasure: the counts, the named protected records, a digest.
	 *
	 * Writes nothing. Every collaborator it calls is a read, and the two that
	 * could refuse in a way the caller cannot see — the archival guard and the
	 * retention service — are called inside a catch that counts an unanswerable
	 * question as PROTECTED (D-2).
	 *
	 * @param SubjectQuery $subject   Subject identifier value (email, bsn, …) and optional GdprEntity type
	 *                                filter; the match is always exact.
	 * @param string       $eraseMode `pseudonymise` or `whole-object`.
	 *
	 * @return array<string, mixed> The preview: counts, items, protected, digest.
	 *
	 * @spec openspec/specs/gdpr-data-subject-rights/spec.md
	 */
	public function preview(
		SubjectQuery $subject,
		string $eraseMode = DataSubjectRequestService::ERASE_MODE_PSEUDONYMISE,
	): array {
		$run = new ErasurePreviewRun(subjectId: trim($subject->subjectId), type: $subject->type, eraseMode: $this->normaliseMode(eraseMode: $eraseMode));

		return $this->assemble(run: $run, items: $this->classifyAll(run: $run));
	}//end preview()

	/**
	 * The digest of a preview: what was approved, so a stale approval is seen.
	 *
	 * Covers the request (subject, type, mode) and the classified outcome of
	 * every object. It deliberately does NOT cover `generatedAt`, so previewing
	 * the same unchanged world twice agrees.
	 *
	 * @param array<string, mixed> $preview The preview to digest.
	 *
	 * @return string A hex sha256.
	 *
	 * @spec openspec/specs/gdpr-data-subject-rights/spec.md
	 */
	public function digest(array $preview): string {
		$items = [];
		foreach (($preview['items'] ?? []) as $item) {
			$items[] = $this->digestItem(item: $item);
		}

		usort($items, static fn (array $left, array $right): int => strcmp((string)$left['uuid'], (string)$right['uuid']));

		$material = [
			'subject' => ($preview['subject'] ?? ''),
			'type' => ($preview['type'] ?? null),
			'eraseMode' => ($preview['eraseMode'] ?? ''),
			'items' => $items,
		];

		return hash('sha256', (string)json_encode($material));
	}//end digest()

	/**
	 * The part of one preview item the digest covers.
	 *
	 * @param array<string, mixed> $item The preview item.
	 *
	 * @return array<string, mixed> Its uuid, bucket, ground and counts.
	 */
	private function digestItem(array $item): array {
		return [
			'uuid' => ($item['uuid'] ?? ''),
			'bucket' => ($item['bucket'] ?? ''),
			'ground' => ($item['ground'] ?? null),
			'counts' => ($item['counts'] ?? []),
		];
	}//end digestItem()

	/**
	 * Classify every object the subject is found on.
	 *
	 * @param ErasurePreviewRun $run The subject, type filter and normalised mode.
	 *
	 * @return array<int, array<string, mixed>> The preview items, in discovery order.
	 */
	private function classifyAll(ErasurePreviewRun $run): array {
		$items = [];
		foreach ($this->subjects->findSubjectObjects($run->query()) as $hit) {
			$items[] = $this->classify(match: new SubjectMatch(object: $hit['object'], matched: $hit['gdprEntities']), run: $run);
		}

		return $items;
	}//end classifyAll()

	/**
	 * Put the classified items together into the preview, digest included.
	 *
	 * @param ErasurePreviewRun                $run   The subject, type filter and normalised mode.
	 * @param array<int, array<string, mixed>> $items The classified items.
	 *
	 * @return array<string, mixed> The preview.
	 */
	private function assemble(ErasurePreviewRun $run, array $items): array {
		$preview = [
			'subject' => $run->subjectId,
			'type' => $run->type,
			'eraseMode' => $run->eraseMode,
			'generatedAt' => (new DateTimeImmutable())->format(DATE_ATOM),
			'matchedCount' => count($items),
			'counts' => $this->tally(items: $items),
			'items' => $items,
			// D-1: the protected records are listed again, by name, because the
			// count alone does not let a handler write the sentence the data
			// subject is owed.
			'protected' => $this->protectedOf(items: $items),
		];

		$preview['digest'] = $this->digest(preview: $preview);

		return $preview;
	}//end assemble()

	/**
	 * The protected items, in order.
	 *
	 * @param array<int, array<string, mixed>> $items The classified items.
	 *
	 * @return array<int, array<string, mixed>> The protected ones.
	 */
	private function protectedOf(array $items): array {
		return array_values(array_filter($items, static fn (array $item): bool => $item['bucket'] === ErasureBucket::PROTECTED));
	}//end protectedOf()

	/**
	 * Sum every item's counts into its bucket.
	 *
	 * @param array<int, array<string, mixed>> $items The classified items.
	 *
	 * @return array<string, array<string, int>> The counts per bucket and kind.
	 */
	private function tally(array $items): array {
		$counts = ErasureBucket::emptyCounts();
		foreach ($items as $item) {
			foreach ($item['counts'] as $kind => $value) {
				$counts[$item['bucket']][$kind] += $value;
			}
		}

		return $counts;
	}//end tally()

	/**
	 * Classify one object and count what an erasure would cost on it.
	 *
	 * @param SubjectMatch      $match The object and the PII hits that pulled it in.
	 * @param ErasurePreviewRun $run   The subject value and the requested mode.
	 *
	 * @return array<string, mixed> One preview item.
	 */
	private function classify(SubjectMatch $match, ErasurePreviewRun $run): array {
		$item = $this->baseItem(match: $match);
		$ground = $this->protection->protectionFor(object: $match->object);
		if ($ground !== null) {
			// A protected record is not destroyed and not scrubbed, so the only
			// thing it costs is itself. Counting its files here would read as
			// "these files go", which is the opposite of what protected means.
			return array_merge($item, $ground, ['bucket' => ErasureBucket::PROTECTED, 'counts' => [ErasureBucket::OBJECTS => 1]]);
		}

		return array_merge($item, $this->erasureCost(match: $match, run: $run));
	}//end classify()

	/**
	 * The bucket and counts of an unprotected object under the requested mode.
	 *
	 * @param SubjectMatch      $match The object and the PII hits that pulled it in.
	 * @param ErasurePreviewRun $run   The subject value and the requested mode.
	 *
	 * @return array<string, mixed> The `bucket` and `counts` of the preview item.
	 */
	private function erasureCost(SubjectMatch $match, ErasurePreviewRun $run): array {
		if ($run->eraseMode === DataSubjectRequestService::ERASE_MODE_WHOLE_OBJECT) {
			return $this->wholeObject(match: $match, subjectId: $run->subjectId);
		}

		// Pseudonymisation leaves the object, its folder and its timeline where
		// they are: only the subject's values inside the payload move.
		$parties = $this->countPartyRecords(match: $match, subjectId: $run->subjectId);

		return ['bucket' => ErasureBucket::PSEUDONYMISED, 'counts' => $this->scrubCounts(parties: $parties)];
	}//end erasureCost()

	/**
	 * What every preview item says about its object, whatever its bucket.
	 *
	 * @param SubjectMatch $match The object and the PII hits that pulled it in.
	 *
	 * @return array<string, mixed> The uuid, register, schema and matched types.
	 */
	private function baseItem(SubjectMatch $match): array {
		return [
			'uuid' => (string)($match->object->getUuid() ?? ''),
			'register' => $match->object->getRegister(),
			'schema' => $match->object->getSchema(),
			'matchedOn' => array_values(array_unique(array_filter(array_column($match->matched, 'type')))),
		];
	}//end baseItem()

	/**
	 * The outcome of a whole-object request on an unprotected object.
	 *
	 * DESTROYING A SHARED RECORD ERASES SOMEBODY WHO DID NOT ASK. A record
	 * carrying another person's identifier of the same kind is not this
	 * subject's record to destroy, so the whole-object request is downgraded to
	 * a scrub and the downgrade is named.
	 *
	 * @param SubjectMatch $match     The object and the PII hits.
	 * @param string       $subjectId The subject value.
	 *
	 * @return array<string, mixed> The bucket, any downgrade ground, and the counts.
	 */
	private function wholeObject(SubjectMatch $match, string $subjectId): array {
		$parties = $this->countPartyRecords(match: $match, subjectId: $subjectId);
		$shared = $this->counters->coSubjects->otherSubjectsOn(match: $match, subjectId: $subjectId);
		if ($shared !== []) {
			return [
				'bucket' => ErasureBucket::PSEUDONYMISED,
				'ground' => ErasureBucket::GROUND_SHARED_RECORD,
				'message' => 'This record also holds another person\'s data, so it is scrubbed rather than destroyed.',
				'sharedWith' => $shared,
				'counts' => $this->scrubCounts(parties: $parties),
			];
		}

		return ['bucket' => ErasureBucket::ERASABLE, 'counts' => $this->destroyCounts(object: $match->object, parties: $parties)];
	}//end wholeObject()

	/**
	 * The counts of a destroyed object: itself, its scope and its party records.
	 *
	 * @param ObjectEntity $object  The object.
	 * @param int          $parties Party records naming the subject.
	 *
	 * @return array<string, int> The counts.
	 */
	private function destroyCounts(ObjectEntity $object, int $parties): array {
		$scope = $this->counters->scopeCounter->scopeCounts(object: $object);

		return [
			ErasureBucket::OBJECTS => 1,
			ErasureBucket::FILES => $scope[ErasureBucket::FILES],
			ErasureBucket::TIMELINE => $scope[ErasureBucket::TIMELINE],
			ErasureBucket::PARTIES => $parties,
		];
	}//end destroyCounts()

	/**
	 * The counts of a scrubbed object: itself and its party records.
	 *
	 * @param int $parties Party records naming the subject.
	 *
	 * @return array<string, int> The counts.
	 */
	private function scrubCounts(int $parties): array {
		return [
			ErasureBucket::OBJECTS => 1,
			ErasureBucket::PARTIES => $parties,
		];
	}//end scrubCounts()

	/**
	 * Count the party records inside an object that name this subject.
	 *
	 * @param SubjectMatch $match     The object and the PII hits.
	 * @param string       $subjectId The subject value.
	 *
	 * @return int The number of party records naming the subject.
	 */
	private function countPartyRecords(SubjectMatch $match, string $subjectId): int {
		return $this->counters->parties->count(
			payload: ($match->object->getObject() ?? []),
			needles: $this->counters->parties->needles(subjectId: $subjectId, matched: $match->matched)
		);
	}//end countPartyRecords()

	/**
	 * Fold an unknown mode onto the safe one.
	 *
	 * @param string $eraseMode The requested mode.
	 *
	 * @return string A known mode.
	 */
	private function normaliseMode(string $eraseMode): string {
		if ($eraseMode === DataSubjectRequestService::ERASE_MODE_WHOLE_OBJECT) {
			return DataSubjectRequestService::ERASE_MODE_WHOLE_OBJECT;
		}

		return DataSubjectRequestService::ERASE_MODE_PSEUDONYMISE;
	}//end normaliseMode()
}//end class
