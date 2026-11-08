<?php

/**
 * Golden test for what the timeline stores and what it asks its collaborators.
 *
 * Expected values are literals recorded on the code before it was split into units.
 *
 * @category  Test
 * @package   OCA\OpenRegister\Tests\Unit\Service\Timeline
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://www.OpenRegister.app
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace Unit\Service\Timeline;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Notifications\Service\Timeline\TimelineEntryDerivations;
use OCA\OpenRegister\Notifications\Service\Timeline\TimelineEntryRecords;
use OCA\OpenRegister\Notifications\Db\TimelineEntry;
use OCA\OpenRegister\Notifications\Db\TimelineEntryMapper;
use OCA\OpenRegister\Notifications\Service\Timeline\EntryMentionService;
use OCA\OpenRegister\Notifications\Service\Timeline\EntryPin;
use OCA\OpenRegister\Notifications\Service\Timeline\LanguageDetector;
use OCA\OpenRegister\Notifications\Service\Timeline\NoteProjection;
use OCA\OpenRegister\Notifications\Service\Timeline\NoteRewrite;
use OCA\OpenRegister\Notifications\Service\Timeline\ReferenceService;
use OCA\OpenRegister\Notifications\Service\Timeline\ReferenceSource;
use OCA\OpenRegister\Notifications\Service\Timeline\TextBlockService;
use OCA\OpenRegister\Notifications\Service\Timeline\TimelineEntryService;
use OCA\OpenRegister\Notifications\Service\Timeline\TimelineKindService;
use OCA\OpenRegister\Notifications\Service\Timeline\TimelinePermissionException;
use OCA\OpenRegister\Notifications\Service\Timeline\TimelineValidationException;
use OCA\OpenRegister\Notifications\Service\Timeline\TimelineWriteService;
use OCA\OpenRegister\Notifications\Service\TimelineVisibilityService;
use OCA\OpenRegister\Files\Service\NoteService;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Drives the services through the collaborators they build.
 */
class TimelineEntryGoldenTest extends TestCase {

	private array $log = [];

	private function object(): ObjectEntity {
		$object = new ObjectEntity();
		$object->setUuid('object-1');
		$object->setObject(['naam' => 'Jan', 'objectUuid' => 'overwritten', 'zaak' => 'Z1']);

		return $object;
	}//end object()

	private function entryService(?TimelineEntryMapper $mapper = null, bool $mayManage = true): TimelineEntryService {
		if ($mapper === null) {
			$mapper = $this->createMock(TimelineEntryMapper::class);
			$mapper->method('insert')->willReturnArgument(0);
			$mapper->method('update')->willReturnArgument(0);
		}

		$kinds = $this->createMock(TimelineKindService::class);
		$kinds->method('validateFields')->willReturnCallback(
			function (?string $kindSlug, array $fields): array {
				$this->log[] = 'validate '.json_encode([$kindSlug, $fields]);
				return ['accepted' => count($fields)];
			}
		);
		$kinds->method('carriesFollowUp')->willReturnCallback(static fn (?string $kindSlug): bool => $kindSlug === 'terugbel');
		$languages = $this->createMock(LanguageDetector::class);
		$languages->method('detect')->willReturnCallback(static fn (string $text): string => 'taal-'.$text);
		$visibility = $this->createMock(TimelineVisibilityService::class);
		$visibility->method('normalise')->willReturnCallback(static fn (?string $value): string => 'norm-'.(string)$value);
		$visibility->method('mayManage')->willReturn($mayManage);
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		$records = new TimelineEntryRecords(entryMapper: $mapper, kinds: $kinds, languages: $languages);
		return new TimelineEntryService($records, $visibility, $session, $this->createMock(LoggerInterface::class));
	}//end entryService()

