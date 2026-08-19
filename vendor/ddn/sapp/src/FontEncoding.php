<?php
/*
    This file is part of SAPP.

    SAPP — Simple and Agnostic PDF Parser.
    LGPL-3.0-or-later — see the SAPP license header in PDFObject.php.
*/

namespace ddn\sapp;

/**
 * Implicit-encoding tables for PDF simple fonts (single-byte) plus
 * Identity-H / Identity-V passthrough discriminators.
 *
 * Used when a font dictionary does NOT carry a /ToUnicode CMap and we
 * have to fall back to the named `/Encoding` value (PDF 1.7 §9.6.6).
 * The implicit encodings (`/WinAnsiEncoding`, `/MacRomanEncoding`,
 * `/StandardEncoding`) define a 1-byte → glyph-name → Unicode chain
 * which we flatten to a direct byte → codepoint table here.
 *
 * `/Identity-H` / `/Identity-V` are composite-font CID encodings; they
 * require a ToUnicode CMap to resolve to Unicode, so this class only
 * exposes them via the `isIdentityH()` / `isIdentityV()` discriminators.
 *
 * @category Helper
 * @package  ddn\sapp
 */
class FontEncoding
{
    /**
     * Encoding name (e.g. '/WinAnsiEncoding', '/Identity-H').
     *
     * @var string
     */
    private $name;

    /**
     * byte (int 0..255) → Unicode codepoint (int).
     * Empty for Identity-H / -V (those require a CMap).
     *
     * @var array<int, int>
     */
    private $byteToCp = [];

    /**
     * codepoint (int) → byte (int).
     *
     * @var array<int, int>
     */
    private $cpToByte = [];

    /**
     * Construct a FontEncoding for the named encoding.
     *
     * Use the `forName` factory unless you have a good reason to build
     * one with a custom table (e.g. /Differences-overridden encodings).
     *
     * @param string         $name      Encoding name including leading slash.
     * @param array<int,int> $byteToCp  Byte-to-codepoint table.
     */
    public function __construct($name, array $byteToCp = []) {
        $this->name = $name;
        $this->byteToCp = $byteToCp;
        foreach ($byteToCp as $b => $cp) {
            $this->cpToByte[$cp] = $b;
        }
    }

    /**
     * Factory for the standard PDF encodings.
     *
     * @param string $name Encoding name as it appears in the font dict.
     *
     * @return FontEncoding
     */
    /** @var array<string, FontEncoding> Cache for the standard encodings. */
    private static $instanceCache = [];

    public static function forName($name) {
        $name = trim($name);
        if ($name === '' || $name[0] !== '/') $name = '/' . $name;

        // Cache the resolved instance per name so multi-page documents
        // don't pay the table-build cost (two loops over 256 entries
        // each) on every byteToUnicode invocation. The standard
        // encoding tables are immutable, so reuse is safe.
        if (isset(self::$instanceCache[$name])) {
            return self::$instanceCache[$name];
        }
        switch ($name) {
            case '/WinAnsiEncoding':
                $inst = new self($name, self::winAnsiTable());
                break;
            case '/MacRomanEncoding':
                $inst = new self($name, self::macRomanTable());
                break;
            case '/StandardEncoding':
                $inst = new self($name, self::standardTable());
                break;
            case '/Identity-H':
            case '/Identity-V':
                // Identity passthrough: no implicit byte→codepoint table;
                // resolution requires a ToUnicode CMap.
                $inst = new self($name, []);
                break;
            default:
                $inst = new self($name, []);
                break;
        }
        self::$instanceCache[$name] = $inst;
        return $inst;
    }

    /**
     * Resolve a single byte to its Unicode character (UTF-8).
     *
     * @param int $byte 0..255.
     *
     * @return string Single UTF-8 character; empty when the encoding can't resolve the byte.
     */
    public function byteToUnicode($byte) {
        if (!isset($this->byteToCp[$byte])) return '';
        $utf8 = @mb_convert_encoding(pack('N', $this->byteToCp[$byte]), 'UTF-8', 'UTF-32BE');
        // String '0' is falsy in PHP — use explicit false check rather
        // than `?:` which would replace '0' with the empty string.
        return $utf8 === false ? '' : $utf8;
    }

    /**
     * Resolve a single Unicode character to its encoded byte.
     *
     * @param string $unicode Single UTF-8 character.
     *
     * @return int|null Byte value 0..255, or null when not encodable.
     */
    public function unicodeToByte($unicode) {
        // Convert UTF-8 char to a single codepoint integer.
        $utf32 = @mb_convert_encoding($unicode, 'UTF-32BE', 'UTF-8');
        if ($utf32 === false || strlen($utf32) < 4) return null;
        $unpacked = @unpack('N', $utf32);
        if ($unpacked === false || !isset($unpacked[1])) return null;
        $cp = $unpacked[1];
        return $this->cpToByte[$cp] ?? null;
    }

