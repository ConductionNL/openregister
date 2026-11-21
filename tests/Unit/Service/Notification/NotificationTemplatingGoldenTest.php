<?php

/**
 * Golden test for NotificationTemplating.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Notification
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/notificatie-engine/spec.md
 */

declare(strict_types=1);

namespace Unit\Service\Notification;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCA\OpenRegister\Notifications\Service\Notification\NotificationTemplating;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;
use OCA\OpenRegister\Notifications\Service\Notification\PlaceholderValues;

/**
 * Pins interpolate(), unanswered() and translatedValue() over a fixed corpus,
 * with an object resolver that names one UUID, leaves one nameless, misses one
 * and throws on one. Expected values were recorded from the code before its
 * refactor and live in `fixtures/notification-templating-golden.json`.
 *
 * @coversDefaultClass \OCA\OpenRegister\Notifications\Service\Notification\NotificationTemplating
 */
class NotificationTemplatingGoldenTest extends TestCase {

	private const NAMED = '11111111-1111-4111-8111-111111111111';

	private const NAMELESS = '22222222-2222-4222-8222-222222222222';

	private const MISSING = '33333333-3333-4333-8333-333333333333';

	private const THROWS = '44444444-4444-4444-8444-444444444444';

	/**
	 * Path of the golden file.
	 *
	 * @return string
	 */
	public static function goldenPath(): string {
		return __DIR__.'/fixtures/notification-templating-golden.json';
	}//end goldenPath()

	/**
	 * Values fed to translatedValue() and used as data/context.
	 *
	 * @return array<string, mixed>
	 */
	public static function values(): array {
		return [
			'string'          => 'Hello',
			'empty'           => '',
			'int'             => 42,
			'zero'            => 0,
			'float'           => 1.5,
			'true'            => true,
			'false'           => false,
			'null'            => null,
			'list'            => ['a', 'b'],
			'empty-array'     => [],
			'nested'          => ['nl' => ['x']],
			'map-nl-en'       => ['nl' => 'Bel klant', 'en' => 'Call client'],
			'map-en-only'     => ['en' => 'Call'],
			'map-regional'    => ['en-GB' => 'Colour', 'en' => 'Color'],
			'map-underscore'  => ['pt_BR' => 'Olá'],
			'map-blank-first' => ['nl' => '', 'de' => null, 'en' => 'Fallback'],
			'map-all-blank'   => ['nl' => '', 'en' => null],
			'map-false'       => ['nl' => false, 'en' => 'x'],
			'map-zero'        => ['nl' => 0],
			'map-bad-key'     => ['nl' => 'a', 'English' => 'b'],
			'map-int-key'     => ['nl' => 'a', 5 => 'b'],
			'map-upper'       => ['NL' => 'a'],
			'map-four'        => ['abcd' => 'a'],
			'map-long-region' => ['zh-Hant-TW' => 'a'],
			'map-html'        => ['nl' => '<b>"x" & y</b>'],
			'uuid-named'      => self::NAMED,
			'uuid-nameless'   => self::NAMELESS,
			'uuid-missing'    => self::MISSING,
			'uuid-throws'     => self::THROWS,
			'uuid-upper'      => strtoupper(self::NAMED),
			'html'            => '<i>it\'s</i>',
		];
	}//end values()

	/**
	 * Language chains.
	 *
	 * @return array<string, array<int, string|null>>
	 */
	public static function chains(): array {
		return [
			'none'     => [],
			'nl'       => ['nl'],
			'en-GB'    => ['en-GB'],
			'en_US'    => ['en_US', 'nl'],
			'de-nl'    => ['de', null, '', 'nl'],
			'pt-BR'    => ['pt-BR', 'pt_BR'],
			'zh'       => ['ZH-hant'],
		];
	}//end chains()

	/**
	 * Templates fed to interpolate() and unanswered().
	 *
	 * @return array<string, string>
	 */
	public static function templates(): array {
		$keys = array_keys(self::values());
		$all  = implode(' | ', array_map(static fn (string $key): string => '{{ '.$key.' }}', $keys));

		return [
			'all'        => $all,
			'spacing'    => '{{string}} {{  int  }} {{ctx-only}} {{ string}}',
			'unknown'    => 'a {{nope}} b {{nope}} {{ other.key }} {{bad key}} {{}}',
			'no-holes'   => 'plain text',
			'ctx-wins?'  => '{{shared}} {{ctx-only}} {{ctx-list}} {{ctx-map}} {{ctx-uuid}} {{ctx-html}}',
			'dotted'     => '{{a.b}} {{a-b}} {{a_b}}',
		];
	}//end templates()

