<?php

/**
 * What an access question that cannot be decided means for one schema.
 *
 * A question is undecidable when the effective authorization block cannot be
 * resolved: the register cascade is unreadable, the schema's register is gone,
 * or the evaluation throws. The schema declares the answer in its own block as
 * `authorization.onUndecidable`, `closed` or `open`; absent, or anything else
 * that reaches the runtime, means `closed`.
 *
 * The posture is read from the schema's OWN block, never from the resolved
 * one: the resolved block is exactly what could not be computed.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Rbac
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/an-app-declares-object-access-rather-than-guarding-it/specs/object-access-contract/spec.md#requirement-a-schema-declares-what-an-undecidable-access-question-means
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Rbac;

/**
 * The declared posture for an undecidable access question: closed unless the schema says open.
 *
 * @spec openspec/changes/an-app-declares-object-access-rather-than-guarding-it/specs/object-access-contract/spec.md#requirement-a-schema-declares-what-an-undecidable-access-question-means
 */
final class UndecidablePosture {

	/**
	 * The key in an authorization block.
	 */
	public const KEY = 'onUndecidable';

	/**
	 * Refuse the question. The default.
	 */
	public const CLOSED = 'closed';

	/**
	 * Grant the question, and still log it.
	 */
	public const OPEN = 'open';

	/**
	 * The posture a block declares; `closed` when it declares none or anything other than `open`.
	 *
	 * @param array|null $authorization The schema's own authorization block.
	 *
	 * @return string One of CLOSED or OPEN.
	 *
	 * @spec openspec/changes/an-app-declares-object-access-rather-than-guarding-it/specs/object-access-contract/spec.md#requirement-a-schema-declares-what-an-undecidable-access-question-means
	 */
	public function of(?array $authorization): string {
		if (is_array($authorization) === true && ($authorization[self::KEY] ?? null) === self::OPEN) {
			return self::OPEN;
		}

		return self::CLOSED;
	}//end of()

	/**
	 * Whether an undecidable question is granted under the block's posture.
	 *
	 * @param array|null $authorization The schema's own authorization block.
	 *
	 * @return bool True only when the block says `open`.
	 *
	 * @spec openspec/changes/an-app-declares-object-access-rather-than-guarding-it/specs/object-access-contract/spec.md#requirement-a-schema-declares-what-an-undecidable-access-question-means
	 */
	public function admits(?array $authorization): bool {
		return $this->of(authorization: $authorization) === self::OPEN;
	}//end admits()

	/**
	 * The block without its posture, for the question "does this schema carry rules of its own".
	 *
	 * A schema whose block holds nothing but `onUndecidable` still takes its
	 * rules from its register, exactly as an empty block does: the posture is
	 * what to do when that cascade fails, so it must not stop the cascade.
	 *
	 * @param array|null $authorization The schema's own authorization block.
	 *
	 * @return array|null The block without `onUndecidable`; null or empty when nothing else is left.
	 *
	 * @spec openspec/changes/an-app-declares-object-access-rather-than-guarding-it/specs/object-access-contract/spec.md#requirement-a-schema-declares-what-an-undecidable-access-question-means
	 */
	public function rulesOf(?array $authorization): ?array {
		if (is_array($authorization) === false) {
			return $authorization;
		}

		unset($authorization[self::KEY]);
		return $authorization;
	}//end rulesOf()

	/**
	 * Why a declared value cannot be saved, or null when it can.
	 *
	 * @param mixed $value The value of `onUndecidable`.
	 *
	 * @return string|null The refusal, or null for `closed` and `open`.
	 *
	 * @spec openspec/changes/an-app-declares-object-access-rather-than-guarding-it/specs/object-access-contract/spec.md#requirement-a-schema-declares-what-an-undecidable-access-question-means
	 */
	public function refusalFor(mixed $value): ?string {
		if ($value === self::CLOSED || $value === self::OPEN) {
			return null;
		}

		return 'must be "' . self::CLOSED . '" or "' . self::OPEN . '"';
	}//end refusalFor()
}//end class
