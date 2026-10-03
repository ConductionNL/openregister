<?php

/**
 * Who may add a scoped property, how many, and what happens to it after.
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
 * @spec openspec/changes/fields-a-user-adds-and-choices-a-record-narrows/specs/runtime-schema-api/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Schemas;

use DateTimeImmutable;
use OCA\OpenRegister\Db\Schema;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IUserSession;

/**
 * The three things that keep a scoped property from becoming a free-for-all.
 *
 * 🔑 ADDING ONE IS A DECLARED ACTION GATED BY THE SCOPE, NOT BY THE ADMIN FLAG.
 * Gating on admin would mean either every team waits on an administrator, which
 * is the friction the feature exists to remove, or administrators are handed out
 * until the flag means nothing. The group that OWNS the scope is the group that
 * may add to it, which is the same answer the read rule gives, so a person
 * cannot create a field they would not then be allowed to see.
 *
 * @spec openspec/changes/fields-a-user-adds-and-choices-a-record-narrows/specs/runtime-schema-api/spec.md
 */
class ScopedPropertyGovernance {

	/**
	 * The app config key holding the ceiling.
	 */
	public const CEILING_KEY = 'scoped_properties_per_scope';

	/**
	 * How many scoped properties one scope may hold before a new one is refused.
	 *
	 * A ceiling exists at all because the failure it prevents is silent: a
	 * register fills with fields nobody remembers asking for, every form gets
	 * longer, and no single addition is the one that did it.
	 */
	public const DEFAULT_CEILING = 25;

	/**
	 * The app config key holding the unused period, in days.
	 */
	public const UNUSED_DAYS_KEY = 'scoped_property_unused_days';

	/**
	 * How long a scoped property may hold no value before it reads as abandoned.
	 */
	public const DEFAULT_UNUSED_DAYS = 90;

	/**
	 * The collaborators.
	 *
	 * @param IUserSession  $userSession  The caller.
	 * @param IGroupManager $groupManager Group membership.
	 * @param IAppConfig    $appConfig    The administered ceiling and period.
	 */
	public function __construct(
		private readonly IUserSession $userSession,
		private readonly IGroupManager $groupManager,
		private readonly IAppConfig $appConfig,
	) {
	}//end __construct()

	/**
	 * Whether the caller may add a property at this scope.
	 *
	 * 🔴 AN ADMINISTRATOR IS ADMITTED, AND THAT IS NOT THE SAME AS GATING ON
	 * THE ADMIN FLAG. The flag being sufficient is fine; the flag being
	 * REQUIRED is what this refuses, because it would send every team to an
	 * administrator for a field only they will use.
	 *
	 * @param string $scope The scope being added to.
	 *
	 * @return bool Whether the caller may add.
	 *
	 * @spec openspec/changes/fields-a-user-adds-and-choices-a-record-narrows/specs/runtime-schema-api/spec.md
	 */
	public function mayAddAtScope(string $scope): bool {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return false;
		}

		$groups = $this->groupManager->getUserGroupIds($user);

		if (in_array('admin', $groups, true) === true) {
			return true;
		}

