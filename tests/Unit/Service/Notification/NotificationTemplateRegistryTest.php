<?php

declare(strict_types=1);

namespace Unit\Service\Notification;

use OCA\OpenRegister\Service\Notification\NotificationTemplateRegistry;
use OCP\IConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Covers the shipped template set, the editable half, and the gap list.
 *
 * The gap list is the part worth testing hardest, because a gap list derived
 * from the template set can only ever be empty and would pass every assertion
 * anybody thought to write. So the inventory and the texts are injected
 * separately here, and one test declares an event nobody wrote a template for.
 */
class NotificationTemplateRegistryTest extends TestCase {
	private IConfig&MockObject $config;

	/**
	 * Stored app values, keyed by config key.
	 *
	 * @var array<string, string>
	 */
	private array $appValues = [];

	protected function setUp(): void {
		parent::setUp();
		$this->config = $this->createMock(IConfig::class);
		$this->config->method('getAppValue')->willReturnCallback(
			fn (string $app, string $key, string $default = ''): string => ($this->appValues[$key] ?? $default)
		);
		$this->config->method('setAppValue')->willReturnCallback(
			function (string $app, string $key, string $value): void {
				$this->appValues[$key] = $value;
			}
		);
		$this->config->method('deleteAppValue')->willReturnCallback(
			function (string $app, string $key): void {
				unset($this->appValues[$key]);
			}
		);
	}

	/**
	 * The platform's own registry, with everything it ships.
	 *
	 * @return NotificationTemplateRegistry The registry.
	 */
	private function shipped(): NotificationTemplateRegistry {
		return new NotificationTemplateRegistry($this->config);
	}

	/**
	 * Every event the platform raises has a template as shipped.
	 */
	public function testTheShippedSetHasNoGaps(): void {
		$this->assertSame([], $this->shipped()->gaps());
	}

	/**
	 * An event with no template is named, rather than rendering a generic line.
	 */
	public function testAnEventWithNoTemplateIsNamed(): void {
		$registry = new NotificationTemplateRegistry(
			$this->config,
			[
				'thing_happened' => ['group' => 'test', 'variables' => ['what' => 'What happened']],
				'other_thing_happened' => ['group' => 'test', 'variables' => []],
			],
			[
				'thing_happened' => ['en' => ['subject' => '{{what}}', 'body' => '{{what}} happened.']],
			]
		);

		$this->assertSame(['other_thing_happened'], $registry->gaps());
		$this->assertNull($registry->render(event: 'other_thing_happened', locale: 'en'));
	}

	/**
	 * The listing says, per event, whether it has anything to say.
	 */
	public function testTheListingReportsWhichEventsHaveATemplate(): void {
		$registry = new NotificationTemplateRegistry(
			$this->config,
			[
				'thing_happened' => ['group' => 'test', 'variables' => []],
				'other_thing_happened' => ['group' => 'test', 'variables' => []],
			],
			[
				'thing_happened' => ['en' => ['subject' => 'Something', 'body' => 'Something happened.']],
			]
		);

		$rows = $registry->listAll();
		$byEvent = array_column($rows, null, 'event');

		$this->assertTrue($byEvent['thing_happened']['hasTemplate']);
		$this->assertSame('shipped', $byEvent['thing_happened']['source']);
		$this->assertFalse($byEvent['other_thing_happened']['hasTemplate']);
		$this->assertSame('none', $byEvent['other_thing_happened']['source']);
	}

	/**
	 * An administrator's edit is the text that gets used.
	 */
	public function testAnEditedTemplateIsTheOneRendered(): void {
		$registry = $this->shipped();

		$registry->edit(
			event: 'destruction_review_pending',
			template: ['en' => ['subject' => 'Review {{pendingCount}} records', 'body' => 'On {{schemaSlug}}.']]
		);

		$rendered = $registry->render(
			event: 'destruction_review_pending',
			locale: 'en',
			variables: ['pendingCount' => 4, 'schemaSlug' => 'zaak']
		);

		$this->assertSame('Review 4 records', $rendered['subject']);
		$this->assertSame('On zaak.', $rendered['body']);
		$this->assertTrue($registry->hasEdit(event: 'destruction_review_pending'));
	}

	/**
	 * Clearing an edit restores the shipped words.
	 */
	public function testClearingAnEditRestoresTheShippedText(): void {
		$registry = $this->shipped();
		$before = $registry->render(event: 'scheduled_report_failed', locale: 'en', variables: ['report' => 'Q3']);

		$registry->edit(
			event: 'scheduled_report_failed',
			template: ['en' => ['subject' => 'Nope', 'body' => 'Nope.']]
		);
		$registry->edit(event: 'scheduled_report_failed', template: null);

		$after = $registry->render(event: 'scheduled_report_failed', locale: 'en', variables: ['report' => 'Q3']);

		$this->assertSame($before, $after);
		$this->assertFalse($registry->hasEdit(event: 'scheduled_report_failed'));
	}

	/**
	 * Editing something the platform does not raise is refused, rather than
	 * stored under a name nothing will ever look up.
	 */
	public function testEditingAnUnknownEventIsRefused(): void {
		$this->expectException(\InvalidArgumentException::class);
		$this->shipped()->edit(
			event: 'not_an_event',
			template: ['en' => ['subject' => 'x', 'body' => 'y']]
		);
	}

	/**
	 * A locale the template does not carry falls back to the default one,
	 * rather than answering "no template" for text that plainly exists.
	 */
	public function testAnUnknownLocaleFallsBackRatherThanReportingAGap(): void {
		$rendered = $this->shipped()->render(
			event: 'credential_relink_needed',
			locale: 'de',
			variables: ['connection' => 'Zaaksysteem']
		);

		$this->assertNotNull($rendered);
		$this->assertStringContainsString('Zaaksysteem', $rendered['subject']);
	}

	/**
	 * A placeholder with no value is left standing, so a message that is
	 * missing something says which thing rather than leaving a hole.
	 */
	public function testAMissingVariableLeavesItsPlaceholderVisible(): void {
		$rendered = $this->shipped()->render(event: 'handoff_drain_failed', locale: 'en', variables: []);

		$this->assertStringContainsString('{{target}}', $rendered['subject']);
	}

	/**
	 * Every shipped template documents its variables, and every variable a
	 * template uses is one the inventory declares. A template interpolating a
	 * name nobody documented renders a literal `{{...}}` to a real person.
	 */
	public function testEveryPlaceholderUsedIsADocumentedVariable(): void {
		foreach ($this->shipped()->listAll() as $row) {
			$documented = array_keys($row['variables']);
			foreach (($row['shipped'] ?? []) as $locale => $text) {
				$used = [];
				preg_match_all('/\{\{\s*([a-zA-Z0-9_.-]+)\s*\}\}/', ($text['subject'] . ' ' . $text['body']), $used);
				foreach ($used[1] as $name) {
					$this->assertContains(
						$name,
						$documented,
						sprintf('%s/%s uses {{%s}}, which the event does not document', $row['event'], $locale, $name)
					);
				}
			}
		}
	}
}
