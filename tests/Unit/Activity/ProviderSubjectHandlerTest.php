<?php

/**
 * ProviderSubjectHandler Unit Test
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Activity
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

namespace OCA\OpenRegister\Tests\Unit\Activity;

use OCA\OpenRegister\Activity\ProviderSubjectHandler;
use OCP\Activity\IEvent;
use OCP\IL10N;
use OCP\L10N\IFactory;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for ProviderSubjectHandler.
 */
class ProviderSubjectHandlerTest extends TestCase {
	/**
	 * A handler whose l10n factory hands out the given translator.
	 */
	private function handlerTranslatingWith(IL10N $l): ProviderSubjectHandler {
		$factory = $this->createMock(IFactory::class);
		$factory->method('get')->with('openregister', 'en')->willReturn($l);
		return new ProviderSubjectHandler($factory);
	}

	/**
	 * Create a mock l10n that returns the string as-is with sprintf applied.
	 */
	private function mockL10n(): IL10N {
		$l = $this->createMock(IL10N::class);
		$l->method('t')->willReturnCallback(function (string $text, array $params = []) {
			return vsprintf($text, $params) ?: $text;
		});
		return $l;
	}

	/**
	 * Test: applySubjectText sets parsed and rich subjects for object_created.
	 */
	public function testApplySubjectTextObjectCreated(): void {
		$l = $this->mockL10n();
		$event = $this->createMock(IEvent::class);
		$event->method('getSubject')->willReturn('object_created');
		$event->method('getObjectId')->willReturn(42);

		$event->expects($this->once())->method('setParsedSubject')->with('Object created: My Object');
		$event->expects($this->once())->method('setRichSubject');

		$event->method('getSubjectParameters')->willReturn(['title' => 'My Object']);
		$this->handlerTranslatingWith($l)->applySubjectText($event, 'en');
	}

	/**
	 * Test: applySubjectText sets parsed subject for register_deleted.
	 */
	public function testApplySubjectTextRegisterDeleted(): void {
		$l = $this->mockL10n();
		$event = $this->createMock(IEvent::class);
		$event->method('getSubject')->willReturn('register_deleted');
		$event->method('getObjectId')->willReturn(10);

		$event->expects($this->once())->method('setParsedSubject')->with('Register deleted: Test Reg');

		$event->method('getSubjectParameters')->willReturn(['title' => 'Test Reg']);
		$this->handlerTranslatingWith($l)->applySubjectText($event, 'en');
	}

	/**
	 * Test: applySubjectText handles empty title gracefully.
	 */
	public function testApplySubjectTextEmptyTitle(): void {
		$l = $this->mockL10n();
		$event = $this->createMock(IEvent::class);
		$event->method('getSubject')->willReturn('schema_updated');
		$event->method('getObjectId')->willReturn(20);

		$event->expects($this->once())->method('setParsedSubject')->with('Schema updated: ');

		$event->method('getSubjectParameters')->willReturn([]);
		$this->handlerTranslatingWith($l)->applySubjectText($event, 'en');
	}

	/**
	 * Test: applySubjectText builds correct rich parameters.
	 */
	public function testApplySubjectTextBuildRichParams(): void {
		$l = $this->mockL10n();
		$event = $this->createMock(IEvent::class);
		$event->method('getSubject')->willReturn('object_created');
		$event->method('getObjectId')->willReturn(99);

		$event->expects($this->once())->method('setRichSubject')->with(
			$this->anything(),
			[
				'title' => [
					'type' => 'highlight',
					'id' => '99',
					'name' => 'Rich Test',
				],
			]
		);

		$event->method('getSubjectParameters')->willReturn(['title' => 'Rich Test']);
		$this->handlerTranslatingWith($l)->applySubjectText($event, 'en');
	}
	/**
	 * Test: an object update that carries the schema title names schema and object.
	 *
	 * @spec openspec/specs/activity-provider/spec.md#requirement-an-object-activity-names-the-schema-and-the-object
	 */
	public function testObjectUpdatedWithSchemaNamesSchemaAndObject(): void {
		$l = $this->mockL10n();
		$event = $this->createMock(IEvent::class);
		$event->method('getSubject')->willReturn('object_updated');
		$event->method('getObjectId')->willReturn(6);

		$event->expects($this->once())->method('setParsedSubject')->with('Client Gemeente Demo updated');
		$event->expects($this->once())->method('setRichSubject')->with(
			'{schema} {title} updated',
			$this->callback(
				static function (array $rich): bool {
					return $rich['schema']['name'] === 'Client' && $rich['title']['name'] === 'Gemeente Demo';
				}
			)
		);

		$event->method('getSubjectParameters')->willReturn(['title' => 'Gemeente Demo', 'schema' => 'Client']);
		$this->handlerTranslatingWith($l)->applySubjectText($event, 'en');
	}

	/**
	 * Test: without a schema title the object update keeps its old form.
	 */
	public function testObjectUpdatedWithoutSchemaKeepsOldForm(): void {
		$l = $this->mockL10n();
		$event = $this->createMock(IEvent::class);
		$event->method('getSubject')->willReturn('object_updated');
		$event->method('getObjectId')->willReturn(6);

		$event->expects($this->once())->method('setParsedSubject')->with('Object updated: Gemeente Demo');

		$event->method('getSubjectParameters')->willReturn(['title' => 'Gemeente Demo']);
		$this->handlerTranslatingWith($l)->applySubjectText($event, 'en');
	}

	/**
	 * Test: a schema parameter on a register subject changes nothing.
	 */
	public function testRegisterSubjectIgnoresSchemaParameter(): void {
		$l = $this->mockL10n();
		$event = $this->createMock(IEvent::class);
		$event->method('getSubject')->willReturn('register_updated');
		$event->method('getObjectId')->willReturn(20);

		$event->expects($this->once())->method('setParsedSubject')->with('Register updated: CRM');

		$event->method('getSubjectParameters')->willReturn(['title' => 'CRM', 'schema' => 'Client']);
		$this->handlerTranslatingWith($l)->applySubjectText($event, 'en');
	}
}
