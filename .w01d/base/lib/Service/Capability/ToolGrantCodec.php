<?php

/**
 * Tool Grant Codec
 *
 * RELOCATED FROM HERMIQ (ADR-099 §5), as a relocation with its tests and NOT a
 * rewrite. The capability axis — may agent X use tool T — is not agent-specific
 * and resolves against `ToolRegistryFacade`, which already lives here. Its codec
 * carries a measured scar (35 of 87 tools parsed wrong) and ADR-095's
 * persistence constraint, and a rewrite-while-moving would reopen both, so
 * nothing below this line changed except the namespace.
 *
 * 🔴 NAMED `Capability`, NEVER `Grant`. `Service\Delegation` answers a different
 * question — may principal P act as user B — and once both live in one codebase
 * the conflation risk rises rather than falls. A user approving a tool must not
 * be able to widen whose identity the agent wears.
 *
 * Reads and writes the LEGACY grant-string grammar — the one thing
 * {@see ToolGrantSet} needs in order to stop needing it.
 *
 * A grant used to be a string, and three spellings of that string are in
 * circulation: `pipelinq.lead.search` (app.subject.action),
 * `hermiq.listFiles` (app plus a camelCase name) and, from hand-written
 * providers, ids with no app prefix at all. On top of that, an argument-scoped
 * grant carries its constraints inside the same string as
 * `?key=value&other=in:a,b`, because the frozen `string[]` shape left nowhere
 * else to put them.
 *
 * 🔑 This class is where that grammar is understood, and it is the ONLY place.
 * Every consumer used to carry its own version of these rules, and they
 * disagreed — measurably, on 35 of 87 tools. Converting once, at the boundary,
 * and storing the result is what makes the disagreement impossible rather than
 * merely unlikely.
 *
 * ⚠️ Kept ONE-WAY on purpose. `coordinatesFor()` reads the grammar and
 * `grantStringFor()` writes it, but nothing here rebuilds a tool id from
 * coordinates: `hermiq.listFiles` sits at (hermiq, file, list) and rebuilding
 * from those gives `hermiq.file.list`, which is not a tool. The id is carried
 * through verbatim, always.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Capability
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/structured-tool-grants/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Capability;

/**
 * The legacy grant-string grammar, read and written in one place.
 *
 * @spec openspec/specs/structured-tool-grants/spec.md
 */
final class ToolGrantCodec {

	/**
	 * One stored entry: the bare id, or `{id, args}` when it is constrained.
	 *
	 * The bare-id form is not an optimisation, it is what makes the stored JSON
	 * readable — the overwhelming majority of grants carry no constraints, and
	 * spelling every one of them `{"id": "..."}` would bury the few that do.
	 *
	 * @param string $id   The tool id.
	 * @param array<string, mixed> $args The argument constraints, if any.
	 *
	 * @return string|array<string, mixed> The entry.
	 *
	 * @spec openspec/specs/structured-tool-grants/spec.md#scenario-two-constrained-grants-for-one-tool-both-survive
	 */
	public static function entryFor(string $id, array $args): string|array {
		if ($args === []) {
			return $id;
		}

		return ['id' => $id, 'args' => $args];
	}//end entryFor()

	/**
	 * The grant string for one stored entry.
	 *
	 * @param string|array<string, mixed> $entry The stored entry.
	 *
	 * @return string The grant string.
	 *
	 * @spec openspec/specs/structured-tool-grants/spec.md#requirement-the-legacy-grant-grammar-lives-in-exactly-one-place
	 */
	public static function grantStringFor(string|array $entry): string {
		if (is_string($entry) === true) {
			return $entry;
		}

		$id = (string)($entry['id'] ?? '');
		$args = ($entry['args'] ?? []);
		if (is_array($args) === false || $args === []) {
			return $id;
		}

		$pairs = [];
		foreach ($args as $key => $value) {
			// The array case is handled FIRST. The unconditional `(string)$value`
			// that used to open this loop ran for arrays too, raising an
			// "Array to string conversion" warning on a value the next line then
			// overwrote — the output was right, the warning was noise.
			if (is_array($value) === true) {
				// A closed value set, spelled the way the resolver's constraint
				// grammar reads it back.
				$pairs[] = $key . '=in:' . implode(',', $value);
				continue;
			}

			$pairs[] = $key . '=' . (string)$value;
		}

		return $id . '?' . implode('&', $pairs);
	}//end grantStringFor()

