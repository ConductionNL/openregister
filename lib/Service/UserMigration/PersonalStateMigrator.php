<?php

/**
 * The user migrator: what a user personally holds in OpenRegister leaves and arrives with them.
 *
 * Exports, for one user, the saved views they own and the objects they watch
 * (a favourite is a watch). Objects are not exported: a record belongs to the
 * organisation, and a migrator that carried it would be a bulk extraction with
 * an export button (D-1). The export carries a manifest that says what it holds
 * and that objects were not taken (D-2). The import restores what the target
 * can hold and names the rest; it creates no register or schema (D-3).
 *
 * Register and schema references travel as slugs, because ids are local to an
 * instance. A view arrives private: publishing it on the target is the user's
 * own act there.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\UserMigration
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/platform-user-migrator/specs/platform-user-migration/spec.md#requirement-a-users-personal-state-is-exported-and-imported-req-pum-001
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\UserMigration;

use OCA\OpenRegister\Notifications\Db\Watcher;
use OCA\OpenRegister\Notifications\Db\WatcherMapperInterface;
use OCA\OpenRegister\Search\Db\View;
use OCA\OpenRegister\Search\Db\ViewMapperInterface;
use OCP\IL10N;
use OCP\IUser;
use OCP\UserMigration\IExportDestination;
use OCP\UserMigration\IImportSource;
use OCP\UserMigration\IMigrator;
use OCP\UserMigration\TMigratorBasicVersionHandling;
use OCP\UserMigration\UserMigrationException;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/**
 * Exports and imports a user's saved views and watches, never objects.
 *
 * @spec openspec/changes/platform-user-migrator/specs/platform-user-migration/spec.md#requirement-a-users-personal-state-is-exported-and-imported-req-pum-001
 * @spec openspec/changes/platform-user-migrator/specs/platform-user-migration/spec.md#requirement-objects-are-not-exported-and-both-ends-say-so-req-pum-002
 */
class PersonalStateMigrator implements IMigrator {
	use TMigratorBasicVersionHandling;

	/**
	 * The folder of this migrator inside the export.
	 *
	 * @var string
	 */
	public const PATH = 'openregister/';

	/**
	 * The manifest: what the export holds and what it does not.
	 *
	 * @var string
	 */
	public const MANIFEST = self::PATH . 'manifest.json';

	/**
	 * The saved views.
	 *
	 * @var string
	 */
	public const VIEWS = self::PATH . 'views.json';

	/**
	 * The watches.
	 *
	 * @var string
	 */
	public const WATCHES = self::PATH . 'watches.json';

	/**
	 * Constructor.
	 *
	 * @param PersonalStateReader    $reader     Reads what the user holds.
	 * @param RegisterReferences     $references Translates register and schema references.
	 * @param ViewMapperInterface    $views      Stores a restored view.
	 * @param WatcherMapperInterface $watchers   Stores a restored watch.
	 * @param IL10N                  $l10n       The display name and description.
	 */
	public function __construct(
		private readonly PersonalStateReader $reader,
		private readonly RegisterReferences $references,
		private readonly ViewMapperInterface $views,
		private readonly WatcherMapperInterface $watchers,
		private readonly IL10N $l10n,
	) {
	}//end __construct()

	/**
	 * Write the user's views, watches and the manifest into the export.
	 *
	 * @param IUser              $user              The user.
	 * @param IExportDestination $exportDestination The export.
	 * @param OutputInterface    $output            The command's output.
	 *
	 * @return void
	 *
	 * @throws UserMigrationException When the export cannot be written.
	 *
	 * @spec openspec/changes/platform-user-migrator/specs/platform-user-migration/spec.md#requirement-a-users-personal-state-is-exported-and-imported-req-pum-001
	 * @spec openspec/changes/platform-user-migrator/specs/platform-user-migration/spec.md#requirement-objects-are-not-exported-and-both-ends-say-so-req-pum-002
	 */
	public function export(IUser $user, IExportDestination $exportDestination, OutputInterface $output): void {
		$output->writeln('Exporting OpenRegister saved views and watches…');

		$views = array_map(fn (View $view): array => $this->portableView(view: $view), $this->reader->ownedViews(userId: $user->getUID()));
		$watches = array_map(fn (Watcher $watch): array => $this->portableWatch(watch: $watch), $this->reader->watches(userId: $user->getUID()));

		try {
			$exportDestination->addFileContents(self::VIEWS, self::json(data: $views));
			$exportDestination->addFileContents(self::WATCHES, self::json(data: $watches));
			$exportDestination->addFileContents(self::MANIFEST, self::json(data: self::manifest(views: count($views), watches: count($watches))));
		} catch (Throwable $e) {
			throw new UserMigrationException('Could not export the OpenRegister personal state', 0, $e);
		}
	}//end export()

