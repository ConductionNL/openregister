<?php

/**
 * The request alert notifications (production-observability, "Error rate threshold", "Response time threshold").
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Notification
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 *
 * @spec openspec/changes/production-observability/specs/production-observability/spec.md#requirement-alerting-threshold-configuration
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Notification;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Notification\ObservabilitySubjectRenderer;
use OCA\OpenRegister\Notification\SubjectRenderers;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\Notification\INotification;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Notification\ObservabilitySubjectRenderer
 * @uses \OCA\OpenRegister\Notification\SubjectRenderer
 * @uses \OCA\OpenRegister\Notification\SubjectRenderers
 * @uses \OCA\OpenRegister\Notification\DisplayNames
 * @uses \OCA\OpenRegister\Notification\ArchivalSubjectRenderer
 * @uses \OCA\OpenRegister\Notification\ReportSubjectRenderer
 * @uses \OCA\OpenRegister\Notification\WorkSubjectRenderer
 * @uses \OCA\OpenRegister\Notification\AccessSubjectRenderer
 * @uses \OCA\OpenRegister\Notification\DelegationConsentRenderer
 * @uses \OCA\OpenRegister\Notification\SchemaChangeSubjectRenderer
 */
final class ObservabilitySubjectRendererTest extends TestCase {

	/**
	 * What the renderer set.
	 *
	 * @var array<string, string>
	 */
	private array $parsed = [];

	public function testAnErrorRateAlertNamesTheRateTheWindowAndTheThreshold(): void {
		$notification = $this->notification(
			subject: 'observability_error_rate',
			parameters: ['ratePercent' => 6.0, 'thresholdPercent' => 5, 'windowMinutes' => 5]
		);

		$this->assertSame($notification, (new ObservabilitySubjectRenderer($this->urls()))->render($notification, self::english()));
		$this->assertSame('OpenRegister error rate is over its alert threshold', $this->parsed['setParsedSubject']);
		$this->assertSame(
			'6% of the requests in the last 5 minutes failed with a server error; the threshold is 5%.',
			$this->parsed['setParsedMessage']
		);
		$this->assertSame('https://nc.example/apps/openregister/img/app.svg', $this->parsed['setIcon']);
	}//end testAnErrorRateAlertNamesTheRateTheWindowAndTheThreshold()

	public function testAResponseTimeAlertNamesTheP95TheWindowAndTheThreshold(): void {
		$notification = $this->notification(
			subject: 'observability_response_time',
			parameters: ['p95Ms' => 3750, 'thresholdMs' => 3000, 'windowMinutes' => 5]
		);

		(new ObservabilitySubjectRenderer($this->urls()))->render($notification, self::english());

		$this->assertSame('OpenRegister response time is over its alert threshold', $this->parsed['setParsedSubject']);
		$this->assertSame(
			'The 95th percentile response time in the last 5 minutes was 3750 ms; the threshold is 3000 ms.',
			$this->parsed['setParsedMessage']
		);
	}//end testAResponseTimeAlertNamesTheP95TheWindowAndTheThreshold()

	public function testAnotherSubjectIsLeftToAnotherRenderer(): void {
		$notification = $this->notification(subject: 'object_created', parameters: []);
		$this->assertNull((new ObservabilitySubjectRenderer($this->urls()))->render($notification, self::english()));
	}//end testAnotherSubjectIsLeftToAnotherRenderer()

	public function testTheNotifierRendersTheAlertInDutch(): void {
		$notification = $this->notification(
			subject: 'observability_error_rate',
			parameters: ['ratePercent' => 6.0, 'thresholdPercent' => 5, 'windowMinutes' => 5]
		);

		(new SubjectRenderers(urlGenerator: $this->urls(), userManager: null))->render(notification: $notification, l: self::dutch());

		$this->assertSame('Foutpercentage van OpenRegister is boven de alarmdrempel', $this->parsed['setParsedSubject']);
		$this->assertSame(
			'6% van de verzoeken in de afgelopen 5 minuten is mislukt met een serverfout; de drempel is 5%.',
			$this->parsed['setParsedMessage']
		);
	}//end testTheNotifierRendersTheAlertInDutch()

	private function urls(): IURLGenerator {
		$urls = $this->createMock(IURLGenerator::class);
		$urls->method('imagePath')->willReturn('/apps/openregister/img/app.svg');
		$urls->method('getAbsoluteURL')->willReturnCallback(static fn (string $path): string => 'https://nc.example'.$path);
		return $urls;
	}//end urls()

	/**
	 * @param string               $subject    The subject.
	 * @param array<string, mixed> $parameters Its parameters.
	 *
	 * @return INotification
	 */
	private function notification(string $subject, array $parameters): INotification {
		$notification = $this->createMock(INotification::class);
		$notification->method('getSubject')->willReturn($subject);
		$notification->method('getSubjectParameters')->willReturn($parameters);
		foreach (['setParsedSubject', 'setParsedMessage', 'setIcon'] as $setter) {
			$notification->method($setter)->willReturnCallback(
				function (string $value) use ($notification, $setter): INotification {
					$this->parsed[$setter] = $value;
					return $notification;
				}
			);
		}

		return $notification;
	}//end notification()

	private static function english(): IL10N {
		return self::l10n(catalogue: []);
	}//end english()

	private static function dutch(): IL10N {
		$catalogue = json_decode((string) file_get_contents(__DIR__.'/../../../l10n/nl.json'), true);
		return self::l10n(catalogue: $catalogue['translations']);
	}//end dutch()

	/**
	 * @param array<string, string> $catalogue The translations.
	 *
	 * @return IL10N
	 */
	private static function l10n(array $catalogue): IL10N {
		$mock = (new \PHPUnit\Framework\MockObject\Generator\Generator())->testDouble(IL10N::class, true, [], [], '', false);
		$mock->method('t')->willReturnCallback(
			static fn (string $text, array $parameters = []): string => vsprintf(($catalogue[$text] ?? $text), $parameters)
		);
		return $mock;
	}//end l10n()

	/**
	 * The renderer's subjects are the ones the alert evaluator sends.
	 *
	 * @return void
	 */
	public function testTheSubjectsMatchTheAlertEvaluator(): void {
		$this->assertSame(\OCA\OpenRegister\Service\Observability\RequestAlertEvaluator::SUBJECT_ERROR_RATE, \OCA\OpenRegister\Notification\ObservabilitySubjectRenderer::SUBJECT_ERROR_RATE);
		$this->assertSame(\OCA\OpenRegister\Service\Observability\RequestAlertEvaluator::SUBJECT_RESPONSE_TIME, \OCA\OpenRegister\Notification\ObservabilitySubjectRenderer::SUBJECT_RESPONSE_TIME);
	}//end testTheSubjectsMatchTheAlertEvaluator()

}//end class
