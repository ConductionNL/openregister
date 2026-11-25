<?php

/**
 * ScheduledReportService — the delivery mail is written in the owner's language.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service;

use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\ScheduledReport;
use OCA\OpenRegister\Db\ScheduledReportMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\ExportService;
use OCA\OpenRegister\Service\ScheduledReportDelivery;
use OCA\OpenRegister\Service\ScheduledReportExporters;
use OCA\OpenRegister\Service\ScheduledReportOwnerServices;
use OCA\OpenRegister\Service\ScheduledReportRun;
use OCA\OpenRegister\Service\ScheduledReportService;
use OCA\OpenRegister\Service\ScheduledReportStores;
use OCP\Files\IRootFolder;
use OCP\IConfig;
use OCP\IL10N;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\L10N\IFactory;
use OCP\Mail\IEMailTemplate;
use OCP\Mail\IMailer;
use OCP\Mail\IMessage;
use OCP\Notification\IManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;

/**
 * A Dutch owner's report mail reads Dutch, from the backend catalogue `l10n/nl.json`;
 * without a factory the mail stays the English source.
 *
 * @covers \OCA\OpenRegister\Service\ScheduledReportService
 * @uses \OCA\OpenRegister\Service\ScheduledReportDelivery
 * @uses \OCA\OpenRegister\Service\ScheduledReportExporters
 * @uses \OCA\OpenRegister\Service\ScheduledReportOwnerServices
 * @uses \OCA\OpenRegister\Service\ScheduledReportRun
 * @uses \OCA\OpenRegister\Service\ScheduledReportStores
 * @uses \OCA\OpenRegister\Db\Register
 * @uses \OCA\OpenRegister\Db\ScheduledReport
 * @uses \OCA\OpenRegister\Db\SchemaCatalog
 *
 * @spec openspec/changes/platform-provided-capabilities/tasks.md#2.5
 */
class ScheduledReportMailLanguageTest extends TestCase {

	/** @var list<string> The subjects set on the message. */
	private array $subjects = [];

	/** @var list<string> The body lines added to the template. */
	private array $lines = [];

	/** @var list<string> The languages the factory was asked for. */
	private array $asked = [];

	/**
	 * A translator over one of the app's backend catalogues, as Nextcloud's own reads it.
	 *
	 * @param string $locale The catalogue.
	 *
	 * @return IL10N
	 */
	private function catalogue(string $locale): IL10N {
		$translations = json_decode((string)file_get_contents(dirname(__DIR__, 3) . '/l10n/' . $locale . '.json'), true)['translations'];
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(
			static fn (string $text, array $parameters = []): string => vsprintf((string)($translations[$text] ?? $text), $parameters)
		);
		$l10n->method('n')->willReturnCallback(
			static function (string $singular, string $plural, int $count, array $parameters = []) use ($translations): string {
				$forms = ($translations['_' . $singular . '_::_' . $plural . '_'] ?? [$singular, $plural]);
				$form = $forms[($count === 1) ? 0 : 1];
				return vsprintf(str_replace('%n', (string)$count, $form), $parameters);
			}
		);
		return $l10n;
	}//end catalogue()

