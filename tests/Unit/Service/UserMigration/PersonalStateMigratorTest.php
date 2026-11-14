<?php

/**
 * The user migrator: a user's saved views and watches leave and arrive with
 * them, objects and secrets never do, and an import names what it could not
 * restore instead of inventing a register for it.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\UserMigration
 *
 * @spec openspec/changes/platform-user-migrator/specs/platform-user-migration/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\UserMigration;

use DateTime;
use OCA\OpenRegister\Db\CalendarFeedToken;
use OCA\OpenRegister\Notifications\Db\TimelineEntry;
use OCA\OpenRegister\Notifications\Db\Watcher;
use OCA\OpenRegister\Notifications\Db\WatcherMapperInterface;
use OCA\OpenRegister\Search\Db\View;
use OCA\OpenRegister\Search\Db\ViewMapperInterface;
use OCA\OpenRegister\Service\UserMigration\ImportReport;
use OCA\OpenRegister\Service\UserMigration\PersonalStateMigrator;
use OCA\OpenRegister\Service\UserMigration\PersonalStateReader;
use OCA\OpenRegister\Service\UserMigration\RegisterReferences;
use OCP\IL10N;
use OCP\IUser;
use OCP\UserMigration\IExportDestination;
use OCP\UserMigration\IImportSource;
use OCP\UserMigration\UserMigrationException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * REQ-PUM-001 and REQ-PUM-002.
 *
 * @covers \OCA\OpenRegister\Service\UserMigration\PersonalStateMigrator
 * @covers \OCA\OpenRegister\Service\UserMigration\ImportReport
 * @uses \OCA\OpenRegister\Search\Db\View
 * @uses \OCA\OpenRegister\Notifications\Db\Watcher
 * @uses \OCA\OpenRegister\Db\CalendarFeedToken
 * @uses \OCA\OpenRegister\Notifications\Db\TimelineEntry
 */
final class PersonalStateMigratorTest extends TestCase {

	/** @var PersonalStateReader&MockObject */
	private PersonalStateReader $reader;

	/** @var RegisterReferences&MockObject */
	private RegisterReferences $references;

	/** @var ViewMapperInterface&MockObject */
	private ViewMapperInterface $views;

	/** @var WatcherMapperInterface&MockObject */
	private WatcherMapperInterface $watchers;

	/**
	 * Files written to the export.
	 *
	 * @var array<string, string>
	 */
	private array $written = [];

	protected function setUp(): void {
		$this->reader = $this->createMock(PersonalStateReader::class);
		$this->references = $this->createMock(RegisterReferences::class);
		$this->views = $this->createMock(ViewMapperInterface::class);
		$this->watchers = $this->createMock(WatcherMapperInterface::class);

		// This instance knows register 3 as "zaken" and schema 9 as "zaak"; nothing else.
		$slugs = [RegisterReferences::REGISTER => ['3' => 'zaken'], RegisterReferences::SCHEMA => ['9' => 'zaak']];
		$this->references->method('portable')->willReturnCallback(
			static fn (string $kind, string|int $reference): string => ($slugs[$kind][(string)$reference] ?? (string)$reference)
		);
		$this->references->method('local')->willReturnCallback(
			static function (string $kind, string $reference) use ($slugs): ?string {
				$id = array_search($reference, $slugs[$kind], true);
				if ($id === false) {
					return null;
				}

				return (string)$id;
			}
		);
	}//end setUp()

	private function migrator(): PersonalStateMigrator {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);