	public function testARecordCarriesEverythingThePayloadOffers(): void {
		$entry = $this->entryService()->record(
			$this->object(),
			[
				'kind' => ' terugbel ',
				'fields' => ['a' => 1, 'b' => 2],
				'message' => 'tekst',
				'register' => ' r ',
				'schema' => 's',
				'commentId' => '41',
				'visibility' => 'public',
				'rawSource' => 'bron',
				'rawHeaders' => ['h' => 'v'],
				'siblings' => ['x' => 'y'],
			]
		);

		$this->assertSame(['validate ["terugbel",{"a":1,"b":2}]'], $this->log);
		$this->assertNotNull($entry->getUuid());
		$this->assertSame('object-1', $entry->getObjectUuid());
		$this->assertSame('r', $entry->getRegister());
		$this->assertSame('s', $entry->getSchema());
		$this->assertSame(41, $entry->getCommentId());
		$this->assertSame('terugbel', $entry->getKind());
		$this->assertSame('alice', $entry->getAuthor());
		$this->assertSame('norm-public', $entry->getVisibility());
		$this->assertSame('tekst', $entry->getMessage());
		$this->assertSame(['accepted' => 2], $entry->getFields());
		$this->assertFalse($entry->getPinned());
		$this->assertSame('taal-tekst', $entry->getLanguage());
		$this->assertSame('bron', $entry->getRawSource());
		$this->assertSame(['h' => 'v'], $entry->getRawHeaders());
		$this->assertSame(['y'], $entry->getSiblings());
		$this->assertSame(TimelineEntry::FOLLOW_UP_OPEN, $entry->getFollowUp());
		$this->assertNotNull($entry->getCreated());
		$this->assertNotNull($entry->getUpdated());
	}//end testARecordCarriesEverythingThePayloadOffers()

	public function testARecordIgnoresWhatIsNoList(): void {
		$entry = $this->entryService()->record(
			$this->object(),
			['fields' => 'x', 'rawHeaders' => 'x', 'siblings' => 'x', 'commentId' => 'x']
		);

		$this->assertSame(['validate [null,[]]'], $this->log);
		$this->assertNull($entry->getCommentId());
		$this->assertNull($entry->getRawHeaders());
		$this->assertNull($entry->getSiblings());
		$this->assertNull($entry->getFollowUp());
		$this->assertSame('', $entry->getMessage());
	}//end testARecordIgnoresWhatIsNoList()

	private function syncFromNote(TimelineEntryService $service, ?string $message, ?string $visibility): ?TimelineEntry {
		return $service->syncFromNote(new NoteRewrite(41, $message, $visibility));
	}//end syncFromNote()

	private function pin(TimelineEntryService $service, TimelineEntry $entry, bool $pinned): TimelineEntry {
		return $service->pin($this->object(), new EntryPin($entry, $pinned));
	}//end pin()

	public function testSyncingTouchesOnlyWhatChanged(): void {
		$entry = new TimelineEntry();
		$entry->setMessage('oud');
		$entry->setLanguage('oud');
		$entry->setVisibility('oud');
		$mapper = $this->createMock(TimelineEntryMapper::class);
		$mapper->expects($this->once())->method('findByComment')->with(41)->willReturn($entry);
		$mapper->method('update')->willReturnArgument(0);

		$this->syncFromNote($this->entryService($mapper), null, null);
		$this->assertSame('oud', $entry->getMessage());
		$this->assertSame('oud', $entry->getLanguage());
		$this->assertSame('oud', $entry->getVisibility());
		$this->assertNotNull($entry->getUpdated());
	}//end testSyncingTouchesOnlyWhatChanged()

	public function testSyncingWritesTheNewTextLanguageAndFlag(): void {
		$entry = new TimelineEntry();
		$mapper = $this->createMock(TimelineEntryMapper::class);
		$mapper->method('findByComment')->willReturn($entry);
		$mapper->method('update')->willReturnArgument(0);

		$this->syncFromNote($this->entryService($mapper), 'nieuw', 'public');
		$this->assertSame('nieuw', $entry->getMessage());
		$this->assertSame('taal-nieuw', $entry->getLanguage());
		$this->assertSame('norm-public', $entry->getVisibility());
	}//end testSyncingWritesTheNewTextLanguageAndFlag()

	public function testAPinNamesTheCallerAndTakingItOffClearsThat(): void {
		$service = $this->entryService();
		$entry = new TimelineEntry();

		$this->pin($service, $entry, true);
		$this->assertTrue($entry->getPinned());
		$this->assertSame('alice', $entry->getPinnedBy());
		$this->assertNotNull($entry->getPinnedAt());
		$this->assertNotNull($entry->getUpdated());

		$this->pin($service, $entry, false);
		$this->assertFalse($entry->getPinned());
		$this->assertNull($entry->getPinnedBy());
		$this->assertNull($entry->getPinnedAt());
	}//end testAPinNamesTheCallerAndTakingItOffClearsThat()

	public function testAPinIsRefusedBeforeAnythingIsWritten(): void {
		$mapper = $this->createMock(TimelineEntryMapper::class);
		$mapper->expects($this->never())->method('update');
		$entry = new TimelineEntry();

		try {
			$this->pin($this->entryService($mapper, mayManage: false), $entry, true);
			$this->fail('pinned');
		} catch (TimelinePermissionException $e) {
			$this->assertSame('You do not have permission to pin entries on this object', $e->getMessage());
		}

		$this->assertFalse((bool)$entry->getPinned());
		$this->assertNull($entry->getPinnedBy());
	}//end testAPinIsRefusedBeforeAnythingIsWritten()

