<?php

declare(strict_types=1);

/**
 * What normalizeTranslationsForSave makes of every value shape, with and without a target language
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Object
 * @author   Conduction Development Team <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/openregister
 */

namespace OCA\OpenRegister\Tests\Unit\Service\Object;

use OCA\OpenRegister\Service\Object\TranslationScope;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Exception\TranslationTargetConflictException;
use OCA\OpenRegister\Service\LanguageService;
use OCA\OpenRegister\Service\Object\TranslationHandler;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Pins the save normalisation shape by shape.
 */
class TranslationHandlerSaveShapesTest extends TestCase {
	/**
	 * Shapes: object data, target language, expected data, whether a warning is logged.
	 *
	 * @return array<string, array{0: array, 1: string|null, 2: array, 3: bool}>
	 */
	public static function shapes(): array {
		$keyed = ['nl' => 'Hallo', 'en' => 'Hello'];
		return [
			'a scalar goes under the default' => [['title' => 'Hallo'], null, ['title' => ['nl' => 'Hallo']], false],
			'a scalar goes under the target' => [['title' => 'Hello'], 'en', ['title' => ['en' => 'Hello']], false],
			'an empty target counts as none' => [['title' => 'Hallo'], '', ['title' => ['nl' => 'Hallo']], false],
			'a keyed value stays' => [['title' => $keyed], null, ['title' => $keyed], false],
			'a keyed value without the default stays and warns' => [['title' => ['en' => 'Hello']], null, ['title' => ['en' => 'Hello']], true],
			'a keyed value with a null default stays and warns' => [['title' => ['nl' => null, 'en' => 'Hello']], null, ['title' => ['nl' => null, 'en' => 'Hello']], true],
			'a plain list is a simple value' => [['title' => ['a', 'b']], null, ['title' => ['nl' => ['a', 'b']]], false],
			'a null stays' => [['title' => null], 'en', ['title' => null], false],
			'an absent property stays absent' => [['other' => 1], 'en', ['other' => 1], false],
			'another property is left alone' => [['other' => 'x', 'title' => 'y'], null, ['other' => 'x', 'title' => ['nl' => 'y']], false],
		];
	}

	/**
	 * Every shape answers as declared.
	 *
	 * @param array       $data     The object data.
	 * @param string|null $target   The target language.
	 * @param array       $expected The expected data.
	 * @param bool        $warns    Whether the missing default is logged.
	 *
	 * @return void
	 */
	#[DataProvider('shapes')]
	public function testAShapeIsNormalised(array $data, ?string $target, array $expected, bool $warns): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($warns ? $this->once() : $this->never())->method('warning')->with(
			'[TranslationHandler] Translatable property missing default language value',
			$this->callback(fn (array $context): bool => $context['property'] === 'title' && $context['defaultLanguage'] === 'nl')
		);

		$this->assertSame($expected, $this->handler(logger: $logger, target: $target)->normalizeTranslationsForSave($data, new TranslationScope($this->schema(), $this->register())));
	}

	/**
	 * A keyed value with a target refuses, and names the property and the language.
	 *
	 * @return void
	 */
	public function testAKeyedValueWithATargetIsAConflict(): void {
		$handler = $this->handler(logger: $this->createMock(LoggerInterface::class), target: 'en');
		try {
			$handler->normalizeTranslationsForSave(['title' => ['nl' => 'Hallo']], new TranslationScope($this->schema(), $this->register()));
			$this->fail('A conflict was expected.');
		} catch (TranslationTargetConflictException $e) {
			$this->assertStringContainsString('title', $e->getMessage());
			$this->assertStringContainsString('en', $e->getMessage());
		}
	}

	/**
	 * Without a register the default is Dutch.
	 *
	 * @return void
	 */
	public function testNoRegisterMeansDutch(): void {
		$handler = $this->handler(logger: $this->createMock(LoggerInterface::class), target: null);

		$this->assertSame(['title' => ['nl' => 'x']], $handler->normalizeTranslationsForSave(['title' => 'x'], new TranslationScope($this->schema())));
	}

	/**
	 * A schema without translatable properties changes nothing.
	 *
	 * @return void
	 */
	public function testNothingTranslatableChangesNothing(): void {
		$schema = new Schema();
		$schema->setProperties(['title' => ['type' => 'string']]);
		$handler = $this->handler(logger: $this->createMock(LoggerInterface::class), target: 'en');

		$this->assertSame(['title' => 'x'], $handler->normalizeTranslationsForSave(['title' => 'x'], new TranslationScope($schema, $this->register())));
	}

	/**
	 * The handler.
	 *
	 * @param LoggerInterface $logger The logger.
	 * @param string|null     $target The target language.
	 *
	 * @return TranslationHandler
	 */
	private function handler(LoggerInterface $logger, ?string $target): TranslationHandler {
		$languages = new LanguageService();
		$languages->setTargetLanguage($target);
		return new TranslationHandler($languages, $logger);
	}

	/**
	 * A schema with one translatable property.
	 *
	 * @return Schema
	 */
	private function schema(): Schema {
		$schema = new Schema();
		$schema->setProperties(['title' => ['type' => 'string', 'translatable' => true]]);
		return $schema;
	}

	/**
	 * A register with Dutch as default.
	 *
	 * @return Register
	 */
	private function register(): Register {
		$register = new Register();
		$register->setLanguages(['nl', 'en']);
		return $register;
	}
}