	/**
	 * Restore what the target can hold and report the rest.
	 *
	 * @param IUser           $user         The user on this instance.
	 * @param IImportSource   $importSource The export.
	 * @param OutputInterface $output       The command's output; the report is written here.
	 *
	 * @return void
	 *
	 * @throws UserMigrationException When the export's version is newer than this migrator reads.
	 *
	 * @spec openspec/changes/platform-user-migrator/specs/platform-user-migration/spec.md#requirement-objects-are-not-exported-and-both-ends-say-so-req-pum-002
	 */
	public function import(IUser $user, IImportSource $importSource, OutputInterface $output): void {
		if ($importSource->getMigratorVersion($this->getId()) === null) {
			$output->writeln('No OpenRegister personal state in this export.');
			return;
		}

		$report = new ImportReport();
		foreach (self::listIn(source: $importSource, path: self::VIEWS) as $view) {
			$this->restoreView(data: $view, userId: $user->getUID(), report: $report);
		}

		foreach (self::listIn(source: $importSource, path: self::WATCHES) as $watch) {
			$this->restoreWatch(data: $watch, userId: $user->getUID(), report: $report);
		}

		foreach ($report->lines() as $line) {
			$output->writeln($line);
		}
	}//end import()

	/**
	 * The migrator's id.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/platform-user-migrator/specs/platform-user-migration/spec.md#requirement-a-users-personal-state-is-exported-and-imported-req-pum-001
	 */
	public function getId(): string {
		return 'openregister';
	}//end getId()

	/**
	 * The migrator's name, shown to the user.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/platform-user-migrator/specs/platform-user-migration/spec.md#requirement-a-users-personal-state-is-exported-and-imported-req-pum-001
	 */
	public function getDisplayName(): string {
		return $this->l10n->t('OpenRegister');
	}//end getDisplayName()

	/**
	 * What the migrator carries, shown to the user.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/platform-user-migrator/specs/platform-user-migration/spec.md#requirement-a-users-personal-state-is-exported-and-imported-req-pum-001
	 */
	public function getDescription(): string {
		return $this->l10n->t('Your saved views and the objects you watch. The objects themselves stay with your organisation.');
	}//end getDescription()

	/**
	 * The manifest of an export.
	 *
	 * @param int $views   The number of views exported.
	 * @param int $watches The number of watches exported.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/platform-user-migrator/specs/platform-user-migration/spec.md#requirement-objects-are-not-exported-and-both-ends-say-so-req-pum-002
	 */
	public static function manifest(int $views, int $watches): array {
		return [
			'contains' => [
				'views' => $views,
				'watches' => $watches,
			],
			'objectsIncluded' => false,
			'secretsIncluded' => false,
			'notes' => [
				'Objects are not exported: they belong to the organisation that holds them.',
				'A watch names an object by its uuid; the object itself is not in this export.',
				'Register and schema references are slugs; an import resolves them on the target and creates none.',
				'Views are restored private.',
			],
		];
	}//end manifest()

	/**
	 * A view as the export holds it.
	 *
	 * @param View $view The view.
	 *
	 * @return array<string, mixed>
	 */
	private function portableView(View $view): array {
		$query = ($view->getQuery() ?? []);
		foreach ([RegisterReferences::REGISTER => 'registers', RegisterReferences::SCHEMA => 'schemas'] as $kind => $key) {
			if (is_array($query[$key] ?? null) === true) {
				$query[$key] = array_map(
					fn (mixed $reference): string => $this->references->portable(kind: $kind, reference: self::reference(value: $reference)),
					array_values($query[$key])
				);
			}
		}

		return [
			'name' => $view->getName(),
			'description' => $view->getDescription(),
			'isDefault' => $view->getIsDefault(),
			'query' => $query,
			'presentation' => $view->getPresentation(),
		];
	}//end portableView()

	/**
	 * A watch as the export holds it: the object's uuid and where it lives, never the object.
	 *
	 * @param Watcher $watch The watch.
	 *
	 * @return array<string, mixed>
	 */
	private function portableWatch(Watcher $watch): array {
		return [
			'objectUuid' => $watch->getObjectUuid(),
			'register' => $this->references->portable(kind: RegisterReferences::REGISTER, reference: (string)$watch->getRegister()),
			'schema' => $this->references->portable(kind: RegisterReferences::SCHEMA, reference: (string)$watch->getSchema()),
			'notify' => $watch->getNotify(),
		];
	}//end portableWatch()