	public function testSiblingsNameEveryOtherEntry(): void {
		$entries = [];
		foreach (['a', 'b', 'c'] as $id) {
			$entry = new TimelineEntry();
			$entry->setUuid($id);
			$entry->setObjectUuid('o-'.$id);
			$entry->setRegister('r-'.$id);
			$entry->setSchema('s-'.$id);
			$entries[] = $entry;
		}

		$written = $this->entryService()->linkSiblings($entries);

		$this->assertCount(3, $written);
		$this->assertSame(
			[
				['entry' => 'a', 'objectUuid' => 'o-a', 'register' => 'r-a', 'schema' => 's-a'],
				['entry' => 'c', 'objectUuid' => 'o-c', 'register' => 'r-c', 'schema' => 's-c'],
			],
			$written[1]->getSiblings()
		);
		$this->assertNotNull($written[0]->getUpdated());
	}//end testSiblingsNameEveryOtherEntry()

	private function writeService(TimelineEntryService $entries, ?callable $failing = null): TimelineWriteService {
		$notes = $this->createMock(NoteService::class);
		$notes->method('createNote')->willReturnCallback(
			function (string $objectUuid, string $message, ?string $visibility): array {
				$this->log[] = 'note '.json_encode([$objectUuid, $message, $visibility]);
				return ['id' => 41];
			}
		);
		$references = $this->createMock(ReferenceService::class);
		$references->method('record')->willReturnCallback(
			function (ReferenceSource $source) use ($failing): array {
				$this->log[] = 'references '.json_encode([$source->entryUuid, $source->sourceUuid, $source->text]);
				if ($failing !== null) {
					$failing();
				}

				return [];
			}
		);
		$mentions = $this->createMock(EntryMentionService::class);
		$mentions->method('apply')->willReturnCallback(
			function (ObjectEntity $object, string $entryUuid, ?string $text, ?string $register, ?string $schema) use ($failing): array {
				$this->log[] = 'mentions '.json_encode([$object->getUuid(), $entryUuid, $text, $register, $schema]);
				if ($failing !== null) {
					$failing();
				}

				return [];
			}
		);
		$blocks = $this->createMock(TextBlockService::class);
		$blocks->method('insert')->willReturnCallback(
			function (string $slug, array $variables): string {
				$this->log[] = 'block '.json_encode([$slug, $variables]);
				return 'uit blok';
			}
		);
		$logger = $this->createMock(LoggerInterface::class);
		foreach (['warning', 'error'] as $level) {
			$logger->method($level)->willReturnCallback(
				function (string $message, array $context) use ($level): void {
					$this->log[] = $level.' '.$message.' '.get_class($context['exception']);
				}
			);
		}

		$derived = new TimelineEntryDerivations(references: $references, mentions: $mentions, blocks: $blocks);
		return new TimelineWriteService($notes, $entries, $derived, $logger);
	}//end writeService()

	private function recording(?callable $record = null): TimelineEntryService {
		$entries = $this->createMock(TimelineEntryService::class);
		$entries->method('record')->willReturnCallback(
			function (ObjectEntity $object, array $data) use ($record): TimelineEntry {
				$this->log[] = 'record '.$object->getUuid().' '.json_encode($data);
				if ($record !== null) {
					$record();
				}

				$entry = new TimelineEntry();
				$entry->setUuid('entry-1');
				return $entry;
			}
		);

		return $entries;
	}//end recording()

	public function testWritingARecordsTheNoteThenWhatItDerives(): void {
		$entry = $this->writeService($this->recording())->write(
			$this->object(),
			['message' => ' tekst ', 'visibility' => 'public', 'register' => ' r ', 'schema' => 's']
		);

		$this->assertSame('entry-1', $entry->getUuid());
		$this->assertSame(
			[
				'note ["object-1","tekst","public"]',
				'record object-1 {"message":"tekst","visibility":"public","register":" r ","schema":"s","commentId":41}',
				'references ["entry-1","object-1","tekst"]',
				'mentions ["object-1","entry-1","tekst","r","s"]',
			],
			$this->log
		);
	}//end testWritingARecordsTheNoteThenWhatItDerives()

	public function testATextBlockIsFilledFromTheObjectThenTheCaller(): void {
		$this->writeService($this->recording())->write(
			$this->object(),
			['textBlock' => ' groet ', 'variables' => ['naam' => 'Piet']]
		);

		$this->assertSame('block ["groet",{"id":"object-1","naam":"Piet","objectUuid":"object-1","zaak":"Z1"}]', $this->log[0]);
	}//end testATextBlockIsFilledFromTheObjectThenTheCaller()

