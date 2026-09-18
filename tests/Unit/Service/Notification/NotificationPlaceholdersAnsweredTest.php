<?php

/**
 * A placeholder nothing answers is left in the text, and no shipped template
 * names one.
 *
 * 🔴 THIS SUBSYSTEM HAD BOTH FAILURE MODES AT ONCE, AND WHICH ONE A READER GOT
 * DEPENDED ON WHETHER AN ADMINISTRATOR HAD EDITED THE TEMPLATE.
 * `NotificationTemplateRegistry::interpolate()` left an unknown key alone;
 * `NotificationTemplating::interpolate()` rendered it as an empty string. Two
 * evaluators for the same kind of text in the same subsystem, disagreeing about
 * the same question.
 *
 * 🔴 AND BLANKING IS THE WORSE OF THE TWO. `Bewaartermijn: {{skippedCount}}
 * records overgeslagen` became "Bewaartermijn:  records overgeslagen" — a
 * sentence with a hole, which reads as clumsy writing rather than as a defect.
 * Nobody reports clumsy writing. `{{skippedCount}}` left in the text announces
 * itself the first time anybody reads it, which is exactly how dossiq#2950
 * found six mail templates that had been wrong for 35 days.
 *
 * So the rule here is: leave it, and make sure nothing shipped needs to.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Notification
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/notification-placeholders-refuse/specs/notificatie-engine/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Notification;

use OCA\OpenRegister\Service\Notification\NotificationTemplateRegistry;
use OCA\OpenRegister\Service\Notification\NotificationTemplating;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use ReflectionClass;

/**
 * The evaluator's rule, and the shipped templates held to it.
 *
 * @covers \OCA\OpenRegister\Service\Notification\NotificationTemplating
 */
class NotificationPlaceholdersAnsweredTest extends TestCase {

	/**
	 * The evaluator under test.
	 *
	 * @return NotificationTemplating The evaluator.
	 */
	private function templating(): NotificationTemplating {
		return new NotificationTemplating(new NullLogger());
	}//end templating()

	/**
	 * An unanswered key stays in the text rather than becoming a hole.
	 *
	 * @return void
	 */
	public function testAnUnansweredKeyIsLeftInTheText(): void {
		$rendered = $this->templating()->interpolate(
			template: 'Bewaartermijn: {{skippedCount}} records overgeslagen',
			data: [],
			context: []
		);

		// 🔴 THE ASSERTION THIS FILE EXISTS FOR. The old behaviour produced
		// "Bewaartermijn:  records overgeslagen", which reads as a typo.
		$this->assertStringContainsString('{{skippedCount}}', $rendered);
		$this->assertStringNotContainsString(
			'Bewaartermijn:  records',
			$rendered,
			'a hole in the sentence is what nobody reports'
		);
	}//end testAnUnansweredKeyIsLeftInTheText()

	/**
	 * An answered key still renders, from data and from context.
	 *
	 * The control. Without it, "the placeholder is still there" could mean
	 * nothing is ever interpolated at all.
	 *
	 * @return void
	 */
	public function testAnAnsweredKeyStillRenders(): void {
		$templating = $this->templating();

		$this->assertSame(
			'Bewaartermijn: 12 records overgeslagen',
			$templating->interpolate(
				template: 'Bewaartermijn: {{skippedCount}} records overgeslagen',
				data: ['skippedCount' => 12],
				context: []
			)
		);

		// Context answers too, and data wins over context.
		$this->assertSame(
			'a',
			$templating->interpolate(template: '{{k}}', data: ['k' => 'a'], context: ['k' => 'b'])
		);
		$this->assertSame(
			'b',
			$templating->interpolate(template: '{{k}}', data: [], context: ['k' => 'b'])
		);
	}//end testAnAnsweredKeyStillRenders()

	/**
	 * A non-scalar is unanswered too, not silently blank.
	 *
	 * @return void
	 */
	public function testANonScalarIsUnansweredRatherThanBlank(): void {
		$this->assertSame(
			'{{k}}',
			$this->templating()->interpolate(
				template: '{{k}}',
				data: ['k' => ['not', 'scalar']],
				context: []
			)
		);
	}//end testANonScalarIsUnansweredRatherThanBlank()

