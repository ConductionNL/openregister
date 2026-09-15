<?php

/**
 * Unit tests for TimelineEntriesController.
 *
 * Covers the enforced visibility filter a caller cannot drop, the internal
 * entry that answers 404 rather than 403, the raw source under the entry's own
 * access, the multi-object write that refuses before it writes anything, and
 * the search that needs a term.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/timeline-entries-are-records/specs/object-interactions/spec.md
 */

declare(strict_types=1);

namespace Unit\Controller;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable Squiz.Commenting.VariableComment.Missing -- PHPUnit fixture properties are named by their type.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Controller\TimelineEntriesController;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\TimelineEntry;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\Timeline\ReferenceService;
use OCA\OpenRegister\Service\Timeline\TimelineEntrySearchService;
use OCA\OpenRegister\Service\Timeline\TimelineEntryService;
use OCA\OpenRegister\Service\Timeline\TimelinePermissionException;
use OCA\OpenRegister\Service\Timeline\TimelineValidationException;
use OCA\OpenRegister\Service\Timeline\TimelineWriteService;
use OCA\OpenRegister\Service\TimelineVisibilityService;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * TimelineEntriesControllerTest.
 *
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)
 */
class TimelineEntriesControllerTest extends TestCase {
	private TimelineEntriesController $controller;
	private IRequest&MockObject $request;
	private ObjectService&MockObject $objects;
	private TimelineEntryService&MockObject $entries;
	private TimelineWriteService&MockObject $writer;
	private TimelineEntrySearchService&MockObject $search;
	private ReferenceService&MockObject $references;
	private TimelineVisibilityService&MockObject $visibility;

	protected function setUp(): void {
		parent::setUp();

		$this->request = $this->createMock(IRequest::class);
		$this->objects = $this->createMock(ObjectService::class);
		$this->entries = $this->createMock(TimelineEntryService::class);
		$this->writer = $this->createMock(TimelineWriteService::class);
		$this->search = $this->createMock(TimelineEntrySearchService::class);
		$this->references = $this->createMock(ReferenceService::class);

		$this->visibility = $this->createMock(TimelineVisibilityService::class);
		$this->visibility->method('isKnownValue')->willReturnCallback(
			static fn (?string $value): bool => in_array($value, ['internal', 'public'], true)
		);
		$this->visibility->method('normalise')->willReturnCallback(
			static function (?string $value): string {
				if ($value === 'public') {
					return 'public';
				}

				return 'internal';
			}
		);

		$this->controller = new TimelineEntriesController(
			'openregister',
			$this->request,
			$this->objects,
			$this->entries,
			$this->writer,
			$this->search,
			$this->references,
			$this->visibility,
			$this->createMock(LoggerInterface::class)
		);
	}

	private function object(): ObjectEntity {
		$object = new ObjectEntity();
		$object->setUuid('case-1');

		return $object;
	}

	private function objectResolves(): void {
		$this->objects->method('getObject')->willReturn($this->object());
	}

	private function entry(string $visibility = 'internal'): TimelineEntry {
		$entry = new TimelineEntry();
		$entry->setUuid('entry-a');
		$entry->setObjectUuid('case-1');
		$entry->setVisibility($visibility);

		return $entry;
	}

	public function testAnObjectThatDoesNotResolveIsNotFound(): void {
		$this->objects->method('getObject')->willReturn(null);
		$this->request->method('getParams')->willReturn([]);

		$this->assertSame(404, $this->controller->index('zaken', 'zaak', 'nope')->getStatus());
	}

	public function testAReaderIsServedThePublicViewWithoutAskingForIt(): void {
		$this->objectResolves();
		$this->request->method('getParams')->willReturn([]);
		$this->visibility->method('effectiveFilter')->willReturn('public');
		$this->visibility->method('mayManage')->willReturn(false);
		// The filter reaches the READ, so it cannot be dropped by omitting a
		// query parameter.
		$this->entries->expects($this->once())->method('listForObject')
			->with($this->anything(), 'public', 50, 0)
			->willReturn([]);

		$data = $this->controller->index('zaken', 'zaak', 'case-1')->getData();

		$this->assertSame('public', $data['visibility']);
		$this->assertFalse($data['canManage']);
	}

	public function testAnInternalEntryIsNotFoundForAReaderRatherThanForbidden(): void {
		$this->objectResolves();
		$this->entries->method('get')->willReturn($this->entry('internal'));
		$this->visibility->method('mayManage')->willReturn(false);

		// A 403 on a specific entry id confirms that the entry exists.
		$this->assertSame(404, $this->controller->show('zaken', 'zaak', 'case-1', 'entry-a')->getStatus());
	}

	public function testAPublicEntryIsReadableByAReader(): void {
		$this->objectResolves();
		$this->entries->method('get')->willReturn($this->entry('public'));
		$this->visibility->method('mayManage')->willReturn(false);
		$this->references->method('forEntry')->willReturn([]);

		$data = $this->controller->show('zaken', 'zaak', 'case-1', 'entry-a')->getData();

		$this->assertSame('entry-a', $data['id']);
		$this->assertSame([], $data['references']);
	}

