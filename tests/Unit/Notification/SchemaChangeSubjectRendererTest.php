<?php

/**
 * SchemaChangeSubjectRenderer: the breaking-change notice in the recipient's language.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Notification
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Notification;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.
use OCA\OpenRegister\Notification\SchemaChangeSubjectRenderer;
use OCA\OpenRegister\Notification\SubjectRenderers;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\Notification\IAction;
use OCP\Notification\INotification;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Notification\SchemaChangeSubjectRenderer
 * @covers \OCA\OpenRegister\Notification\SubjectRenderers
 * @uses   \OCA\OpenRegister\Notification\SubjectRenderer
 * @uses   \OCA\OpenRegister\Notification\ArchivalSubjectRenderer
 * @uses   \OCA\OpenRegister\Notification\ReportSubjectRenderer
 * @uses   \OCA\OpenRegister\Notification\WorkSubjectRenderer
 * @uses   \OCA\OpenRegister\Notification\AccessSubjectRenderer
 * @uses   \OCA\OpenRegister\Notification\DelegationConsentRenderer
 * @uses   \OCA\OpenRegister\Notification\DisplayNames
 */
final class SchemaChangeSubjectRendererTest extends TestCase {

	/** @var array<string, mixed> */
	private array $parsed = [];

	private function urls(): IURLGenerator&MockObject {
		$urls = $this->createMock(IURLGenerator::class);
		$urls->method('linkToRouteAbsolute')->willReturnCallback(
			static fn (string $route, array $arguments): string => 'https://nc.example/' . $route . '/' . $arguments['id']
		);
		$urls->method('imagePath')->willReturn('/apps/openregister/img/app.svg');
		$urls->method('getAbsoluteURL')->willReturnArgument(0);
		return $urls;
	}//end urls()

	private function notification(string $subject, array $parameters): INotification {
		$notification = $this->createMock(INotification::class);
		$notification->method('getSubject')->willReturn($subject);
		$notification->method('getSubjectParameters')->willReturn($parameters);
		foreach (['setParsedSubject', 'setParsedMessage', 'setLink', 'setIcon'] as $setter) {
			$notification->method($setter)->willReturnCallback(
				function (string $value) use ($notification, $setter): INotification {
					$this->parsed[$setter] = $value;
					return $notification;
				}
			);
		}

		$action = $this->createMock(IAction::class);
		foreach (['setParsedLabel', 'setPrimary'] as $setter) {
			$action->method($setter)->willReturnCallback(
				function ($value) use ($action, $setter): IAction {
					$this->parsed['action.' . $setter] = $value;
					return $action;
				}
			);
		}

		$action->method('setLink')->willReturnCallback(
			function (string $link) use ($action): IAction {
				$this->parsed['action.link'] = $link;
				return $action;
			}
		);
		$notification->method('createAction')->willReturn($action);
		$notification->method('addParsedAction')->willReturnSelf();
		return $notification;
	}//end notification()

	/**
	 * A Dutch IL10N over the backend catalogue.
	 */
	private static function dutch(): IL10N {
		$catalogue = json_decode((string)file_get_contents(__DIR__ . '/../../../l10n/nl.json'), true)['translations'];
		$l = new class($catalogue) {
			public function __construct(private array $catalogue) {
			}

			public function t(string $text, array $parameters = []): string {
				return vsprintf(($this->catalogue[$text] ?? $text), $parameters);
			}
		};

		$mock = (new \PHPUnit\Framework\MockObject\Generator\Generator())->testDouble(IL10N::class, true, [], [], '', false);
		$mock->method('t')->willReturnCallback(static fn (string $text, array $parameters = []): string => $l->t($text, $parameters));
		return $mock;
	}//end dutch()

	private static function english(): IL10N {
		$mock = (new \PHPUnit\Framework\MockObject\Generator\Generator())->testDouble(IL10N::class, true, [], [], '', false);
		$mock->method('t')->willReturnCallback(static fn (string $text, array $parameters = []): string => vsprintf($text, $parameters));
		return $mock;
	}//end english()

	public function testASupplierReadsTheSchemaVersionNoteAndChangelogLink(): void {
		$notification = $this->notification('schema_breaking_change', ['schemaId' => 12, 'schema' => 'Melding', 'version' => '2.0.0', 'note' => 'Field toelichting is removed, use omschrijving']);

		$this->assertSame($notification, (new SchemaChangeSubjectRenderer($this->urls()))->render($notification, self::english()));

		$this->assertSame('Schema "Melding" changed in a way that can break your use of it (version 2.0.0)', $this->parsed['setParsedSubject']);
		$this->assertSame(
			'A breaking change to schema "Melding" was applied as version 2.0.0. Check the changelog before your next call. Note from the administrator: Field toelichting is removed, use omschrijving',
			$this->parsed['setParsedMessage']
		);
		$this->assertSame('https://nc.example/openregister.schemaMigration.changelog/12', $this->parsed['setLink']);
		$this->assertSame('https://nc.example/openregister.schemaMigration.changelog/12', $this->parsed['action.link']);
		$this->assertSame('Open changelog', $this->parsed['action.setParsedLabel']);
	}//end testASupplierReadsTheSchemaVersionNoteAndChangelogLink()

	public function testADutchRecipientReadsItInDutchAndWithoutANoteNoNoteLine(): void {
		$notification = $this->notification('schema_breaking_change', ['schemaId' => 12, 'schema' => 'Melding', 'version' => '2.0.0', 'note' => '']);

		(new SchemaChangeSubjectRenderer($this->urls()))->render($notification, self::dutch());

		$this->assertSame('Schema "Melding" is gewijzigd op een manier die jouw gebruik ervan kan breken (versie 2.0.0)', $this->parsed['setParsedSubject']);
		$this->assertSame('Een brekende wijziging van schema "Melding" is doorgevoerd als versie 2.0.0. Bekijk het wijzigingslogboek voor je volgende aanroep.', $this->parsed['setParsedMessage']);
		$this->assertSame('Wijzigingslogboek openen', $this->parsed['action.setParsedLabel']);
	}//end testADutchRecipientReadsItInDutchAndWithoutANoteNoNoteLine()

	public function testAnotherSubjectIsLeftToTheOtherRenderers(): void {
		$this->assertNull((new SchemaChangeSubjectRenderer($this->urls()))->render($this->notification('scheduled_report_failed', []), self::english()));
	}//end testAnotherSubjectIsLeftToTheOtherRenderers()

	public function testTheNotifierDispatchesTheSubjectToIt(): void {
		$notification = $this->notification('schema_breaking_change', ['schemaId' => 1, 'schema' => 'S', 'version' => '1.0.0']);

		(new SubjectRenderers($this->urls(), null))->render($notification, self::english());

		$this->assertSame('Schema "S" changed in a way that can break your use of it (version 1.0.0)', $this->parsed['setParsedSubject']);
	}//end testTheNotifierDispatchesTheSubjectToIt()
}//end class