	/**
	 * The evaluator can say which keys it could not answer.
	 *
	 * @return void
	 */
	public function testItCanSayWhatItCouldNotAnswer(): void {
		$templating = $this->templating();

		// In the order they APPEAR, which is what the method documents and what
		// a reader fixing them would work through.
		$this->assertSame(
			['target', 'reason'],
			$templating->unanswered(
				template: 'De overdracht naar {{target}} stopte: {{reason}}. Zaak {{known}}.',
				data: ['known' => '2026-0042'],
				context: []
			)
		);

		// The control: a template everything answers reports nothing.
		$this->assertSame(
			[],
			$templating->unanswered(template: 'Zaak {{known}}.', data: ['known' => 'x'], context: [])
		);
	}//end testItCanSayWhatItCouldNotAnswer()

	/**
	 * `unanswered()` agrees with `interpolate()` about every key.
	 *
	 * A guard that asked a different question than the renderer answers is how
	 * a guard comes to disagree with the thing it guards.
	 *
	 * @return void
	 */
	public function testTheGuardAgreesWithTheRenderer(): void {
		$templating = $this->templating();
		$template = '{{scalar}} {{nonScalar}} {{fromContext}} {{nobody}}';
		$data = ['scalar' => 'a', 'nonScalar' => ['x']];
		$context = ['fromContext' => 'c'];

		$rendered = $templating->interpolate(template: $template, data: $data, context: $context);

		foreach ($templating->unanswered(template: $template, data: $data, context: $context) as $key) {
			$this->assertStringContainsString(
				'{{' . $key . '}}',
				$rendered,
				sprintf('unanswered() named {{%s}}, so interpolate() must have left it', $key)
			);
		}

		// And the other direction: nothing it left is absent from the list.
		preg_match_all('/\{\{\s*([a-zA-Z0-9_.-]+)\s*\}\}/', $rendered, $left);
		$this->assertSame(
			$templating->unanswered(template: $template, data: $data, context: $context),
			array_values(array_unique($left[1]))
		);
	}//end testTheGuardAgreesWithTheRenderer()

	/**
	 * No shipped template names a key its own event never supplies.
	 *
	 * 🔴 BOTH SIDES DERIVED. The placeholders are read out of `SHIPPED`, and
	 * the answerable names out of the `variables` each event declares beside
	 * it. A list written into this test would be a third copy of the same
	 * knowledge and would drift from both.
	 *
	 * @return void
	 */
	public function testNoShippedTemplateNamesAnUnsuppliedKey(): void {
		$reflection = new ReflectionClass(NotificationTemplateRegistry::class);
		$shipped = $reflection->getConstant('SHIPPED');
		$declared = $reflection->getConstant('EVENTS');

		$this->assertIsArray($shipped);
		$this->assertGreaterThan(
			10,
			count($shipped),
			'too few shipped templates were read for this to check anything'
		);

		$broken = [];
		foreach ($shipped as $event => $locales) {
			$answerable = array_keys(($declared[$event]['variables'] ?? []));
			foreach ($locales as $locale => $text) {
				if (is_array($text) === false) {
					continue;
				}

				$body = (string)($text['subject'] ?? '') . ' ' . (string)($text['body'] ?? '');
				preg_match_all('/\{\{\s*([a-zA-Z0-9_.-]+)\s*\}\}/', $body, $names);
				foreach (array_unique($names[1]) as $name) {
					if (in_array($name, $answerable, true) === false) {
						$broken[] = sprintf('%s[%s] names {{%s}}', $event, $locale, $name);
					}
				}
			}
		}

		sort($broken);
		$this->assertSame(
			[],
			$broken,
			"A shipped template naming a key its event does not supply reaches the reader as "
			. "itself. Either supply the key where the notification is raised, or stop naming "
			. "it in the text:\n  " . implode("\n  ", $broken)
		);
	}//end testNoShippedTemplateNamesAnUnsuppliedKey()
}//end class
