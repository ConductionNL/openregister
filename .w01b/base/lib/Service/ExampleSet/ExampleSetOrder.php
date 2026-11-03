<?php

/**
 * OpenRegister Example Set Order
 *
 * Puts the fragments of an example set in the order their `after` lists ask
 * for. OpenRegister's fragment comes first, because it carries the cast every
 * other fragment refers to. An app named in `after` that is not enabled is
 * skipped and named; a cycle is refused with the apps that form it.
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
 * @spec openspec/specs/example-sets/spec.md#requirement-a-set-is-gathered-from-every-enabled-app-in-a-declared-order-req-exs-001
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\ExampleSet;

/**
 * Orders fragments by `after`.
 *
 * @spec openspec/specs/example-sets/spec.md#requirement-a-set-is-gathered-from-every-enabled-app-in-a-declared-order-req-exs-001
 */
class ExampleSetOrder {
	/**
	 * The app whose fragment carries the cast and always goes first.
	 */
	public const CAST_APP = 'openregister';

	/**
	 * Order the apps that ship a fragment.
	 *
	 * @param array<string, array<int, string>> $after   The `after` list of each app that ships a fragment.
	 * @param array<int, string>                $enabled Every enabled app id.
	 * @param array<int, string>                $expected Apps the set names as taking part, enabled or not.
	 *
	 * @return array{order: array<int, string>, skipped: array<int, string>} The app ids in order, and every
	 *                                                                         named app that is not enabled.
	 *
	 * @throws ExampleSetException When `after` forms a cycle.
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-a-set-is-gathered-from-every-enabled-app-in-a-declared-order-req-exs-001
	 */
	public function order(array $after, array $enabled, array $expected=[]): array {
		$skipped = [];
		foreach ($expected as $app) {
			if (in_array($app, $enabled, true) === false) {
				$skipped[] = $app;
			}
		}

		$waitsOn = [];
		foreach ($after as $app => $before) {
			$waitsOn[$app] = [];
			if ($app !== self::CAST_APP && isset($after[self::CAST_APP]) === true) {
				$waitsOn[$app][] = self::CAST_APP;
			}

			foreach ($before as $other) {
				if (isset($after[$other]) === true) {
					$waitsOn[$app][] = $other;
					continue;
				}

				if (in_array($other, $enabled, true) === false) {
					$skipped[] = $other;
				}
			}
		}

		$skipped = array_values(array_unique($skipped));
		sort($skipped);

		return ['order' => $this->sorted(waitsOn: $waitsOn), 'skipped' => $skipped];
	}//end order()

	/**
	 * A topological order, ties broken by app id, OpenRegister first.
	 *
	 * @param array<string, array<int, string>> $waitsOn The apps each app waits on.
	 *
	 * @return array<int, string>
	 *
	 * @throws ExampleSetException When some apps wait on each other.
	 */
	private function sorted(array $waitsOn): array {
		$order = [];
		while ($waitsOn !== []) {
			$ready = [];
			foreach ($waitsOn as $app => $before) {
				if (array_diff($before, $order) === []) {
					$ready[] = $app;
				}
			}

			if ($ready === []) {
				$cycle = [];
				foreach (array_keys($waitsOn) as $app) {
					if ($this->reaches(waitsOn: $waitsOn, from: $app, target: $app, seen: []) === true) {
						$cycle[] = $app;
					}
				}

				sort($cycle);
				throw new ExampleSetException(
					'The fragments of this set wait on each other in a cycle through '.implode(', ', $cycle)
					.'. Fix the "after" lists of these apps; nothing was written.'
				);
			}

			sort($ready);
			$next = $ready[0];
			if (in_array(self::CAST_APP, $ready, true) === true) {
				$next = self::CAST_APP;
			}

			$order[] = $next;
			unset($waitsOn[$next]);
		}

		return $order;
	}//end sorted()

	/**
	 * Whether following `after` from one app leads to the target.
	 *
	 * @param array<string, array<int, string>> $waitsOn The apps each app waits on.
	 * @param string                            $from    Where to start.
	 * @param string                            $target  The app looked for.
	 * @param array<string, bool>               $seen    Apps already followed.
	 *
	 * @return bool
	 */
	private function reaches(array $waitsOn, string $from, string $target, array $seen): bool {
		foreach (($waitsOn[$from] ?? []) as $next) {
			if ($next === $target) {
				return true;
			}

			if (isset($seen[$next]) === false && $this->reaches(waitsOn: $waitsOn, from: $next, target: $target, seen: ($seen + [$next => true])) === true) {
				return true;
			}
		}

		return false;
	}//end reaches()
}//end class