	/**
	 * The service, with a factory that answers the owner's language, or without one.
	 *
	 * @param string|null $ownerLanguage The owner's language, or null for no factory.
	 *
	 * @return ScheduledReportService
	 */
	private function service(?string $ownerLanguage): ScheduledReportService {
		$mailer = $this->createMock(IMailer::class);
		$template = $this->createMock(IEMailTemplate::class);
		$template->method('addBodyText')->willReturnCallback(function (string $text): void {
			$this->lines[] = $text;
		});
		$mailer->method('createEMailTemplate')->willReturn($template);
		$message = $this->createMock(IMessage::class);
		$message->method('setSubject')->willReturnCallback(function (string $subject) use ($message): IMessage {
			$this->subjects[] = $subject;
			return $message;
		});
		$mailer->method('createMessage')->willReturn($message);
		$config = $this->createMock(IConfig::class);
		$config->method('getSystemValue')->willReturnCallback(static fn ($key, $default = null) => $default);

		$factory = null;
		if ($ownerLanguage !== null) {
			$factory = $this->createMock(IFactory::class);
			$factory->method('getUserLanguage')->willReturn($ownerLanguage);
			$factory->method('get')->willReturnCallback(function (string $app, ?string $lang = null): IL10N {
				$this->asked[] = $app . '/' . $lang;
				return $this->catalogue(locale: (string)$lang);
			});
		}

		$register = new Register();
		$register->setTitle('Zaken');
		$registers = $this->createMock(RegisterMapper::class);
		$registers->method('find')->willReturn($register);

		return new ScheduledReportService(
			new ScheduledReportStores(
				$this->createMock(ScheduledReportMapper::class),
				new \OCA\OpenRegister\Db\SchemaCatalog(registers: $registers, schemas: $this->createMock(SchemaMapper::class), tables: $this->createMock(\OCA\OpenRegister\Db\MagicMapperInterface::class))
			),
			new ScheduledReportExporters($this->createMock(ExportService::class), null, null),
			new ScheduledReportDelivery(
				new ScheduledReportOwnerServices($this->createMock(IUserManager::class), $this->createMock(IUserSession::class), $this->createMock(IRootFolder::class), $this->createMock(IManager::class)),
				$mailer,
				$config,
				null,
				$factory
			),
			$this->createMock(LoggerInterface::class)
		);
	}//end service()

	/**
	 * Compose the delivery mail of a two-row run of "Wekelijkse zaken".
	 *
	 * @param ScheduledReportService $service The service.
	 *
	 * @return void
	 */
	private function compose(ScheduledReportService $service): void {
		$report = new ScheduledReport();
		$report->setName('Wekelijkse zaken');
		$report->setRegisterId(3);
		$report->setScheduleType('weekly');
		$report->setFormat('csv');
		$owner = $this->createMock(IUser::class);
		$owner->method('getUID')->willReturn('fatima');
		$run = new ScheduledReportRun(report: $report, owner: $owner);
		$run->export = ['bytes' => "id\n1\n2\n", 'rowCount' => 2];
		$run->filename = 'weekly.csv';

		(new ReflectionMethod($service, 'composeEmail'))->invoke($service, $run, ['fatima@example.nl' => 'Fatima']);
	}//end compose()

	public function testADutchOwnerGetsTheDutchSubjectAndBody(): void {
		$this->compose(service: $this->service(ownerLanguage: 'nl'));

		$this->assertSame(['openregister/nl', 'openregister/nl'], $this->asked);
		$this->assertSame(['Geplande rapportage "Wekelijkse zaken"'], $this->subjects);
		$this->assertSame(
			[
				'Je geplande rapportage "Wekelijkse zaken" is uitgevoerd en leverde 2 rijen op.',
				"Bron: Zaken / alle schema's.",
				'Planning: weekly.',
			],
			$this->lines
		);
	}//end testADutchOwnerGetsTheDutchSubjectAndBody()

	public function testAnEnglishOwnerGetsTheSource(): void {
		$this->compose(service: $this->service(ownerLanguage: 'en'));

		$this->assertSame(['Scheduled report "Wekelijkse zaken"'], $this->subjects);
		$this->assertSame('Your scheduled report "Wekelijkse zaken" ran and delivered 2 rows.', $this->lines[0]);
	}//end testAnEnglishOwnerGetsTheSource()

	public function testWithoutAFactoryTheMailStaysEnglish(): void {
		$this->compose(service: $this->service(ownerLanguage: null));

		$this->assertSame(['Scheduled report "Wekelijkse zaken"'], $this->subjects);
		$this->assertSame(
			['Your scheduled report "Wekelijkse zaken" ran and delivered 2 rows.', 'Source: Zaken / all schemas.', 'Schedule: weekly.'],
			$this->lines
		);
	}//end testWithoutAFactoryTheMailStaysEnglish()
}//end class
