<?php

/**
 * The flows, notification rules and saved views that name what a rename changes.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Schema
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Schema;

use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Kernel\Contracts\FlowNamingDocumentsInterface;
use OCA\OpenRegister\Kernel\Contracts\ViewNamingDocumentsInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Lists, as preview warnings, what still names a renamed property or a renamed schema slug.
 *
 * A rename moves the data and rewrites schema `$ref`s; it deliberately does
 * NOT rewrite flows, notification rules (`x-openregister-notifications` on a
 * schema) or saved views. Those name a property or slug as text, and
 * rewriting text is a guess. The preview names them instead, so the
 * administrator can fix them before running (design D-5).
 *
 * A document NAMES a word when a string in it is the word, or carries it as a
 * whole token (`object.omschrijving`, `{{ omschrijving }}`), or when an array
 * key is the word. `omschrijvingen` does not name `omschrijving`.
 *
 * Every source is read on its own: one that cannot be read leaves out its
 * warnings and is logged, it never fails the preview.
 *
 * @spec openspec/changes/modelling-rename-without-loss/tasks.md#1.2
 */
class RenameReferenceWarnings {

	/**
	 * Constructor.
	 *
	 * @param FlowNamingDocumentsInterface $flows   The flows, as the Processes area hands them out.
	 * @param ViewNamingDocumentsInterface $views   The saved views, as the Search area hands them out.
	 * @param SchemaMapperInterface        $schemas The schemas, whose notification rules are read.
	 * @param LoggerInterface              $logger  Where an unreadable source is logged.
	 */
	public function __construct(
		private readonly FlowNamingDocumentsInterface $flows,
		private readonly ViewNamingDocumentsInterface $views,
		private readonly SchemaMapperInterface $schemas,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The warnings for a migration plan: everything that names a renamed property or the old slug.
	 *
	 * @param array<int|string, mixed> $plan The transform chain.
	 *
	 * @return list<array{kind: string, id: mixed, name: string|null, names: string, op: string, paths: list<string>}> The warnings.
	 *
	 * @spec openspec/changes/modelling-rename-without-loss/tasks.md#1.2
	 */
	public function forPlan(array $plan): array {
		$renamed = self::renamedNames(plan: $plan);
		if ($renamed === []) {
			return [];
		}

		$documents = array_merge($this->flows->namingDocuments(), $this->notificationDocuments(), $this->views->namingDocuments());

		$warnings = [];
		foreach ($renamed as $rename) {
			foreach ($documents as $document) {
				$paths = self::pathsNaming(value: $document['content'], name: $rename['name']);
				if ($paths === []) {
					continue;
				}

				$warnings[] = [
					'kind' => $document['kind'],
					'id' => $document['id'],
					'name' => $document['name'],
					'names' => $rename['name'],
					'op' => $rename['op'],
					'paths' => $paths,
				];
			}
		}

		return $warnings;
	}//end forPlan()

	/**
	 * The old names a plan renames: the `from` of every `rename` and `renameSchema` step.
	 *
	 * @param array<int|string, mixed> $plan The transform chain.
	 *
	 * @return list<array{op: string, name: string}> The renamed names, each once per op.
	 *
	 * @spec openspec/changes/modelling-rename-without-loss/tasks.md#1.2
	 */
	public static function renamedNames(array $plan): array {
		$names = [];
		foreach ($plan as $step) {
			if (is_array($step) === false || in_array(($step['op'] ?? null), ['rename', SchemaSlugRename::OP], true) === false) {
				continue;
			}

			$from = $step['from'] ?? null;
			if (is_string($from) === false || trim($from) === '') {
				continue;
			}

			$names[$step['op'] . "\0" . trim($from)] = ['op' => (string)$step['op'], 'name' => trim($from)];
		}

		return array_values($names);
	}//end renamedNames()

	/**
	 * The dotted paths in a document where it names the word.
	 *
	 * @param mixed  $value The document.
	 * @param string $name  The word.
	 * @param string $path  The path of the value, for the recursion.
	 *
	 * @return list<string> The paths, `$` for the document itself.
	 *
	 * @spec openspec/changes/modelling-rename-without-loss/tasks.md#1.2
	 */
	public static function pathsNaming(mixed $value, string $name, string $path = '$'): array {
		if ($name === '') {
			return [];
		}

		if (is_string($value) === true) {
			if (self::textNames(text: $value, name: $name) === true) {
				return [$path];
			}

			return [];
		}

		if (is_array($value) === false) {
			return [];
		}

		$paths = [];
		foreach ($value as $key => $item) {
			$itemPath = $path . '.' . $key;
			if ((string)$key === $name) {
				$paths[] = $itemPath;
				continue;
			}

			$paths = array_merge($paths, self::pathsNaming(value: $item, name: $name, path: $itemPath));
		}

		return $paths;
	}//end pathsNaming()

	/**
	 * Whether a text is the word or carries it as a whole token.
	 *
	 * @param string $text The text.
	 * @param string $name The word.
	 *
	 * @return bool
	 */
	private static function textNames(string $text, string $name): bool {
		if ($text === $name) {
			return true;
		}

		return preg_match('/(?<![\p{L}\p{N}_-])' . preg_quote($name, '/') . '(?![\p{L}\p{N}_-])/u', $text) === 1;
	}//end textNames()

	/**
	 * The notification rules of every schema that declares any.
	 *
	 * @return list<array{kind: string, id: mixed, name: string|null, content: array}>
	 */
	private function notificationDocuments(): array {
		$documents = [];
		try {
			foreach ($this->schemas->findAll(_rbac: false, _multitenancy: false) as $schema) {
				$rules = self::notificationRules(schema: $schema);
				if ($rules === null) {
					continue;
				}

				$documents[] = ['kind' => 'notificationRule', 'id' => $schema->getId(), 'name' => $schema->getSlug(), 'content' => $rules];
			}
		} catch (Throwable $e) {
			$this->unreadable(source: 'notification rules', error: $e);
		}

		return $documents;
	}//end notificationDocuments()

	/**
	 * A schema's `x-openregister-notifications` block, or null when it declares none.
	 *
	 * @param mixed $schema The schema.
	 *
	 * @return array|null
	 */
	private static function notificationRules(mixed $schema): ?array {
		if (($schema instanceof Schema) === false) {
			return null;
		}

		$rules = (($schema->getConfiguration() ?? [])['x-openregister-notifications'] ?? null);
		if (is_array($rules) === false || $rules === []) {
			return null;
		}

		return $rules;
	}//end notificationRules()

	/**
	 * Log a source the preview could not read.
	 *
	 * @param string    $source The source.
	 * @param Throwable $error  Why.
	 *
	 * @return void
	 */
	private function unreadable(string $source, Throwable $error): void {
		$this->logger->warning(
			message: '[RenameReferenceWarnings] Could not read ' . $source . ' for the rename preview: ' . $error->getMessage(),
			context: ['file' => __FILE__, 'line' => __LINE__]
		);
	}//end unreadable()
}//end class