		return new PersonalStateMigrator(
			reader: $this->reader,
			references: $this->references,
			views: $this->views,
			watchers: $this->watchers,
			l10n: $l10n
		);
	}//end migrator()

	private function user(string $uid): IUser {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);

		return $user;
	}//end user()

	private function destination(): IExportDestination {
		$destination = $this->createMock(IExportDestination::class);
		$destination->method('addFileContents')->willReturnCallback(
			function (string $path, string $content): void {
				$this->written[$path] = $content;
			}
		);

		return $destination;
	}//end destination()

	/**
	 * An export as the import reads it.
	 *
	 * @param array<string, mixed> $files   Path to decoded contents.
	 * @param int|null             $version The migrator version recorded, or null when the export holds none.
	 */
	private function source(array $files, ?int $version = 1): IImportSource {
		$source = $this->createMock(IImportSource::class);
		$source->method('getMigratorVersion')->with('openregister')->willReturn($version);
		$source->method('pathExists')->willReturnCallback(static fn (string $path): bool => array_key_exists($path, $files));
		$source->method('getFileContents')->willReturnCallback(static fn (string $path): string => json_encode($files[$path]));

		return $source;
	}//end source()

	private function exported(string $path): array {
		return json_decode($this->written[$path], true);
	}//end exported()

	private function ownedView(): View {
		$view = new View();
		$view->setName('Mijn open zaken');
		$view->setDescription('Alles wat op mij wacht');
		$view->setOwner('alice');
		$view->setIsPublic(true);
		$view->setIsDefault(true);
		$view->setQuery(['registers' => [3], 'schemas' => ['9'], 'filters' => ['status' => 'open']]);
		$view->setPresentation(['type' => 'table']);
		$view->setSharedWith([['group' => 'team-a']]);
		$view->setFavoredBy(['bob']);

		return $view;
	}//end ownedView()

	private function watch(): Watcher {
		$watch = new Watcher();
		$watch->setUserId('alice');
		$watch->setObjectUuid('0b1d6a3e-0000-4000-8000-000000000001');
		$watch->setRegister('3');
		$watch->setSchema('9');
		$watch->setNotify(false);

		return $watch;
	}//end watch()

	/**
	 * Spec: a caseworker's habits move with them; references travel as slugs.
	 */
	public function testTheExportHoldsTheViewsAndWatchesWithPortableReferences(): void {
		$this->reader->method('ownedViews')->with('alice')->willReturn([$this->ownedView()]);
		$this->reader->method('watches')->with('alice')->willReturn([$this->watch()]);

		$this->migrator()->export($this->user(uid: 'alice'), $this->destination(), new BufferedOutput());

		$this->assertSame(
			[[
				'name' => 'Mijn open zaken',
				'description' => 'Alles wat op mij wacht',
				'isDefault' => true,
				'query' => ['registers' => ['zaken'], 'schemas' => ['zaak'], 'filters' => ['status' => 'open']],
				'presentation' => ['type' => 'table'],
			]],
			$this->exported(path: PersonalStateMigrator::VIEWS)
		);
		$this->assertSame(
			[['objectUuid' => '0b1d6a3e-0000-4000-8000-000000000001', 'register' => 'zaken', 'schema' => 'zaak', 'notify' => false]],
			$this->exported(path: PersonalStateMigrator::WATCHES)
		);
	}//end testTheExportHoldsTheViewsAndWatchesWithPortableReferences()

	/**
	 * Spec: a leaver's export is not a bulk extraction, and its manifest says so.
	 */
	public function testTheExportHoldsNoObjectAndItsManifestSaysSo(): void {
		$this->reader->method('ownedViews')->willReturn([$this->ownedView()]);
		$this->reader->method('watches')->willReturn([$this->watch()]);

		$this->migrator()->export($this->user(uid: 'alice'), $this->destination(), new BufferedOutput());

		$this->assertSame(
			[
				PersonalStateMigrator::VIEWS,
				PersonalStateMigrator::WATCHES,
				PersonalStateMigrator::FEED_TOKENS,
				PersonalStateMigrator::NOTES,
				PersonalStateMigrator::TIMELINE,
				PersonalStateMigrator::MANIFEST,
			],
			array_keys($this->written)
		);
		$manifest = $this->exported(path: PersonalStateMigrator::MANIFEST);
		$this->assertFalse($manifest['objectsIncluded']);
		$this->assertFalse($manifest['secretsIncluded']);
		$this->assertSame(['views' => 1, 'watches' => 1, 'feedTokens' => 0, 'notes' => 0, 'timelineEntries' => 0], $manifest['contains']);
		$this->assertStringContainsString('Objects are not exported', $manifest['notes'][0]);

		// Nothing in the export names other people or groups.
		$everything = implode('', $this->written);
		$this->assertStringNotContainsString('team-a', $everything);
		$this->assertStringNotContainsString('bob', $everything);
	}//end testTheExportHoldsNoObjectAndItsManifestSaysSo()

	/**
	 * A failed write is the migration's failure, not a silent half export.
	 */
	public function testAFailedWriteFailsTheExport(): void {
		$destination = $this->createMock(IExportDestination::class);
		$destination->method('addFileContents')->willThrowException(new RuntimeException('disk full'));

		$this->expectException(UserMigrationException::class);
		$this->migrator()->export($this->user(uid: 'alice'), $destination, new BufferedOutput());
	}//end testAFailedWriteFailsTheExport()

	/**
	 * Spec: the views and watches are present on an instance holding the same registers.
	 * A view arrives private, with this instance's ids, owned by the importing user.
	 */
	public function testTheImportRestoresWhatTheTargetHolds(): void {
		$inserted = [];
		$this->views->expects($this->once())->method('insert')->willReturnCallback(
			static function (View $view) use (&$inserted): View {
				$inserted[] = $view;
				return $view;
			}
		);
		$this->watchers->expects($this->once())->method('subscribe')->with(
			$this->callback(
				static fn (Watcher $watch): bool => $watch->getUserId() === 'carol'
					&& $watch->getObjectUuid() === 'obj-1'
					&& $watch->getRegister() === '3'
					&& $watch->getSchema() === '9'
			),
			false
		)->willReturnArgument(0);

		$output = new BufferedOutput();
		$this->migrator()->import(
			$this->user(uid: 'carol'),
			$this->source(
				files: [
					PersonalStateMigrator::VIEWS => [['name' => 'Mijn open zaken', 'isDefault' => true, 'query' => ['registers' => ['zaken'], 'schemas' => ['zaak']]]],
					PersonalStateMigrator::WATCHES => [['objectUuid' => 'obj-1', 'register' => 'zaken', 'schema' => 'zaak', 'notify' => false]],
				]
			),
			$output
		);

		$this->assertCount(1, $inserted);
		$this->assertSame('carol', $inserted[0]->getOwner());
		$this->assertFalse($inserted[0]->getIsPublic());
		$this->assertTrue($inserted[0]->getIsDefault());
		$this->assertSame(['registers' => ['3'], 'schemas' => ['9']], $inserted[0]->getQuery());
		$this->assertSame("OpenRegister: restored 2, not restored 0.\n", $output->fetch());
	}//end testTheImportRestoresWhatTheTargetHolds()

	/**
	 * Spec: an unrestorable view is reported, not invented; the same for a watch.
	 */
	public function testWhatTheTargetCannotHoldIsReportedAndNotCreated(): void {
		$this->views->expects($this->never())->method('insert');
		$this->watchers->expects($this->never())->method('subscribe');

		$output = new BufferedOutput();
		$this->migrator()->import(
			$this->user(uid: 'carol'),
			$this->source(
				files: [
					PersonalStateMigrator::VIEWS => [['name' => 'Bezwaren', 'query' => ['registers' => ['bezwaar'], 'schemas' => ['zaak']]]],
					PersonalStateMigrator::WATCHES => [['objectUuid' => 'obj-2', 'register' => 'bezwaar', 'schema' => 'zaak']],
				]
			),
			$output
		);

		$this->assertSame(
			"OpenRegister: restored 0, not restored 2.\n"
			. "Not restored: view \"Bezwaren\": this instance has no register bezwaar\n"
			. "Not restored: watch on object obj-2: this instance has no register \"bezwaar\" with schema \"zaak\"\n",
			$output->fetch()
		);
	}//end testWhatTheTargetCannotHoldIsReportedAndNotCreated()

	/**
	 * A store that refuses is reported, and the rest of the import goes on.
	 */
	public function testARefusedStoreIsReported(): void {
		$this->views->method('insert')->willThrowException(new RuntimeException('Access denied'));
		$this->watchers->expects($this->once())->method('subscribe')->willReturnArgument(0);

		$output = new BufferedOutput();
		$this->migrator()->import(
			$this->user(uid: 'carol'),
			$this->source(
				files: [
					PersonalStateMigrator::VIEWS => [['name' => 'V', 'query' => []]],
					PersonalStateMigrator::WATCHES => [['objectUuid' => 'obj-1', 'register' => 'zaken', 'schema' => 'zaak']],
				]
			),
			$output
		);

		$this->assertStringContainsString('Not restored: view "V": it could not be stored: Access denied', $output->fetch());
	}//end testARefusedStoreIsReported()

	/**
	 * An export without this migrator's part restores nothing and says so.
	 */
	public function testAnExportWithoutOurPartRestoresNothing(): void {
		$this->views->expects($this->never())->method('insert');
		$output = new BufferedOutput();

		$this->migrator()->import($this->user(uid: 'carol'), $this->source(files: [], version: null), $output);

		$this->assertSame("No OpenRegister personal state in this export.\n", $output->fetch());
	}//end testAnExportWithoutOurPartRestoresNothing()

	/**
	 * The report keeps each reason.
	 */
	public function testTheReportNamesEachItemNotRestored(): void {
		$report = new ImportReport();
		$report->restored(what: 'a');
		$report->skipped(what: 'b', reason: 'why');

		$this->assertSame(['b: why'], $report->notRestored());
		$this->assertSame(['OpenRegister: restored 1, not restored 1.', 'Not restored: b: why'], $report->lines());
	}//end testTheReportNamesEachItemNotRestored()

	/**
	 * A calendar feed token travels as its details only: label, scope with a
	 * portable schema reference, and dates. Its value never leaves (D-4).
	 */
	public function testFeedTokenDetailsAreExportedWithoutTheirValue(): void {
		$token = new CalendarFeedToken();
		$token->setToken('feed-secret-0f9a');
		$token->setUserId('alice');
		$token->setLabel('Mijn zaken in de agenda');
		$token->setScopeType(CalendarFeedToken::SCOPE_SCHEMA);
		$token->setScopeId('9');
		$token->setCreatedAt(new DateTime('2026-03-01T10:00:00+00:00'));
		$token->setExpiresAt(new DateTime('2027-03-01T10:00:00+00:00'));
		$token->setLastReadAt(new DateTime('2026-11-01T08:30:00+00:00'));
		$view = new CalendarFeedToken();
		$view->setToken('feed-secret-77b1');
		$view->setLabel('Teamplanning');
		$view->setScopeType(CalendarFeedToken::SCOPE_VIEW);
		$view->setScopeId('12');
		$view->setRevokedAt(new DateTime('2026-06-01T00:00:00+00:00'));
		$this->reader->method('ownedViews')->willReturn([]);
		$this->reader->method('watches')->willReturn([]);
		$this->reader->expects($this->once())->method('feedTokens')->with('alice')->willReturn([$token, $view]);

		$this->migrator()->export($this->user(uid: 'alice'), $this->destination(), new BufferedOutput());

		$this->assertSame(
			[
				[
					'label' => 'Mijn zaken in de agenda',
					'scopeType' => 'schema',
					'scopeId' => 'zaak',
					'createdAt' => '2026-03-01T10:00:00+00:00',
					'expiresAt' => '2027-03-01T10:00:00+00:00',
					'revokedAt' => null,
					'lastReadAt' => '2026-11-01T08:30:00+00:00',
				],
				[
					'label' => 'Teamplanning',
					'scopeType' => 'view',
					'scopeId' => '12',
					'createdAt' => null,
					'expiresAt' => null,
					'revokedAt' => '2026-06-01T00:00:00+00:00',
					'lastReadAt' => null,
				],
			],
			$this->exported(path: PersonalStateMigrator::FEED_TOKENS)
		);
		$manifest = $this->exported(path: PersonalStateMigrator::MANIFEST);
		$this->assertSame(2, $manifest['contains']['feedTokens']);
		$this->assertFalse($manifest['secretsIncluded']);
		$everything = implode('', $this->written);
		$this->assertStringNotContainsString('feed-secret', $everything);
	}//end testFeedTokenDetailsAreExportedWithoutTheirValue()

	/**
	 * The user's own notes and timeline entries travel as a readable archive, with portable references.
	 */
	public function testOwnNotesAndTimelineEntriesAreExportedAsAnArchive(): void {
		$entry = new TimelineEntry();
		$entry->setObjectUuid('obj-1');
		$entry->setRegister('3');
		$entry->setSchema('9');
		$entry->setKind('note');
		$entry->setAuthor('alice');
		$entry->setVisibility('internal');
		$entry->setMessage('Aanvrager gebeld, stukken volgen.');
		$entry->setCreated(new DateTime('2026-10-05T09:15:00+00:00'));
		$note = ['objectUuid' => 'obj-2', 'message' => 'Termijn verlengd.', 'createdAt' => '2026-10-06 11:00:00'];
		$this->reader->method('ownedViews')->willReturn([]);
		$this->reader->method('watches')->willReturn([]);
		$this->reader->method('feedTokens')->willReturn([]);
		$this->reader->expects($this->once())->method('notes')->with('alice')->willReturn([$note]);
		$this->reader->expects($this->once())->method('timelineEntries')->with('alice')->willReturn([$entry]);

		$this->migrator()->export($this->user(uid: 'alice'), $this->destination(), new BufferedOutput());

		$this->assertSame([$note], $this->exported(path: PersonalStateMigrator::NOTES));
		$this->assertSame(
			[
				[
					'objectUuid' => 'obj-1',
					'register' => 'zaken',
					'schema' => 'zaak',
					'kind' => 'note',
					'visibility' => 'internal',
					'message' => 'Aanvrager gebeld, stukken volgen.',
					'createdAt' => '2026-10-05T09:15:00+00:00',
				],
			],
			$this->exported(path: PersonalStateMigrator::TIMELINE)
		);
		$manifest = $this->exported(path: PersonalStateMigrator::MANIFEST);
		$this->assertSame(1, $manifest['contains']['notes']);
		$this->assertSame(1, $manifest['contains']['timelineEntries']);
		$this->assertFalse($manifest['objectsIncluded']);
	}//end testOwnNotesAndTimelineEntriesAreExportedAsAnArchive()

	/**
	 * The archive is not written back: the import says so in one line.
	 */
	public function testAnImportKeepsTheArchiveAndSaysSo(): void {
		$output = new BufferedOutput();

		$this->migrator()->import(
			$this->user(uid: 'carol'),
			$this->source(
				files: [
					PersonalStateMigrator::NOTES => [['objectUuid' => 'obj-2', 'message' => 'Termijn verlengd.']],
					PersonalStateMigrator::TIMELINE => [['objectUuid' => 'obj-1', 'message' => 'Gebeld.'], ['objectUuid' => 'obj-1', 'message' => 'Gemaild.']],
				]
			),
			$output
		);

		$this->assertSame(
			"OpenRegister: restored 0, not restored 1.\n"
			. "Not restored: 3 notes and timeline entries: they are kept in the export as an archive; the objects they were written on are not in it\n",
			$output->fetch()
		);
	}//end testAnImportKeepsTheArchiveAndSaysSo()

	/**
	 * An import creates no token: each one in the export is named as not restored.
	 */
	public function testAnImportReportsFeedTokensAndCreatesNone(): void {
		$this->views->expects($this->never())->method('insert');
		$this->watchers->expects($this->never())->method('subscribe');
		$output = new BufferedOutput();

		$this->migrator()->import(
			$this->user(uid: 'carol'),
			$this->source(files: [PersonalStateMigrator::FEED_TOKENS => [['label' => 'Mijn zaken in de agenda', 'scopeType' => 'schema', 'scopeId' => 'zaak']]]),
			$output
		);

		$this->assertSame(
			"OpenRegister: restored 0, not restored 1.\n"
			. "Not restored: calendar feed token \"Mijn zaken in de agenda\": a token is not migrated; create a new one on this instance\n",
			$output->fetch()
		);
	}//end testAnImportReportsFeedTokensAndCreatesNone()

	/**
	 * The migrator is named for the user and identified as openregister.
	 */
	public function testItsIdentity(): void {
		$migrator = $this->migrator();

		$this->assertSame('openregister', $migrator->getId());
		$this->assertSame('OpenRegister', $migrator->getDisplayName());
		$this->assertStringContainsString('stay with your organisation', $migrator->getDescription());
		$this->assertSame(1, $migrator->getVersion());
	}//end testItsIdentity()
}//end class
