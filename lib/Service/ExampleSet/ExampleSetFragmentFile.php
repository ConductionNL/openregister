<?php

/**
 * OpenRegister Example Set Fragment File
 *
 * Reads one app's fragment of an example set from its example-sets folder,
 * with the parts in its `<set>.d/` folder appended in file-name order.
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
 * The fragment files of one app.
 *
 * @spec openspec/specs/example-sets/spec.md#requirement-a-set-is-gathered-from-every-enabled-app-in-a-declared-order-req-exs-001
 */
class ExampleSetFragmentFile {
	/**
	 * Constructor.
	 *
	 * @param string $app    The app id.
	 * @param string $folder The app's example-sets folder.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly string $app,
		private readonly string $folder,
	) {
	}//end __construct()

	/**
	 * The app's fragment of a set with the parts in its `<set>.d/` folder appended, in file-name order, or null when
	 * the app ships none.
	 *
	 * A part adds lists to its app's fragment (`objects`, `changes`, `appConfig` and the
	 * other parts a fragment declares as lists), so two seed areas can live in two files.
	 * A part may not set anything else: the fragment's own settings stay in its main file.
	 * Relative file paths in a part resolve from the main file's folder.
	 *
	 * @param string $set The set id.
	 *
	 * @return array<string, mixed>|null The fragment with every part's lists appended.
	 *
	 * @throws ExampleSetException When the fragment or a part does not parse, or a part sets a value that is not a list.
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-a-set-is-gathered-from-every-enabled-app-in-a-declared-order-req-exs-001
	 */
	public function fragment(string $set): ?array {
		if (is_file($this->folder.'/'.$set.'.json') === false) {
			return null;
		}

		$fragment = $this->read(file: $this->folder.'/'.$set.'.json');
		foreach ($this->partFiles(folder: $this->folder.'/'.$set.'.d') as $file) {
			$fragment = $this->withPart(fragment: $fragment, part: $this->read(file: $file));
		}

		return $fragment;
	}//end fragment()

	/**
	 * The part files in a parts folder, in file-name order.
	 *
	 * @param string $folder The parts folder.
	 *
	 * @return array<int, string>
	 */
	private function partFiles(string $folder): array {
		$files = glob($folder.'/*.json');
		if ($files === false) {
			return [];
		}

		sort($files);

		return $files;
	}//end partFiles()

	/**
	 * A fragment with one part's lists appended.
	 *
	 * @param array<string, mixed> $fragment The fragment so far.
	 * @param array<string, mixed> $part     The part, as read.
	 *
	 * @return array<string, mixed>
	 *
	 * @throws ExampleSetException When the part sets a value that is not a list.
	 */
	private function withPart(array $fragment, array $part): array {
		$named = $this->app.'/'.ExampleSetCatalogue::FOLDER.'/'.basename($part['@dir']).'/'.basename($part['@file']);
		$lists = array_filter($part, static fn (int|string $key): bool => self::isPartNote(key: (string) $key) === false, ARRAY_FILTER_USE_KEY);
		foreach ($lists as $key => $value) {
			if (is_array($value) === false || array_is_list($value) === false) {
				throw new ExampleSetException($named.': a part may only add lists; "'.$key.'" belongs in the main file.');
			}

			$fragment[$key] = array_merge((array) ($fragment[$key] ?? []), $value);
		}

		return $fragment;
	}//end withPart()

	/**
	 * Whether a part's key is a note rather than content: `set`, `$comment`, or what read() adds (`@file`, `@dir`).
	 *
	 * @param string $key The key.
	 *
	 * @return bool True for a note.
	 */
	private static function isPartNote(string $key): bool {
		return $key === 'set' || str_starts_with($key, '@') === true || str_starts_with($key, '$') === true;
	}//end isPartNote()

	/**
	 * Read and parse one fragment file.
	 *
	 * @param string $file The file's path.
	 *
	 * @return array<string, mixed> The fragment, with `@file` set to a path that names the app.
	 *
	 * @throws ExampleSetException When the file does not parse, or carries a cast outside OpenRegister.
	 */
	private function read(string $file): array {
		$named   = $this->app.'/'.ExampleSetCatalogue::FOLDER.'/'.basename($file);
		$decoded = json_decode((string) file_get_contents($file), true);
		if (is_array($decoded) === false) {
			throw new ExampleSetException($named.' is not valid JSON: '.json_last_error_msg());
		}

		$this->refuseCast(decoded: $decoded, named: $named);
		$decoded['@file'] = $named;
		$decoded['@dir']  = dirname($file);

		return $decoded;
	}//end read()

	/**
	 * Refuse a cast in a file outside OpenRegister.
	 *
	 * @param array<string, mixed> $decoded The parsed file.
	 * @param string               $named   The file, for the message.
	 *
	 * @return void
	 *
	 * @throws ExampleSetException When the file carries a cast and its app is not OpenRegister.
	 */
	private function refuseCast(array $decoded, string $named): void {
		if ($this->app !== ExampleSetOrder::CAST_APP && array_key_exists('cast', $decoded) === true) {
			throw new ExampleSetException(
				$named.' carries a cast. The cast of a set lives once, in OpenRegister; refer to its members with {{cast:key.field}}.'
			);
		}
	}//end refuseCast()
}//end class