	/**
	 * Everything pinned, as one JSON-ready array.
	 *
	 * @return array<string, mixed>
	 */
	public function observe(): array {
		$log    = [];
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('debug')->willReturnCallback(
			static function ($message) use (&$log): void {
				$log[] = (string) $message;
			}
		);
		$calls   = [];
		$objects = $this->createMock(ObjectOperationsInterface::class);
		$objects->method('find')->willReturnCallback(
			function ($id) use (&$calls) {
				$calls[] = (string) $id;
				return $this->objectFor(id: (string) $id);
			}
		);
		$templating = new NotificationTemplating($logger, $objects);
		$plain      = new NotificationTemplating($logger);

		$data    = self::values() + ['shared' => 'from-data', 'a.b' => 'dot', 'a-b' => 'dash', 'a_b' => 'under'];
		$context = [
			'shared'   => 'from-context',
			'ctx-only' => 'C',
			'ctx-list' => ['x'],
			'ctx-map'  => ['en' => 'Ctx EN', 'nl' => 'Ctx NL'],
			'ctx-uuid' => self::NAMED,
			'ctx-html' => '<x>',
		];

		$out = [];
		foreach (self::values() as $name => $value) {
			foreach (self::chains() as $chain => $languages) {
				$out['translated'][$name][$chain] = $templating->translatedValue($value, $languages);
			}
		}

		foreach (self::templates() as $name => $template) {
			foreach (self::chains() as $chain => $languages) {
				$out['interpolate'][$name][$chain] = $templating->render(template: $template, values: new PlaceholderValues(data: $data, context: $context, languages: $languages));
			}

			$out['interpolate-no-resolver'][$name] = $plain->render(template: $template, values: new PlaceholderValues(data: $data, context: $context));
			$out['interpolate-no-context'][$name]  = $templating->render(template: $template, values: new PlaceholderValues(data: $data, context: []));
			$out['interpolate-no-data'][$name]     = $templating->render(template: $template, values: new PlaceholderValues(data: [], context: $context, languages: ['nl']));
			$out['unanswered'][$name]              = $templating->unanswered($template, new PlaceholderValues($data, $context));
			$out['unanswered-empty'][$name]        = $templating->unanswered($template, new PlaceholderValues([], []));
			$out['unanswered-context'][$name]      = $templating->unanswered($template, new PlaceholderValues([], $context));
		}

		$out['find-calls'] = $calls;
		$out['log']        = $log;

		return $out;
	}//end observe()

	/**
	 * The object the resolver returns for an id.
	 *
	 * @param string $id The requested id.
	 *
	 * @return ObjectEntity|null
	 */
	private function objectFor(string $id): ?ObjectEntity {
		if ($id === self::THROWS) {
			throw new RuntimeException('lookup failed');
		}

		if ($id === self::MISSING) {
			return null;
		}

		$object = new ObjectEntity();
		$object->setName('');
		if ($id === self::NAMED) {
			$object->setName('Acme <BV>');
		}

		return $object;
	}//end objectFor()

	/**
	 * The templating still produces the recorded observations.
	 *
	 * @return void
	 */
	public function testMatchesGolden(): void {
		$golden = json_decode((string) file_get_contents(self::goldenPath()), true);
		$actual = json_decode((string) json_encode($this->observe()), true);
		$this->assertSame($golden, $actual);
	}//end testMatchesGolden()

	/**
	 * The golden pins rendered text, unanswered names and resolver traffic, not silence.
	 *
	 * @return void
	 */
	public function testGoldenIsNotTrivial(): void {
		$golden = json_decode((string) file_get_contents(self::goldenPath()), true);
		$this->assertStringContainsString('Acme &lt;BV&gt;', $golden['interpolate']['all']['nl']);
		$this->assertNotSame([], $golden['unanswered']['all']);
		$this->assertNotSame([], $golden['find-calls']);
		$this->assertNotSame([], $golden['log']);
		$this->assertSame('Bel klant', $golden['translated']['map-nl-en']['nl']);
	}//end testGoldenIsNotTrivial()
}//end class
