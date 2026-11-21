<?php

/**
 * Which records a retention pass hands to the anonymisation sweep, and in what shape.
 *
 * A record is nominated for anonymising the way it is nominated for
 * destruction: its retention block names the action (`anonymise`, or any of
 * its spellings such as the `anonymiseren` a result type carries), and the
 * archiefactiedatum has passed while the record is still live. The action
 * comes from configuration, a selectielijst row, a result type or a schema
 * default, so nobody chooses it per record.
 *
 * 🔴 A RECORD NOMINATED FOR ANONYMISING IS NEVER A DESTRUCTION CANDIDATE, AND A
 * RECORD NOMINATED FOR DESTRUCTION IS NEVER AN ANONYMISATION CANDIDATE. The
 * destruction sweep reads only the destroy spellings; this reads only the
 * anonymise spellings. Either one reading the other's would turn "keep it
 * without the name" into "delete it", or the reverse.
 *
 * A legal hold is not refused here: it is carried into the candidate, so the
 * run refuses it naming the hold and the sweep reports that reason per record.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Archival
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/anonymising-as-an-archival-outcome/specs/retention-management/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Archival;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;

/**
 * Derives anonymisation candidates from nominated records.
 *
 * @spec openspec/specs/retention-management/spec.md
 */
final class AnonymisationCandidates {

	/**
	 * The record's retention block does not name the anonymise action.
	 *
	 * @var string
	 */
	public const REFUSAL_NOT_NOMINATED = 'not_nominated_for_anonymisation';

	/**
	 * The record is no longer live (destroyed, transferred, ...).
	 *
	 * @var string
	 */
	public const REFUSAL_NOT_LIVE = 'record_not_live';

	/**
	 * The archiefactiedatum is absent or still in the future.
	 *
	 * @var string
	 */
	public const REFUSAL_NOT_DUE = 'action_date_not_reached';

	/**
	 * The record's schema declares no anonymisation profile.
	 *
	 * @var string
	 */
	public const REFUSAL_NO_PROFILE = 'schema_declares_no_anonymisation_profile';

	/**
	 * Where on the record's retention block the act's marker is kept.
	 *
	 * @var string
	 */
	public const MARKER_KEY = 'anonymisation';

	/**
	 * Why a record is not handed to the anonymisation sweep today, or null when it is.
	 *
	 * @param ObjectEntity $object The record.
	 * @param Schema       $schema Its schema.
	 * @param string       $today  Today, as Y-m-d.
	 *
	 * @return string|null One of the REFUSAL_* reasons, or null.
	 *
	 * @spec openspec/changes/anonymising-as-an-archival-outcome/tasks.md#task-2.3
	 */
	public function refusalOf(ObjectEntity $object, Schema $schema, string $today): ?string {
		$retention = ($object->getRetention() ?? []);
		if (in_array(($retention['archiefnominatie'] ?? null), Appraisal::ANONYMISE_ALIASES, true) === false) {
			return self::REFUSAL_NOT_NOMINATED;
		}

		if ($this->isLive(retention: $retention) === false) {
			return self::REFUSAL_NOT_LIVE;
		}

		$actionDate = ($retention['archiefactiedatum'] ?? null);
		if (is_string($actionDate) === false || $actionDate === '' || substr($actionDate, 0, 10) > $today) {
			return self::REFUSAL_NOT_DUE;
		}

		if ($this->profileBlock(schema: $schema) === []) {
			return self::REFUSAL_NO_PROFILE;
		}

		return null;
	}//end refusalOf()

	/**
	 * The record as the anonymisation sweep reads a candidate, or null when it is not one.
	 *
	 * The shape is the one {@see AnonymisationBatch} documents: the uuid, the
	 * payload, the block declaring the profile, the profile's name (the
	 * schema's slug), the marker of an earlier act, and the legal hold.
	 *
	 * @param ObjectEntity $object The record.
	 * @param Schema       $schema Its schema.
	 * @param string       $today  Today, as Y-m-d.
	 *
	 * @return array<string, mixed>|null The candidate (uuid, payload, annotation, profileName,
	 *                                   marker, hasLegalHold, holdReason), or null.
	 *
	 * @spec openspec/changes/anonymising-as-an-archival-outcome/tasks.md#task-2.3
	 */
	public function candidateOf(ObjectEntity $object, Schema $schema, string $today): ?array {
		if ($this->refusalOf(object: $object, schema: $schema, today: $today) !== null) {
			return null;
		}

		$retention = ($object->getRetention() ?? []);
		$hold      = ($retention['legalHold'] ?? []);
		if (is_array($hold) === false) {
			$hold = [];
		}

		$marker = ($retention[self::MARKER_KEY] ?? []);
		if (is_array($marker) === false) {
			$marker = [];
		}

		return [
			'uuid'         => (string)$object->getUuid(),
			'payload'      => ($object->getObject() ?? []),
			'annotation'   => $this->profileBlock(schema: $schema),
			'profileName'  => (string)($schema->getSlug() ?? $schema->getTitle() ?? ''),
			'marker'       => $marker,
			'hasLegalHold' => (($hold['active'] ?? false) === true),
			'holdReason'   => (string)($hold['reason'] ?? ''),
		];
	}//end candidateOf()

	/**
	 * The block declaring the schema's profile: `x-openregister-archival`, else `archive`.
	 *
	 * Schema save refuses a profile declared in both, so at most one carries it.
	 *
	 * @param Schema $schema The schema.
	 *
	 * @return array<string, mixed> The block, or an empty array when neither declares a profile.
	 */
	private function profileBlock(Schema $schema): array {
		$archival = (($schema->getConfiguration() ?? [])['x-openregister-archival'] ?? []);
		if (is_array($archival) === true && is_array($archival[AnonymisationProfile::ANNOTATION_KEY] ?? null) === true) {
			return $archival;
		}

		$archive = $schema->getArchive();
		if (is_array($archive[AnonymisationProfile::ANNOTATION_KEY] ?? null) === true) {
			return $archive;
		}

		return [];
	}//end profileBlock()

	/**
	 * Whether the record is still live; an absent state means live.
	 *
	 * @param array<string, mixed> $retention The retention block.
	 *
	 * @return bool
	 */
	private function isLive(array $retention): bool {
		$state = ($retention['archiefstatus'] ?? null);
		if ($state === null || (is_string($state) === true && trim($state) === '')) {
			return true;
		}

		return in_array($state, RecordState::ACTIVE_ALIASES, true);
	}//end isLive()
}//end class
