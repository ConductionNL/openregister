<?php

/**
 * Golden tests for how TranslationHandler picks one language per translatable property on render.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Object
 * @author   Conduction <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/openregister
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Object;

use OCA\OpenRegister\Service\Object\TranslationScope;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\LanguageService;
use OCA\OpenRegister\Service\Object\TranslationHandler;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Pins the language chain (resolved, register languages, register default), the any-variant fallback and the fallback flag.
 *
 * @covers \OCA\OpenRegister\Service\Object\TranslationHandler
 * @uses   \OCA\OpenRegister\Service\Object\LanguageKeyedMap
 * @uses   \OCA\OpenRegister\Db\Register
 * @uses   \OCA\OpenRegister\Db\Schema
 */
class TranslationHandlerRenderGoldenTest extends TestCase {

	/**
	 * Calls made on the language service.
	 *
	 * @var list<string>
	 */
	private array $calls = [];

	/**
	 * Render with a language service that resolves to the given language.
	 *
	 * @param array         $data     The object data.
	 * @param Register|null $register The register.
	 * @param string        $resolved The language the service resolves to.
	 * @param bool          $all      Whether all translations are requested.
	 *
	 * @return array The rendered data.
	 */
	private function render(array $data, ?Register $register, string $resolved, bool $all = false): array {
		$languages = $this->createMock(LanguageService::class);
		$languages->method('shouldReturnAllTranslations')->willReturn($all);
		$languages->method('resolveLanguageForRegister')->willReturnCallback(
			function (array $registerLanguages) use ($resolved): string {
				$this->calls[] = 'resolve:' . implode(',', $registerLanguages);
				return $resolved;
			}
		);
		$languages->method('setFallbackUsed')->willReturnCallback(
			function (bool $used): void {
				$this->calls[] = 'fallback:' . var_export($used, true);
			}
		);

		$schema = new Schema();
		$schema->setProperties(
			[
				'title' => ['type' => 'string', 'translatable' => true],
				'body' => ['type' => 'string', 'translatable' => true],
				'note' => ['type' => 'string', 'translatable' => true],
				'tags' => ['type' => 'array', 'translatable' => true],
				'gone' => ['type' => 'string', 'translatable' => true],
				'plain' => ['type' => 'object'],
				'broken' => 'not-an-array',
			]
		);

		$handler = new TranslationHandler($languages, $this->createMock(LoggerInterface::class));
		return $handler->resolveTranslationsForRender(objectData: $data, scope: new TranslationScope($schema, $register));
	}//end render()

	/**
	 * A register with the given languages.
	 *
	 * @param list<string> $languages The languages, default first.
	 *
	 * @return Register The register.
	 */
	private function register(array $languages): Register {
		$register = new Register();
		$register->setLanguages($languages);
		return $register;
	}//end register()

	/**
	 * Object data with every kind of value.
	 *
	 * @return array The data.
	 */
	private function data(): array {
		return [
			'title' => ['nl' => 'Titel', 'en' => 'Title'],
			'body' => ['de' => 'Text', 'fr' => 'Texte'],
			'note' => ['es' => 'Nota'],
			'tags' => ['a', 'b'],
			'gone' => null,
			'plain' => ['en' => 'kept'],
		];
	}//end data()

	public function testResolvedLanguageFirst(): void {
		$out = $this->render($this->data(), $this->register(['fr', 'de']), 'nl');

		$this->assertSame(
			['title' => 'Titel', 'body' => 'Texte', 'note' => 'Nota', 'tags' => ['a', 'b'], 'gone' => null, 'plain' => ['en' => 'kept']],
			$out
		);
		$this->assertSame(['resolve:fr,de', 'fallback:true', 'fallback:true'], $this->calls);
	}//end testResolvedLanguageFirst()

	public function testRegisterLanguagesInDeclaredOrderThenDefault(): void {
		$out = $this->render($this->data(), $this->register(['de', 'en']), 'it');

		$this->assertSame(['Title', 'Text', 'Nota'], [$out['title'], $out['body'], $out['note']]);
		$this->assertSame(['resolve:de,en', 'fallback:true', 'fallback:true', 'fallback:true'], $this->calls);
	}//end testRegisterLanguagesInDeclaredOrderThenDefault()

	public function testWithoutRegisterDutchIsTheDefault(): void {
		$out = $this->render(['title' => ['en' => 'Title', 'nl' => 'Titel'], 'body' => ['fr' => 'Texte']], null, 'de');

		$this->assertSame(['title' => 'Titel', 'body' => 'Texte'], $out);
		$this->assertSame(['resolve:', 'fallback:true', 'fallback:true'], $this->calls);
	}//end testWithoutRegisterDutchIsTheDefault()

	public function testAnEmptyVariantListStaysAsIs(): void {
		$out = $this->render(['title' => ['en' => false]], null, 'nl');

		$this->assertSame(['title' => ['en' => false]], $out);
		$this->assertSame(['resolve:'], $this->calls);
	}//end testAnEmptyVariantListStaysAsIs()

	public function testAllTranslationsReturnsTheDataUnchanged(): void {
		$out = $this->render($this->data(), $this->register(['nl']), 'nl', true);

		$this->assertSame($this->data(), $out);
		$this->assertSame([], $this->calls);
	}//end testAllTranslationsReturnsTheDataUnchanged()
}//end class