	public function testAnEntryThatIsNotOnThisObjectIsNotFound(): void {
		$this->objectResolves();
		$this->entries->method('get')->willReturn(null);

		$this->assertSame(404, $this->controller->show('zaken', 'zaak', 'case-1', 'entry-a')->getStatus());
	}

	public function testTheRawSourceIsRefusedToAReaderOfAnInternalEntry(): void {
		$this->objectResolves();
		$this->entries->method('get')->willReturn($this->entry('internal'));
		$this->visibility->method('mayManage')->willReturn(false);
		$this->entries->expects($this->never())->method('sourceFor');

		$this->assertSame(404, $this->controller->source('zaken', 'zaak', 'case-1', 'entry-a')->getStatus());
	}

	public function testADisputedArrivalDateIsSettledByTheHeaders(): void {
		$this->objectResolves();
		$this->entries->method('get')->willReturn($this->entry('internal'));
		$this->visibility->method('mayManage')->willReturn(true);
		$this->entries->method('sourceFor')->willReturn(
			['source' => 'Received: from mail.example', 'headers' => ['Date' => 'Mon, 14 Sep 2026 09:12:00 +0200']]
		);

		$data = $this->controller->source('zaken', 'zaak', 'case-1', 'entry-a')->getData();

		$this->assertSame('Mon, 14 Sep 2026 09:12:00 +0200', $data['headers']['Date']);
	}

	public function testAnEntryWithNoInboundSourceAnswersNotFound(): void {
		$this->objectResolves();
		$this->entries->method('get')->willReturn($this->entry('internal'));
		$this->visibility->method('mayManage')->willReturn(true);
		$this->entries->method('sourceFor')->willReturn(['source' => null, 'headers' => []]);

		$this->assertSame(404, $this->controller->source('zaken', 'zaak', 'case-1', 'entry-a')->getStatus());
	}

	public function testAFieldThatDoesNotFitIsABadRequestNamingIt(): void {
		$this->objectResolves();
		$this->request->method('getParams')->willReturn(['message' => 'x', 'kind' => 'contactmoment']);
		$this->writer->method('write')
			->willThrowException(new TimelineValidationException(['channel' => 'This field is required']));

		$response = $this->controller->create('zaken', 'zaak', 'case-1');

		$this->assertSame(400, $response->getStatus());
		$this->assertArrayHasKey('channel', $response->getData()['errors']);
	}

	public function testAReaderCannotPin(): void {
		$this->objectResolves();
		$this->entries->method('get')->willReturn($this->entry());
		$this->request->method('getParams')->willReturn(['pinned' => true]);
		$this->entries->method('pin')->willThrowException(new TimelinePermissionException('no'));

		$this->assertSame(403, $this->controller->update('zaken', 'zaak', 'case-1', 'entry-a')->getStatus());
	}

	public function testPinningAnswersTheWrittenEntry(): void {
		$this->objectResolves();
		$this->entries->method('get')->willReturn($this->entry());
		$this->request->method('getParams')->willReturn(['pinned' => true]);

		$pinned = $this->entry();
		$pinned->setPinned(true);
		$pinned->setPinnedBy('supervisor');
		$this->entries->method('pin')->willReturn($pinned);

		$data = $this->controller->update('zaken', 'zaak', 'case-1', 'entry-a')->getData();

		$this->assertTrue($data['pinned']);
		$this->assertSame('supervisor', $data['pinnedBy']);
	}

	public function testARelatedObjectThatCannotBeReachedRefusesBeforeAnythingIsWritten(): void {
		$this->objects->method('getObject')->willReturnOnConsecutiveCalls($this->object(), null);
		$this->request->method('getParams')->willReturn(
			[
				'message' => 'afstemmingsverslag',
				'relatedObjects' => [['register' => 'zaken', 'schema' => 'zaak', 'id' => 'case-2']],
			]
		);
		// Nothing is written: a note that landed on three of the four objects
		// the author asked for is worse than a refusal naming the fourth.
		$this->writer->expects($this->never())->method('write');
		$this->writer->expects($this->never())->method('writeToMany');

		$this->assertSame(404, $this->controller->create('zaken', 'zaak', 'case-1')->getStatus());
	}

	public function testASearchWithoutATermIsABadRequest(): void {
		$this->request->method('getParams')->willReturn([]);
		$this->search->expects($this->never())->method('searchAsArrays');

		$this->assertSame(400, $this->controller->searchEntries()->getStatus());
	}

	public function testASearchPassesOnlyAKnownVisibility(): void {
		$this->request->method('getParams')->willReturn(['q' => 'Jansen', 'visibility' => 'intern']);
		$this->search->expects($this->once())->method('searchAsArrays')
			->with('Jansen', null, null, 25)
			->willReturn([]);

		$this->controller->searchEntries();
	}

	public function testASearchHandsBackTheHitsAndTheirCount(): void {
		$this->request->method('getParams')->willReturn(['q' => 'Jansen', 'visibility' => 'public', 'kind' => 'contactmoment']);
		$this->search->expects($this->once())->method('searchAsArrays')
			->with('Jansen', 'public', 'contactmoment', 25)
			->willReturn([['id' => 'e1'], ['id' => 'e2']]);

		$data = $this->controller->searchEntries()->getData();

		$this->assertSame(2, $data['total']);
	}
}