    /** @return bool True when this is the Identity-H composite encoding. */
    public function isIdentityH() { return $this->name === '/Identity-H'; }

    /** @return bool True when this is the Identity-V composite encoding. */
    public function isIdentityV() { return $this->name === '/Identity-V'; }

    /** @return string Encoding name (with leading slash). */
    public function getName() { return $this->name; }

    /**
     * WinAnsiEncoding byte → codepoint table per PDF 1.7 Appendix D
     * Table D.2. Codes 0x20..0x7E are ASCII; the high half (0x80..0xFF)
     * is roughly Windows-1252 with PDF-specific deviations.
     *
     * @return array<int, int>
     */
    private static function winAnsiTable() {
        $table = [];
        // 0x20..0x7E: ASCII passthrough.
        for ($b = 0x20; $b <= 0x7E; $b++) {
            $table[$b] = $b;
        }
        // High-half assignments per Table D.2 (the encoded glyph names
        // resolved to Unicode codepoints via the Adobe Glyph List).
        $highHalf = [
            0x80 => 0x20AC, 0x82 => 0x201A, 0x83 => 0x0192, 0x84 => 0x201E,
            0x85 => 0x2026, 0x86 => 0x2020, 0x87 => 0x2021, 0x88 => 0x02C6,
            0x89 => 0x2030, 0x8A => 0x0160, 0x8B => 0x2039, 0x8C => 0x0152,
            0x8E => 0x017D, 0x91 => 0x2018, 0x92 => 0x2019, 0x93 => 0x201C,
            0x94 => 0x201D, 0x95 => 0x2022, 0x96 => 0x2013, 0x97 => 0x2014,
            0x98 => 0x02DC, 0x99 => 0x2122, 0x9A => 0x0161, 0x9B => 0x203A,
            0x9C => 0x0153, 0x9E => 0x017E, 0x9F => 0x0178,
            0xA0 => 0x00A0, 0xA1 => 0x00A1, 0xA2 => 0x00A2, 0xA3 => 0x00A3,
            0xA4 => 0x00A4, 0xA5 => 0x00A5, 0xA6 => 0x00A6, 0xA7 => 0x00A7,
            0xA8 => 0x00A8, 0xA9 => 0x00A9, 0xAA => 0x00AA, 0xAB => 0x00AB,
            0xAC => 0x00AC, 0xAD => 0x00AD, 0xAE => 0x00AE, 0xAF => 0x00AF,
        ];
        // 0xB0..0xFF: Latin-1 passthrough (most are unchanged from ISO-8859-1).
        for ($b = 0xB0; $b <= 0xFF; $b++) {
            $highHalf[$b] = $b;
        }
        foreach ($highHalf as $b => $cp) {
            $table[$b] = $cp;
        }
        return $table;
    }

    /**
     * MacRomanEncoding byte → codepoint table (subset — covers the
     * printable ASCII range plus the most common Western European
     * accented characters in the high half). The full table is in
     * PDF 1.7 Appendix D Table D.4; we cover what's needed for the
     * Woo use case.
     *
     * @return array<int, int>
     */
    private static function macRomanTable() {
        $table = [];
        for ($b = 0x20; $b <= 0x7E; $b++) {
            $table[$b] = $b;
        }
        // Apple's high-half mapping — partial coverage; expand as needed.
        $highHalf = [
            0x80 => 0x00C4, 0x81 => 0x00C5, 0x82 => 0x00C7, 0x83 => 0x00C9,
            0x84 => 0x00D1, 0x85 => 0x00D6, 0x86 => 0x00DC, 0x87 => 0x00E1,
            0x88 => 0x00E0, 0x89 => 0x00E2, 0x8A => 0x00E4, 0x8B => 0x00E3,
            0x8C => 0x00E5, 0x8D => 0x00E7, 0x8E => 0x00E9, 0x8F => 0x00E8,
            0x90 => 0x00EA, 0x91 => 0x00EB, 0x92 => 0x00ED, 0x93 => 0x00EC,
            0x94 => 0x00EE, 0x95 => 0x00EF, 0x96 => 0x00F1, 0x97 => 0x00F3,
            0xA9 => 0x00AE, 0xAA => 0x2122, 0xC8 => 0x00BF, 0xC9 => 0x00A1,
            0xD0 => 0x2013, 0xD1 => 0x2014, 0xD2 => 0x201C, 0xD3 => 0x201D,
        ];
        foreach ($highHalf as $b => $cp) {
            $table[$b] = $cp;
        }
        return $table;
    }

    /**
     * StandardEncoding byte → codepoint table (Adobe Standard Encoding,
     * PDF 1.7 Appendix D Table D.2 column 1). ASCII passthrough only;
     * the high-half differs from WinAnsi but is rare in modern PDFs.
     *
     * @return array<int, int>
     */
    private static function standardTable() {
        $table = [];
        for ($b = 0x20; $b <= 0x7E; $b++) {
            $table[$b] = $b;
        }
        return $table;
    }
}
