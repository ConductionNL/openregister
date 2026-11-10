<?php

/**
 * MappingSlug turns a mapping name into a slug on every platform it may run on.
 *
 * The transliteration path depends on what the PHP build offers (intl, iconv)
 * and the last-resort slug on random_bytes(). The functions are shadowed in
 * MappingSlug's namespace by pass-through doubles below, so each fallback can
 * be taken without changing the production class; they defer to the real
 * functions unless a test switches them.
 *
 * @category  Test
 * @package   OCA\OpenRegister\Tests\Unit\Db\Mapping
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://www.OpenRegister.app
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db\Mapping {

	use OCA\OpenRegister\Tests\Unit\Db\Mapping\MappingSlugPlatform;

	/**
	 * Shadows class_exists() for MappingSlug; passes through unless intl is switched off.
	 *
	 * @param string $class    The class.
	 * @param bool   $autoload Whether to autoload.
	 *
	 * @return bool
	 */
	function class_exists(string $class, bool $autoload = true): bool {
		if ($class === '\Transliterator' && MappingSlugPlatform::$intl === false) {
			return false;
		}

		return \class_exists($class, $autoload);
	}//end class_exists()

	/**
	 * Shadows function_exists() for MappingSlug; passes through unless iconv is switched off.
	 *
	 * @param string $function The function.
	 *
	 * @return bool
	 */
	function function_exists(string $function): bool {
		if ($function === 'iconv' && MappingSlugPlatform::$iconv === false) {
			return false;
		}

		return \function_exists($function);
	}//end function_exists()

	/**
	 * Shadows iconv() for MappingSlug; passes through unless a failure is switched on.
	 *
	 * @param string $from   Source encoding.
	 * @param string $to     Target encoding.
	 * @param string $string The text.
	 *
	 * @return string|false
	 */
	function iconv(string $from, string $to, string $string): string|false {
		if (MappingSlugPlatform::$iconvFails === true) {
			trigger_error('iconv(): Detected an illegal character', E_USER_NOTICE);
			return false;
		}

		return \iconv($from, $to, $string);
	}//end iconv()

	/**
	 * Shadows transliterator_create() for MappingSlug; passes through unless it is switched to fail.
	 *
	 * @param string $id        The transliterator id.
	 * @param int    $direction The direction.
	 *
	 * @return \Transliterator|null
	 */
	function transliterator_create(string $id, int $direction = 0): ?\Transliterator {
		if (MappingSlugPlatform::$transliteratorFails === true) {
			return null;
		}

		return \transliterator_create($id, $direction);
	}//end transliterator_create()

	/**
	 * Shadows random_bytes() for MappingSlug; passes through unless entropy is switched off.
	 *
	 * @param int $length The length.
	 *
	 * @return string
	 */
	function random_bytes(int $length): string {
		if (MappingSlugPlatform::$entropy === false) {
			throw new \Random\RandomException('no entropy');
		}

		return \random_bytes($length);
	}//end random_bytes()
}

namespace OCA\OpenRegister\Tests\Unit\Db\Mapping {

	use OCA\OpenRegister\Db\Mapping\MappingSlug;
	use PHPUnit\Framework\TestCase;

	/**
	 * What the PHP build offers MappingSlug in the current test.
	 */
	final class MappingSlugPlatform {

		/**
		 * Whether the intl Transliterator class exists.
		 *
		 * @var bool
		 */
		public static bool $intl = true;

		/**
		 * Whether iconv() exists.
		 *
		 * @var bool
		 */
		public static bool $iconv = true;

		/**
		 * Whether iconv() fails.
		 *
		 * @var bool
		 */
		public static bool $iconvFails = false;

		/**
		 * Whether transliterator_create() returns null.
		 *
		 * @var bool
		 */
		public static bool $transliteratorFails = false;

		/**
		 * Whether random_bytes() has entropy.
		 *
		 * @var bool
		 */
		public static bool $entropy = true;

