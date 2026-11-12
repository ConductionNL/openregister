<?php

/**
 * OpenRegister Example Set Field Rules
 *
 * The checks the rows of an example set share before anything is written: no
 * field the kind does not know, the fields it needs filled, only accounts the
 * set creates or adopts, and dates that read as dates.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\ExampleSet
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 *
 * @spec openspec/specs/example-sets/spec.md#requirement-a-fragment-may-declare-saved-views-for-the-sets-own-accounts
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\ExampleSet;

use DateTime;
use Throwable;

/**
 * Field checks shared by the kinds of row an example set writes; each answers why a row is refused, or ''.
 *
 * @spec openspec/specs/example-sets/spec.md#requirement-a-fragment-may-declare-saved-views-for-the-sets-own-accounts
 */
final class ExampleSetFieldRules {
	/**
	 * The first refusal of the checks, run in order until one refuses; '' when none does. A later check may rely on
	 * what an earlier one let through.
	 *
	 * @param array<int, callable(): string> $checks The checks.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-a-fragment-may-declare-saved-views-for-the-sets-own-accounts
	 */
	public function firstRefusal(array $checks): string {
		foreach ($checks as $check) {
			$refusal = $check();
			if ($refusal !== '') {
				return $refusal;
			}
		}

		return '';
	}//end firstRefusal()

	/**
	 * Why a row names a field its kind does not know, or ''.
	 *
	 * @param array<string, mixed> $data  The filled fields.
	 * @param ExampleSetRowShape   $shape The kind, as the message names it (`a deck card`), and the fields it knows.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-a-fragment-may-declare-saved-views-for-the-sets-own-accounts
	 */
	public function unknownRefusal(array $data, ExampleSetRowShape $shape): string {
		$unknown = array_diff(array_keys($data), $shape->fields);
		if ($unknown !== []) {
			return 'field "'.implode('", "', $unknown).'" is not '.$shape->row.' field a set may write';
		}

		return '';
	}//end unknownRefusal()

	/**
	 * Why a row leaves a field it needs empty, or '': the first such field, in the order given.
	 *
	 * @param array<string, mixed> $data  The filled fields.
	 * @param ExampleSetRowShape   $shape The kind, as the message names it (`a deck card`), and what each needed field
	 *                                    holds, by field (`'title' => 'a title'`).
	 *
	 * @return string
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-a-fragment-may-declare-saved-views-for-the-sets-own-accounts
	 */
	public function missingRefusal(array $data, ExampleSetRowShape $shape): string {
		foreach ($shape->required as $field => $what) {
			if (is_string($data[$field] ?? null) === false || trim($data[$field]) === '') {
				return $shape->row.' needs '.$what.' ('.$field.')';
			}
		}

		return '';
	}//end missingRefusal()

	/**
	 * Why one of the people a row names is no account the set creates or adopts, or '': the first such one.
	 *
	 * @param array<int, string> $people The accounts the row names.
	 * @param array<int, string> $users  The accounts the set creates or adopts.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-a-fragment-may-declare-saved-views-for-the-sets-own-accounts
	 */
	public function strangerRefusal(array $people, array $users): string {
		foreach ($people as $uid) {
			if (in_array($uid, $users, true) === false) {
				return '"'.$uid.'" is not an account the set creates or adopts';
			}
		}

		return '';
	}//end strangerRefusal()

	/**
	 * Why one of a row's optional date fields is set to something that is not a date, or ''.
	 *
	 * @param array<string, mixed> $data   The filled fields.
	 * @param array<int, string>   $fields The optional date fields, in the order they are checked.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-a-fragment-may-declare-saved-views-for-the-sets-own-accounts
	 */
	public function datesRefusal(array $data, array $fields): string {
		foreach ($fields as $field) {
			if (isset($data[$field]) === true && $this->isDate(value: (string) $data[$field]) === false) {
				return $field.' "'.(string) $data[$field].'" is not a date';
			}
		}

		return '';
	}//end datesRefusal()

	/**
	 * Whether a text reads as a date.
	 *
	 * @param string $value The text.
	 *
	 * @return bool
	 */
	private function isDate(string $value): bool {
		try {
			new DateTime($value);
		} catch (Throwable) {
			return false;
		}

		return true;
	}//end isDate()
}//end class
