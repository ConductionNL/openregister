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
	 * @param FragmentOrdering $apps Each fragment's `after`, the enabled apps, and the apps the set names.
	 *
	 * @return array{order: array<int, string>, skipped: array<int, string>} The app ids in order, and every
	 *                                                                         named app that is not enabled.
	 *
	 * @throws ExampleSetException When `after` forms a cycle.
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-a-set-is-gathered-from-every-enabled-app-in-a-declared-order-req-exs-001
	 */
	public function order(FragmentOrdering $apps): array {
		[$after, $enabled] = [$apps->after, $apps->enabled];
		$skipped = array_filter($apps->expected, static fn (mixed $app): bool => in_array($app, $enabled, true) === false);
		$waitsOn = [];
		foreach ($after as $app => $before) {
			$waitsOn[$app] = $this->waitsOn(app: $app, after: $after);
			$skipped       = array_merge($skipped, $this->notTakingPart(before: $before, apps: ['after' => $after, 'enabled' => $enabled]));
		}

		$skipped = array_values(array_unique($skipped));
		sort($skipped);

		return ['order' => $this->sorted(waitsOn: $waitsOn), 'skipped' => $skipped];
	}//end order()

	/**
	 * The apps one app waits on: OpenRegister when it ships a fragment, then every app in its `after` that ships one.
	 *
	 * @param int|string                        $app   The app.
	 * @param array<string, array<int, string>> $after The `after` list of each app that ships a fragment.
	 *
	 * @return array<int, string>
	 */
	private function waitsOn(int|string $app, array $after): array {
		$waits = [];
		if ($app !== self::CAST_APP && isset($after[self::CAST_APP]) === true) {
			$waits[] = self::CAST_APP;
		}

		return array_merge($waits, array_values(array_filter($after[$app], static fn (mixed $other): bool => isset($after[$other]) === true)));
	}//end waitsOn()

	/**
	 * The apps of an `after` list that ship no fragment and are not enabled.
	 *
	 * @param array<int, string>                                                    $before The `after` list.
	 * @param array{after: array<string, array<int, string>>, enabled: array<int, string>} $apps   The `after` lists, and every
	 *                                                                                      enabled app id.
	 *
	 * @return array<int, string>
	 */
	private function notTakingPart(array $before, array $apps): array {
		return array_values(
			array_filter(
				$before,
				static fn (mixed $other): bool => isset($apps['after'][$other]) === false && in_array($other, $apps['enabled'], true) === false
			)
		);
	}//end notTakingPart()

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
			$next    = $this->next(waitsOn: $waitsOn, order: $order);
			$order[] = $next;
			unset($waitsOn[$next]);
		}

		return $order;
	}//end sorted()

	/**
	 * The app that goes next: OpenRegister when it is ready, else the ready app with the lowest id.
	 *
	 * @param array<int|string, array<int, string>> $waitsOn The apps each app not ordered yet waits on.
	 * @param array<int, string>                    $order   The apps ordered so far.
	 *
	 * @return int|string
	 *
	 * @throws ExampleSetException When no app is ready: they wait on each other.
	 */
	private function next(array $waitsOn, array $order): int|string {
		$ready = array_keys(array_filter($waitsOn, static fn (array $before): bool => array_diff($before, $order) === []));
		if ($ready === []) {
			throw $this->cycle(waitsOn: $waitsOn);
		}

		sort($ready);
		if (in_array(self::CAST_APP, $ready, true) === true) {
			return self::CAST_APP;
		}

		return $ready[0];
	}//end next()

	/**
	 * The refusal naming every app that waits on itself through others.
	 *
	 * @param array<string, array<int, string>> $waitsOn The apps each app not ordered yet waits on.
	 *
	 * @return ExampleSetException
	 */
	private function cycle(array $waitsOn): ExampleSetException {
		$cycle = array_values(
			array_filter(
				array_keys($waitsOn),
				fn (int|string $app): bool => $this->reaches(waitsOn: $waitsOn, path: new ExampleSetOrderPath(from: $app, target: $app))
			)
		);
		sort($cycle);

		return new ExampleSetException(
			'The fragments of this set wait on each other in a cycle through '.implode(', ', $cycle)
			.'. Fix the "after" lists of these apps; nothing was written.'
		);
	}//end cycle()

	/**
	 * Whether following `after` from where the path is leads to its target.
	 *
	 * @param array<string, array<int, string>> $waitsOn The apps each app waits on.
	 * @param ExampleSetOrderPath               $path    Where the search is, what it looks for and what it followed.
	 *
	 * @return bool
	 */
	private function reaches(array $waitsOn, ExampleSetOrderPath $path): bool {
		foreach (($waitsOn[$path->from] ?? []) as $next) {
			if ($next === $path->target) {
				return true;
			}

			if (isset($path->seen[$next]) === false && $this->reaches(waitsOn: $waitsOn, path: $path->step(next: $next)) === true) {
				return true;
			}
		}

		return false;
	}//end reaches()
}//end class