	/**
	 * Where one legacy grant string belongs in the structure.
	 *
	 * ⚠️ This is the ONLY place the old string grammar is taken apart, and it
	 * runs once per agent per migration rather than on every read by every
	 * consumer. That is the point of the change: the guessing is not made
	 * cleverer, it is made to happen once and then be written down.
	 *
	 * The id is carried through verbatim, so a tool whose coordinates do not
	 * rebuild its id — `hermiq.listFiles` at (hermiq, file, list) — still
	 * dispatches to the right thing.
	 *
	 * @param string $grant The legacy grant string.
	 *
	 * @return array{0: string, 1: string, 2: string, 3: string|array<string, mixed>}
	 *
	 * @spec openspec/specs/structured-tool-grants/spec.md#requirement-the-legacy-grant-grammar-lives-in-exactly-one-place
	 */
	public static function coordinatesFor(string $grant): array {
		$id = $grant;
		$args = [];

		$queryAt = strpos($grant, '?');
		if ($queryAt !== false) {
			$id = substr($grant, 0, $queryAt);
			$args = self::parseConstraints(query: substr($grant, ($queryAt + 1)));
		}

		$parts = explode('.', $id);
		$app = ($parts[0] ?? '');

		// `{app}.{subject}.{action}` — the derived shape, already structured.
		if (count($parts) >= 3) {
			$action = (string)array_pop($parts);
			$subject = implode('.', array_slice($parts, 1));

			return [$app, $subject, $action, self::entryFor(id: $id, args: $args)];
		}

		// `{app}.{name}` — hand-written. The name is BOTH the subject and the
		// action: it is a capability, not a verb applied to a noun, and pretending
		// otherwise is what produced subjects like "fetch" out of `webFetch`.
		$name = ($parts[1] ?? $app);

		return [$app, $name, $name, self::entryFor(id: $id, args: $args)];
	}//end coordinatesFor()

	/**
	 * Parse the legacy `?key=value&other=in:a,b` constraint syntax.
	 *
	 * @param string $query The part after the `?`.
	 *
	 * @return array<string, mixed> The constraints.
	 *
	 * @spec openspec/specs/structured-tool-grants/spec.md#requirement-the-legacy-grant-grammar-lives-in-exactly-one-place
	 */
	public static function parseConstraints(string $query): array {
		$args = [];
		foreach (explode('&', $query) as $pair) {
			if ($pair === '' || str_contains($pair, '=') === false) {
				continue;
			}

			[$key, $value] = explode('=', $pair, 2);
			if (str_starts_with($value, 'in:') === true) {
				$args[$key] = explode(',', substr($value, 3));
				continue;
			}

			$args[$key] = $value;
		}

		return $args;
	}//end parseConstraints()

	/**
	 * One entry, or null when it is not usable.
	 *
	 * ⚠️ An entry with no `id` is DROPPED rather than defaulted to its
	 * coordinates. A grant that cannot name the tool it grants is not a narrower
	 * grant, it is an unusable one — and inventing `{app}.{subject}.{action}` for
	 * it would resolve to a tool id that may not exist, or worse, to one that
	 * does and was never granted.
	 *
	 * @param mixed $entry The stored entry.
	 *
	 * @return string|array<string, mixed>|null The clean entry, or null.
	 *
	 * @spec openspec/specs/structured-tool-grants/spec.md#requirement-tool-grants-are-a-structure-in-the-domain-and-a-list-in-storage
	 */
	public static function sanitiseEntry(mixed $entry): string|array|null {
		if (is_string($entry) === true && $entry !== '') {
			return $entry;
		}

		if (is_array($entry) === false) {
			return null;
		}

		$id = ($entry['id'] ?? '');
		if (is_string($id) === false || $id === '') {
			return null;
		}

		$args = ($entry['args'] ?? []);
		if (is_array($args) === false || $args === []) {
			return $id;
		}

		return ['id' => $id, 'args' => $args];
	}//end sanitiseEntry()
}//end class