	public function testATextBlockCopesWithoutVariablesOrObjectValues(): void {
		$object = new ObjectEntity();
		$object->setUuid('leeg');

		$this->writeService($this->recording())->write($object, ['textBlock' => 'groet', 'variables' => 'x']);

		$this->assertSame('block ["groet",{"id":"leeg","objectUuid":"leeg"}]', $this->log[0]);
	}//end testATextBlockCopesWithoutVariablesOrObjectValues()

	public function testAnEntryWithoutTextIsRefused(): void {
		try {
			$this->writeService($this->recording())->write($this->object(), ['message' => '  ']);
			$this->fail('written');
		} catch (TimelineValidationException $e) {
			$this->assertSame(['message' => 'An entry needs a message or a text block'], $e->getErrors());
		}

		$this->assertSame([], $this->log);
	}//end testAnEntryWithoutTextIsRefused()

	public function testFailingDerivedStepsNeverCostTheEntry(): void {
		$service = $this->writeService($this->recording(), static function (): void {
			throw new RuntimeException('down');
		});

		$this->assertSame('entry-1', $service->write($this->object(), ['message' => 'tekst'])->getUuid());
		$this->assertSame(
			[
				'warning [TimelineWriteService] References of entry entry-1 could not be recorded RuntimeException',
				'warning [TimelineWriteService] Mentions in entry entry-1 could not be applied RuntimeException',
			],
			array_values(array_filter($this->log, static fn (string $line): bool => str_starts_with($line, 'warning')))
		);
	}//end testFailingDerivedStepsNeverCostTheEntry()

	private function projectNote(TimelineWriteService $service, array $note): ?TimelineEntry {
		return $service->projectNote($this->object(), new NoteProjection($note, ' r ', 's'));
	}//end projectNote()

	private function rewrite(TimelineWriteService $service, ?string $message, ?string $visibility): ?TimelineEntry {
		return $service->rewrite($this->object(), new NoteRewrite(41, $message, $visibility));
	}//end rewrite()

	public function testAProjectedNoteGetsARecordAndItsDerivedSteps(): void {
		$entry = $this->projectNote($this->writeService($this->recording()), ['id' => 41, 'message' => 'tekst', 'visibility' => 'public']);

		$this->assertSame('entry-1', $entry->getUuid());
		$this->assertSame(
			[
				'record object-1 {"message":"tekst","commentId":41,"visibility":"public","register":" r ","schema":"s"}',
				'references ["entry-1","object-1","tekst"]',
				'mentions ["object-1","entry-1","tekst","r","s"]',
			],
			$this->log
		);
	}//end testAProjectedNoteGetsARecordAndItsDerivedSteps()

	public function testAProjectionThatFailsIsLoggedAndAnswersNull(): void {
		$entries = $this->recording(static function (): void {
			throw new RuntimeException('down');
		});

		$this->assertNull($this->projectNote($this->writeService($entries), []));
		$this->assertSame(
			[
				'record object-1 {"message":"","commentId":null,"visibility":null,"register":" r ","schema":"s"}',
				'error [TimelineWriteService] Note ? was written but its record was not; it will not be searchable RuntimeException',
			],
			$this->log
		);
	}//end testAProjectionThatFailsIsLoggedAndAnswersNull()

	private function synced(?TimelineEntry $entry): TimelineEntryService {
		$entries = $this->createMock(TimelineEntryService::class);
		$entries->method('syncFromNote')->willReturn($entry);

		return $entries;
	}//end synced()

	public function testARewriteRecordsReferencesOfTheNewTextButNoMentions(): void {
		$entry = new TimelineEntry();
		$entry->setUuid('entry-1');

		$this->assertSame($entry, $this->rewrite($this->writeService($this->synced($entry)), 'nieuw', null));
		$this->assertSame(['references ["entry-1","object-1","nieuw"]'], $this->log);
	}//end testARewriteRecordsReferencesOfTheNewTextButNoMentions()

	public function testARewriteWithoutNewTextOrRecordDerivesNothing(): void {
		$entry = new TimelineEntry();

		$this->assertSame($entry, $this->rewrite($this->writeService($this->synced($entry)), null, 'public'));
		$this->assertNull($this->rewrite($this->writeService($this->synced(null)), 'nieuw', null));
		$this->assertSame([], $this->log);
	}//end testARewriteWithoutNewTextOrRecordDerivesNothing()
}//end class
