<?php

/**
 * OpenRegister DatabaseConstraintMessages
 *
 * The user sentence for each recognised database error shape.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Exception
 * @package  OCA\OpenRegister\Exception
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Exception;

/**
 * Maps a raw database error message to the sentence a user reads.
 *
 * Rules are tried in order and the first whose needles occur wins, so the
 * order of each table is part of the mapping. `{type}` stands for the entity
 * type being saved.
 */
final class DatabaseConstraintMessages {

	/**
	 * Rules for a duplicate-entry error (MySQL/MariaDB "Duplicate entry ... for key ...").
	 *
	 * BOTH index names are matched, old and new: Version1Date20260723000000
	 * renamed `schemas_organisation_slug_unique` to `schemas_org_app_slug_unique`
	 * (and the register equivalent) when it widened the key to (organisation,
	 * application, slug). The old names stay so an instance that has not yet
	 * migrated keeps its message too.
	 *
	 * @var array{rules: list<array{0: list<string>, 1: string}>, fallback: string}
	 */
	private const DUPLICATE = [
		'rules' => [
			[
				['schemas_organisation_slug_unique', 'schemas_org_app_slug_unique'],
				'A schema with this slug already exists for this application in your organization. Please choose a different slug or title.',
			],
			[
				['registers_organisation_slug_unique', 'registers_org_app_slug_unique'],
				'A register with this slug already exists for this application in your organization. Please choose a different slug or title.',
			],
			[['unique'], 'This {type} already exists. Please check your input and try again.'],
		],
		// The sentence of a duplicate entry no rule names.
		'fallback' => 'A {type} with these details already exists. Please modify your input and try again.',
	];

	/**
	 * Rules for every other error: foreign key, NOT NULL, CHECK, length, SQLSTATE.
	 *
	 * @var array{rules: list<array{0: list<string>, 1: string}>, fallback: string}
	 */
	private const GENERAL = [
		'rules' => [
			[
				['foreign key constraint', 'FOREIGN KEY'],
				"This {type} cannot be saved because it references data that doesn't exist. Please check your configuration and try again.",
			],
			[
				['cannot be null', 'NOT NULL'],
				'Required information is missing. Please fill in all required fields and try again.',
			],
			[
				['check constraint', 'CHECK'],
				"The provided data doesn't meet the required format or constraints. Please check your input and try again.",
			],
			[
				['Data too long', 'too long'],
				'Some of the provided information is too long. Please shorten your input and try again.',
			],
			[['SQLSTATE'], 'There was a problem saving your {type}. Please check your input and try again.'],
		],
		// The sentence of an error no rule recognises.
		'fallback' => 'There was a database error while saving your {type}. Please try again or contact support if the problem persists.',
	];

	/**
	 * The user sentence for one database error message.
	 *
	 * @param string $dbMessage The database error message.
	 * @param string $entityType The type of entity being saved.
	 *
	 * @return string The sentence.
	 */
	public static function forMessage(string $dbMessage, string $entityType): string {
		$template = self::template(dbMessage: $dbMessage, table: self::GENERAL);
		if (str_contains($dbMessage, 'Duplicate entry') === true && str_contains($dbMessage, 'for key') === true) {
			$template = self::template(dbMessage: $dbMessage, table: self::DUPLICATE);
		}

		return str_replace('{type}', $entityType, $template);
	}//end forMessage()

	/**
	 * The sentence of the first rule with a needle in the message.
	 *
	 * @param string $dbMessage The database error message.
	 * @param array{rules: list<array{0: list<string>, 1: string}>, fallback: string} $table The rules, in order, and the fallback.
	 *
	 * @return string The sentence template.
	 */
	private static function template(string $dbMessage, array $table): string {
		foreach ($table['rules'] as $rule) {
			foreach ($rule[0] as $needle) {
				if (str_contains($dbMessage, $needle) === true) {
					return $rule[1];
				}
			}
		}

		return $table['fallback'];
	}//end template()
}//end class
