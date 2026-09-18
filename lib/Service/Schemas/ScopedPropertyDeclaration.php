<?php

/**
 * A property scoped to one unit or team.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Schemas
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/fields-a-user-adds-and-choices-a-record-narrows/specs/schema-vocabulaire/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Schemas;

/**
 * `scope` names the team a property belongs to, and COMPILES INTO THE
 * AUTHORIZATION THAT ALREADY EXISTS.
 *
 * 🔴 THIS KEY WAS DELIBERATELY NOT SHIPPED ALONE. An inert `scope` is inert in
 * the dangerous direction: an author writes `scope: team-a`, the key validates,
 * the vocabulary publishes it, and the field stays readable by everybody. They
 * would believe the field is team-scoped PRECISELY BECAUSE the platform
 * accepted the word. A widget declaring roles nothing reads at least looks like
 * nothing happened; this looks like it worked.
 *
 * 🔑 SO IT IS NOT A SECOND EVALUATOR, IT IS A SHORTHAND. `PropertyRbacHandler`
 * already filters unreadable properties out of every read, refuses writes to
 * them, and strips them from exports and the OAS, all driven by the property's
 * `authorization` block. Writing a second mechanism beside it would mean two
 * answers to "may this person see this field", and the two would disagree
 * within a week; the wider one is the one that discloses. `scope: team-a`
 * therefore BECOMES `authorization: {read: ['team-a'], update: ['team-a']}`,
 * and every enforcement path that already exists applies unchanged.
 *
 * Declaring both is refused rather than merged, for the same reason: two
 * sources for one question, where the quiet resolution is whichever the code
 * happens to read first.
 *
 * @spec openspec/changes/fields-a-user-adds-and-choices-a-record-narrows/specs/schema-vocabulaire/spec.md
 */
final class ScopedPropertyDeclaration {

	/**
	 * The vocabulary key.
	 */
	public const ANNOTATION = 'scope';

	/**
	 * The key it compiles into.
	 */
	public const COMPILES_INTO = 'authorization';

	/**
	 * The actions a scope governs.
	 *
	 * READ IS IN THE LIST, AND THAT IS THE POINT. A scope that only governed
	 * writes would leave the value on screen for everyone, which is the inert
	 * failure this whole class exists to prevent.
	 *
	 * `delete` is absent because a property is not deleted independently of its
	 * object, so a rule there would never be consulted and would read as a
	 * protection that is not one.
	 *
	 * @var array<int, string>
	 */
	public const ACTIONS = ['read', 'update'];

	/**
	 * What a scope name may look like.
	 *
	 * A scope resolves to a Nextcloud group id, so it is matched against what a
	 * group id can be rather than against anything looser. A name that cannot
	 * name a group can never match one, so accepting it would publish a scope
	 * that silently denies everybody, which is the opposite failure but just as
	 * quiet.
	 */
	public const NAME_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9 ._-]{0,63}$/';

	/**
	 * Read the scope off a property, refusing anything malformed.
	 *
	 * @param array<string, mixed> $property The compiled property.
	 * @param string               $path     Where the property sits, for the message.
	 *
	 * @return string|null The scope, or null when the property has none.
	 *
	 * @throws ScopedPropertyException When the declaration cannot be honoured.
	 */
	public static function fromProperty(array $property, string $path = ''): ?string {
		if (array_key_exists(self::ANNOTATION, $property) === false) {
			return null;
		}

		$scope = $property[self::ANNOTATION];

		if (is_string($scope) === false || trim($scope) === '') {
			throw new ScopedPropertyException(
				sprintf(
					'\'%s\' at \'%s\' must name one team or unit as a non-empty string.',
					self::ANNOTATION,
					$path
				)
			);
		}

		$scope = trim($scope);

		if (preg_match(self::NAME_PATTERN, $scope) !== 1) {
			throw new ScopedPropertyException(
				sprintf(
					'\'%s\' at \'%s\' is \'%s\', which cannot name a group. '
					. 'A scope that matches no group denies everybody, silently.',
					self::ANNOTATION,
					$path,
					$scope
				)
			);
		}

		if (empty($property[self::COMPILES_INTO] ?? null) === false) {
			throw new ScopedPropertyException(
				sprintf(
					'\'%s\' at \'%s\' declares both \'%s\' and \'%s\'. '
					. 'A scope IS an authorization block, so declaring both leaves two answers to one question '
					. 'and the quiet resolution is whichever the code reads first. Keep one.',
					self::ANNOTATION,
					$path,
					self::ANNOTATION,
					self::COMPILES_INTO
				)
			);
		}

		return $scope;
	}//end fromProperty()

	/**
	 * The authorization block a scope means.
	 *
	 * @param string $scope The scope.
	 *
	 * @return array<string, array<int, string>> The authorization block.
	 */
	public static function authorizationFor(string $scope): array {
		$block = [];
		foreach (self::ACTIONS as $action) {
			$block[$action] = [$scope];
		}

		return $block;
	}//end authorizationFor()

	/**
	 * Refuse a property whose scope cannot be honoured.
	 *
	 * @param array<string, mixed> $property The compiled property.
	 * @param string               $path     Where the property sits.
	 *
	 * @return void
	 *
	 * @throws ScopedPropertyException When the declaration cannot be honoured.
	 */
	public static function assert(array $property, string $path = ''): void {
		self::fromProperty(property: $property, path: $path);
	}//end assert()
}//end class