		/**
		 * Back to the real platform.
		 *
		 * @return void
		 */
		public static function reset(): void {
			self::$intl = true;
			self::$iconv = true;
			self::$iconvFails = false;
			self::$transliteratorFails = false;
			self::$entropy = true;
		}//end reset()
	}//end class

	/**
	 * @covers \OCA\OpenRegister\Db\Mapping\MappingSlug
	 */
	class MappingSlugFallbacksTest extends TestCase {

		/**
		 * Restore the real platform after each test.
		 *
		 * @return void
		 */
		protected function tearDown(): void {
			MappingSlugPlatform::reset();
			parent::tearDown();
		}//end tearDown()

		/**
		 * With intl, accents are transliterated and runs of other characters become one dash.
		 *
		 * @return void
		 */
		public function testIntlTransliteratesTheName(): void {
			$this->assertSame('cafe-creme-zaken', (new MappingSlug())->generated('  Café Crème — Zaken! ', 7));
		}//end testIntlTransliteratesTheName()

		/**
		 * Without intl, iconv transliterates instead.
		 *
		 * @return void
		 */
		public function testWithoutIntlIconvTransliterates(): void {
			MappingSlugPlatform::$intl = false;

			$this->assertSame('cafe-zaken', (new MappingSlug())->generated('Café Zaken', 7));
		}//end testWithoutIntlIconvTransliterates()

		/**
		 * A failing iconv leaves the name as it was, so only its ASCII survives.
		 *
		 * @return void
		 */
		public function testAFailingIconvKeepsTheAsciiPart(): void {
			MappingSlugPlatform::$intl = false;
			MappingSlugPlatform::$iconvFails = true;

			$this->assertSame('caf-zaken', (new MappingSlug())->generated('Café Zaken', 7));
		}//end testAFailingIconvKeepsTheAsciiPart()

		/**
		 * Without intl or iconv the name is used untransliterated.
		 *
		 * @return void
		 */
		public function testWithoutIntlOrIconvTheNameIsUsedAsIs(): void {
			MappingSlugPlatform::$intl = false;
			MappingSlugPlatform::$iconv = false;

			$this->assertSame('caf-zaken', (new MappingSlug())->generated('Café Zaken', 7));
		}//end testWithoutIntlOrIconvTheNameIsUsedAsIs()

		/**
		 * A transliterator that cannot be created leaves the name as it was.
		 *
		 * @return void
		 */
		public function testAMissingTransliteratorKeepsTheName(): void {
			MappingSlugPlatform::$transliteratorFails = true;

			$this->assertSame('caf-zaken', (new MappingSlug())->generated('Café Zaken', 7));
		}//end testAMissingTransliteratorKeepsTheName()

		/**
		 * A name with nothing sluggable falls back to the id.
		 *
		 * @return void
		 */
		public function testAnUnsluggableNameFallsBackToTheId(): void {
			$this->assertSame('mapping-42', (new MappingSlug())->generated(' !!! ', 42));
			$this->assertSame('mapping-42', (new MappingSlug())->generated(null, '42'));
		}//end testAnUnsluggableNameFallsBackToTheId()

		/**
		 * Without name or id the slug is random.
		 *
		 * @return void
		 */
		public function testWithoutNameOrIdTheSlugIsRandom(): void {
			$this->assertMatchesRegularExpression('/^mapping-[0-9a-f]{8}$/', (new MappingSlug())->generated('', null));
		}//end testWithoutNameOrIdTheSlugIsRandom()

		/**
		 * Without entropy the slug is derived from the name.
		 *
		 * @return void
		 */
		public function testWithoutEntropyTheSlugDerivesFromTheName(): void {
			MappingSlugPlatform::$entropy = false;

			$this->assertSame('mapping-' . substr(md5('—'), 0, 8), (new MappingSlug())->generated('—', ''));
		}//end testWithoutEntropyTheSlugDerivesFromTheName()
	}//end class
}
