<?php

/**
 * The administered purpose list, and what makes a purpose usable.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Audit
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/audit-trail-shipped-and-purpose-bound/specs/verwerkingsregister-api/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Audit;

use OCA\OpenRegister\Db\ProcessingPurpose;
use OCA\OpenRegister\Db\ProcessingPurposeMapper;
use OCA\OpenRegister\Db\Verwerkingsactiviteit;
use OCA\OpenRegister\Db\VerwerkingsactiviteitMapper;

/**
 * Resolves a named purpose, and refuses one that cannot carry a query.
 *
 * The binding is checked against the processing register LIVE, not read off
 * the purpose's stored `activityUuid`. A purpose administered while its
 * activity existed, whose activity was then archived, is no longer bound —
 * and if the check read the stored column it would still look bound forever.
 * The stored uuid is a cache of an answer that can go stale; the register is
 * the authority.
 *
 * @spec openspec/changes/audit-trail-shipped-and-purpose-bound/specs/verwerkingsregister-api/spec.md
 */
class PurposeRegistry {
	/**
	 * The status an archived processing activity carries.
	 *
	 * @var string
	 */
	private const ACTIVITY_ARCHIVED = 'archived';

	/**
	 * Constructor.
	 *
	 * @param ProcessingPurposeMapper     $purposes   The administered purpose list.
	 * @param VerwerkingsactiviteitMapper $activities The processing activity register.
	 */
	public function __construct(
		private readonly ProcessingPurposeMapper $purposes,
		private readonly VerwerkingsactiviteitMapper $activities,
	) {
	}//end __construct()

	/**
	 * Whether the instance administers any purpose at all.
	 *
	 * An instance that declares none behaves as it did before this change:
	 * nothing is refused, because there is nothing to name. That is the
	 * backwards-compatibility promise the proposal makes, and it is read from
	 * the data rather than from a switch, so turning doelbinding on is an act
	 * of administration rather than a setting somebody has to find.
	 *
	 * @return bool True when at least one active purpose is administered.
	 *
	 * @spec openspec/changes/audit-trail-shipped-and-purpose-bound/specs/verwerkingsregister-api/spec.md
	 */
	public function hasAdministeredPurposes(): bool {
		return $this->purposes->findAll(status: ProcessingPurpose::STATUS_ACTIVE) !== [];
	}//end hasAdministeredPurposes()

	/**
	 * The purposes a caller may name, with their bindings resolved.
	 *
	 * @return ProcessingPurpose[] Every active purpose.
	 *
	 * @spec openspec/changes/audit-trail-shipped-and-purpose-bound/specs/verwerkingsregister-api/spec.md
	 */
	public function usablePurposes(): array {
		$usable = [];
		foreach ($this->purposes->findAll(status: ProcessingPurpose::STATUS_ACTIVE) as $purpose) {
			if ($this->boundActivity(purpose: $purpose) !== null) {
				$usable[] = $purpose;
			}
		}

		return $usable;
	}//end usablePurposes()

	/**
	 * The processing activity a purpose is bound to, right now.
	 *
	 * @param ProcessingPurpose $purpose The purpose to resolve.
	 *
	 * @return Verwerkingsactiviteit|null The activity, or null when the purpose is unbound.
	 *
	 * @spec openspec/changes/audit-trail-shipped-and-purpose-bound/specs/verwerkingsregister-api/spec.md
	 */
	public function boundActivity(ProcessingPurpose $purpose): ?Verwerkingsactiviteit {
		$reference = (string)($purpose->getActivity() ?? '');
		if ($reference === '') {
			$reference = (string)($purpose->getActivityUuid() ?? '');
		}

		if ($reference === '') {
			return null;
		}

		$activity = $this->activities->resolveReference(reference: $reference);
		if ($activity === null) {
			return null;
		}

		// An archived activity is one the organisation says it no longer
		// carries out. A query cannot run under a purpose that names it.
		if ($activity->getStatus() === self::ACTIVITY_ARCHIVED) {
			return null;
		}

		return $activity;
	}//end boundActivity()

	/**
	 * Resolve a named purpose to one that may carry a query, or refuse.
	 *
	 * Four refusals, named apart on purpose. "No purpose" and "a purpose that
	 * does not exist" are different mistakes by different callers, and a
	 * withdrawn purpose and an unbound one need different people to fix them:
	 * the first is administration, the second is the processing register.
	 *
	 * @param string|null $code The purpose the caller named.
	 *
	 * @return array{purpose: ProcessingPurpose, activity: Verwerkingsactiviteit} The resolved pair.
	 *
	 * @throws PurposeRefusedException When no usable purpose was named.
	 *
	 * @spec openspec/changes/audit-trail-shipped-and-purpose-bound/specs/verwerkingsregister-api/spec.md
	 */
	public function requirePurpose(?string $code): array {
		$named = trim((string)($code ?? ''));
		if ($named === '') {
			throw new PurposeRefusedException(
				rule: PurposeRefusedException::RULE_MISSING,
				reason: 'This read needs a declared purpose. Name one in the '
					. PurposeContext::HEADER . ' header or the '
					. PurposeContext::PARAM . ' parameter.',
				purpose: null,
				context: ['available' => $this->availableCodes()]
			);
		}

		$purpose = $this->purposes->resolveReference(reference: $named);
		if ($purpose === null) {
			throw new PurposeRefusedException(
				rule: PurposeRefusedException::RULE_UNKNOWN,
				reason: sprintf('Purpose "%s" is not in the administered list.', $named),
				purpose: $named,
				context: ['available' => $this->availableCodes()]
			);
		}

		if ($purpose->getStatus() !== ProcessingPurpose::STATUS_ACTIVE) {
			throw new PurposeRefusedException(
				rule: PurposeRefusedException::RULE_RETIRED,
				reason: sprintf('Purpose "%s" has been withdrawn and cannot carry a query.', $named),
				purpose: $named,
				context: ['available' => $this->availableCodes()]
			);
		}

		$activity = $this->boundActivity(purpose: $purpose);
		if ($activity === null) {
			throw new PurposeRefusedException(
				rule: PurposeRefusedException::RULE_UNBOUND,
				reason: sprintf(
					'Purpose "%s" names no processing activity, so a query cannot run under it.',
					$named
				),
				purpose: $named,
				context: ['activity' => $purpose->getActivity()]
			);
		}

		return ['purpose' => $purpose, 'activity' => $activity];
	}//end require()

	/**
	 * The codes a refusal offers the caller instead.
	 *
	 * @return string[] Every usable purpose code.
	 *
	 * @spec openspec/changes/audit-trail-shipped-and-purpose-bound/specs/verwerkingsregister-api/spec.md
	 */
	private function availableCodes(): array {
		$codes = [];
		foreach ($this->usablePurposes() as $purpose) {
			$code = $purpose->getCode();
			if ($code !== null && $code !== '') {
				$codes[] = $code;
			}
		}

		return $codes;
	}//end availableCodes()
}//end class