	/**
	 * Restore one view, or report why not.
	 *
	 * @param array<string, mixed> $data   The view from the export.
	 * @param string               $userId The owner on this instance.
	 * @param ImportReport         $report The report.
	 *
	 * @return void
	 */
	private function restoreView(array $data, string $userId, ImportReport $report): void {
		$name = (string)($data['name'] ?? '');
		$query = self::arrayOrNull(value: ($data['query'] ?? null)) ?? [];
		$missing = [];
		foreach ([RegisterReferences::REGISTER => 'registers', RegisterReferences::SCHEMA => 'schemas'] as $kind => $key) {
			if (is_array($query[$key] ?? null) === false) {
				continue;
			}

			$local = [];
			foreach (array_values($query[$key]) as $reference) {
				$id = $this->references->local(kind: $kind, reference: self::reference(value: $reference));
				if ($id === null) {
					$missing[] = $kind . ' ' . self::reference(value: $reference);
					continue;
				}

				$local[] = $id;
			}

			$query[$key] = $local;
		}

		if ($missing !== []) {
			$report->skipped(what: 'view "' . $name . '"', reason: 'this instance has no ' . implode(', ', $missing));
			return;
		}

		$view = new View();
		$view->setName($name);
		$view->setDescription(self::stringOrNull(value: ($data['description'] ?? null)));
		$view->setOwner($userId);
		$view->setIsPublic(false);
		$view->setIsDefault(($data['isDefault'] ?? false) === true);
		$view->setQuery($query);
		$view->setPresentation(self::arrayOrNull(value: ($data['presentation'] ?? null)));

		try {
			$this->views->insert($view);
			$report->restored(what: 'view "' . $name . '"');
		} catch (Throwable $e) {
			$report->skipped(what: 'view "' . $name . '"', reason: 'it could not be stored: ' . $e->getMessage());
		}
	}//end restoreView()

	/**
	 * Restore one watch, or report why not.
	 *
	 * @param array<string, mixed> $data   The watch from the export.
	 * @param string               $userId The watcher on this instance.
	 * @param ImportReport         $report The report.
	 *
	 * @return void
	 */
	private function restoreWatch(array $data, string $userId, ImportReport $report): void {
		$objectUuid = (string)($data['objectUuid'] ?? '');
		$what = 'watch on object ' . $objectUuid;
		$register = $this->references->local(kind: RegisterReferences::REGISTER, reference: (string)($data['register'] ?? ''));
		$schema = $this->references->local(kind: RegisterReferences::SCHEMA, reference: (string)($data['schema'] ?? ''));
		if ($objectUuid === '' || $register === null || $schema === null) {
			$where = 'register "' . ($data['register'] ?? '') . '" with schema "' . ($data['schema'] ?? '') . '"';
			$report->skipped(what: $what, reason: 'this instance has no ' . $where);
			return;
		}

		$watch = new Watcher();
		$watch->setUserId($userId);
		$watch->setObjectUuid($objectUuid);
		$watch->setRegister($register);
		$watch->setSchema($schema);

		try {
			$this->watchers->subscribe($watch, ($data['notify'] ?? true) !== false);
			$report->restored(what: $what);
		} catch (Throwable $e) {
			$report->skipped(what: $what, reason: 'it could not be stored: ' . $e->getMessage());
		}
	}//end restoreWatch()

	/**
	 * The list a JSON file of the export holds; an absent or unreadable file holds none.
	 *
	 * @param IImportSource $source The export.
	 * @param string        $path   The file.
	 *
	 * @return list<array<string, mixed>>
	 */
	private static function listIn(IImportSource $source, string $path): array {
		if ($source->pathExists($path) === false) {
			return [];
		}

		$decoded = json_decode($source->getFileContents($path), true);
		if (is_array($decoded) === false) {
			return [];
		}

		return array_values(array_filter($decoded, 'is_array'));
	}//end listIn()

	/**
	 * A register or schema reference as a string; a non-scalar is no reference.
	 *
	 * @param mixed $value The reference.
	 *
	 * @return string
	 */
	private static function reference(mixed $value): string {
		if (is_scalar($value) === true) {
			return (string)$value;
		}

		return '';
	}//end reference()

	/**
	 * An array, or null for anything else.
	 *
	 * @param mixed $value The value.
	 *
	 * @return array<mixed>|null
	 */
	private static function arrayOrNull(mixed $value): ?array {
		if (is_array($value) === true) {
			return $value;
		}

		return null;
	}//end arrayOrNull()

	/**
	 * A string, or null for anything else.
	 *
	 * @param mixed $value The value.
	 *
	 * @return string|null
	 */
	private static function stringOrNull(mixed $value): ?string {
		if (is_string($value) === true) {
			return $value;
		}

		return null;
	}//end stringOrNull()

	/**
	 * Encode for the export.
	 *
	 * @param array<mixed> $data The data.
	 *
	 * @return string
	 */
	private static function json(array $data): string {
		return json_encode($data, (JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
	}//end json()
}//end class