		return in_array($scope, $groups, true);
	}//end mayAddAtScope()

	/**
	 * Refuse a caller who is outside the scope they are adding to.
	 *
	 * @param string $scope The scope.
	 * @param string $path  Where the property sits, for the message.
	 *
	 * @return void
	 *
	 * @throws ScopedPropertyException When the caller is outside the scope.
	 *
	 * @spec openspec/changes/fields-a-user-adds-and-choices-a-record-narrows/specs/runtime-schema-api/spec.md
	 */
	public function assertMayAddAtScope(string $scope, string $path = ''): void {
		if ($this->mayAddAtScope(scope: $scope) === true) {
			return;
		}

		throw new ScopedPropertyException(
			sprintf(
				'Adding \'%s\' at scope \'%s\' is for members of that scope. '
				. 'A field only one team will use is theirs to add, and theirs alone to see.',
				$path,
				$scope
			)
		);
	}//end assertMayAddAtScope()

	/**
	 * The administered ceiling on scoped properties per scope.
	 *
	 * @return int The ceiling.
	 *
	 * @spec openspec/changes/fields-a-user-adds-and-choices-a-record-narrows/specs/runtime-schema-api/spec.md
	 */
	public function ceiling(): int {
		$configured = (int)$this->appConfig->getValueInt('openregister', self::CEILING_KEY, self::DEFAULT_CEILING);

		// A ceiling of zero or less would refuse every scoped property while
		// reading like "no limit", which is the most confusing possible value.
		return max(1, $configured);
	}//end ceiling()

	/**
	 * How many scoped properties one scope already holds.
	 *
	 * @param Schema $schema The schema.
	 * @param string $scope  The scope.
	 * @param string $except A property name to ignore, so an EDIT of an existing property is not counted twice.
	 *
	 * @return int The count.
	 *
	 * @spec openspec/changes/fields-a-user-adds-and-choices-a-record-narrows/specs/runtime-schema-api/spec.md
	 */
	public function countAtScope(Schema $schema, string $scope, string $except = ''): int {
		$count = 0;
		foreach (($schema->getProperties() ?? []) as $name => $property) {
			if ((string)$name === $except) {
				continue;
			}

			if (is_array($property) === false) {
				continue;
			}

			$declared = ($property[ScopedPropertyDeclaration::ANNOTATION] ?? null);
			if (is_string($declared) === true && trim($declared) === $scope) {
				$count++;
			}
		}

		return $count;
	}//end countAtScope()

	/**
	 * Refuse a scoped property that would sit above the ceiling.
	 *
	 * 🔑 THE REFUSAL NAMES THE CEILING. "Refused" alone sends the author to an
	 * administrator with nothing to say; the number tells them whether to ask
	 * for a higher one or to retire a field they no longer use, which is the
	 * decision the ceiling exists to force.
	 *
	 * @param Schema $schema   The schema being saved.
	 * @param string $scope    The scope.
	 * @param string $property The property being added.
	 *
	 * @return void
	 *
	 * @throws ScopedPropertyException When the scope is full.
	 *
	 * @spec openspec/changes/fields-a-user-adds-and-choices-a-record-narrows/specs/runtime-schema-api/spec.md
	 */
	public function assertBelowCeiling(Schema $schema, string $scope, string $property): void {
		$ceiling = $this->ceiling();
		$already = $this->countAtScope(schema: $schema, scope: $scope, except: $property);

		if ($already < $ceiling) {
			return;
		}

		throw new ScopedPropertyException(
			sprintf(
				'Scope \'%s\' already holds %d scoped properties, which is its ceiling of %d, so \'%s\' is refused. '
				. 'Raise the ceiling or retire a field the scope no longer uses.',
				$scope,
				$already,
				$ceiling,
				$property
			)
		);
	}//end assertBelowCeiling()

	/**
	 * How long a scoped property may hold no value before it reads as abandoned.
	 *
	 * @return int The period, in days.
	 *
	 * @spec openspec/changes/fields-a-user-adds-and-choices-a-record-narrows/specs/runtime-schema-api/spec.md
	 */
	public function unusedAfterDays(): int {
		return max(1, (int)$this->appConfig->getValueInt(
			'openregister',
			self::UNUSED_DAYS_KEY,
			self::DEFAULT_UNUSED_DAYS
		));
	}//end unusedAfterDays()

	/**
	 * The scoped properties of a schema that hold no value.
	 *
	 * 🔴 "NO VALUE WRITTEN" IS NOT THE SAME AS "NO OBJECTS", AND CONFLATING
	 * THEM WOULD REPORT EVERY FIELD OF AN EMPTY REGISTER AS ABANDONED. The
	 * caller passes the counts it measured, because counting values is a query
	 * over the objects table and this class does not own one; a class that both
	 * decides the rule and fetches the evidence tends to end up with two
	 * versions of the rule.
	 *
	 * A property with NO COUNT AT ALL is reported as unknown rather than
	 * unused. Absent evidence is not evidence of absence, and retiring a field
	 * on it would delete data somebody is relying on.
	 *
	 * @param Schema                $schema   The schema.
	 * @param array<string, int>    $counts   How many values each property holds.
	 * @param DateTimeImmutable     $asOf     When the report is read.
	 *
	 * @return array<int, array{property: string, scope: string, values: int|null, state: string}> The report.
	 *
	 * @spec openspec/changes/fields-a-user-adds-and-choices-a-record-narrows/specs/runtime-schema-api/spec.md
	 */
	public function unusedReport(Schema $schema, array $counts, DateTimeImmutable $asOf): array {
		$report = [];

		foreach (($schema->getProperties() ?? []) as $name => $property) {
			if (is_array($property) === false) {
				continue;
			}

			$scope = ($property[ScopedPropertyDeclaration::ANNOTATION] ?? null);
			if (is_string($scope) === false || trim($scope) === '') {
				continue;
			}

			$key   = (string)$name;
			$state = 'in use';
			$values = null;

			if (array_key_exists($key, $counts) === false) {
				$state = 'unknown';
			}

			if (array_key_exists($key, $counts) === true) {
				$values = (int)$counts[$key];
				if ($values === 0) {
					$state = 'unused';
				}
			}

			$report[] = [
				'property' => $key,
				'scope'    => trim($scope),
				'values'   => $values,
				'state'    => $state,
				'unusedAfterDays' => $this->unusedAfterDays(),
				'asOf'     => $asOf->format('c'),
			];
		}

		return $report;
	}//end unusedReport()

	/**
	 * Promote a scoped property to an ordinary schema property.
	 *
	 * 🔴 PROMOTION DROPS THE SCOPE AND TOUCHES NOTHING ELSE, WHICH IS WHAT
	 * KEEPS THE VALUES. The values live on the objects, keyed by the property
	 * NAME; they are not copied here and must not be. Renaming the property, or
	 * rebuilding it from a template, would leave forty objects holding a key
	 * nothing reads any more, and the loss would be silent because the objects
	 * would still save.
	 *
	 * So the only change is that the property stops being governed. It returns
	 * the new properties rather than mutating the schema, so the caller decides
	 * when the change is saved and can put the audit entry on the same act.
	 *
	 * @param Schema $schema   The schema.
	 * @param string $property The property to promote.
	 *
	 * @return array<string, mixed> The schema's properties, with that one promoted.
	 *
	 * @throws ScopedPropertyException When the property is not scoped.
	 *
	 * @spec openspec/changes/fields-a-user-adds-and-choices-a-record-narrows/specs/runtime-schema-api/spec.md
	 */
	public function promote(Schema $schema, string $property): array {
		$properties = ($schema->getProperties() ?? []);
		$config     = ($properties[$property] ?? null);

		if (is_array($config) === false) {
			throw new ScopedPropertyException(
				sprintf('There is no property \'%s\' to promote.', $property)
			);
		}

		$scope = ($config[ScopedPropertyDeclaration::ANNOTATION] ?? null);
		if (is_string($scope) === false || trim($scope) === '') {
			throw new ScopedPropertyException(
				sprintf(
					'\'%s\' is not a scoped property, so there is nothing to promote it from. '
					. 'Promoting it anyway would report an act that did not happen.',
					$property
				)
			);
		}

		unset($config[ScopedPropertyDeclaration::ANNOTATION]);
		$properties[$property] = $config;

		return $properties;
	}//end promote()

	/**
	 * The audit entry a promotion leaves.
	 *
	 * Separate from {@see promote()} so the caller cannot perform the act
	 * without having the record in hand, and so a test can assert the record
	 * without saving a schema.
	 *
	 * @param Schema            $schema   The schema.
	 * @param string            $property The promoted property.
	 * @param string            $scope    The scope it left.
	 * @param DateTimeImmutable $stampedAt       When.
	 *
	 * @return array<string, mixed> The entry.
	 *
	 * @spec openspec/changes/fields-a-user-adds-and-choices-a-record-narrows/specs/runtime-schema-api/spec.md
	 */
	public function promotionRecord(
		Schema $schema,
		string $property,
		string $scope,
		DateTimeImmutable $stampedAt,
	): array {
		return [
			'action'   => 'scoped_property_promoted',
			'schema'   => $schema->getId(),
			'property' => $property,
			'fromScope' => $scope,
			// The actor is named rather than left to the log's own context,
			// because "who promoted this" is the question anyone reading the
			// trail later is actually asking.
			'actor'    => $this->userSession->getUser()?->getUID(),
			'at'       => $stampedAt->format('c'),
		];
	}//end promotionRecord()
}//end class
