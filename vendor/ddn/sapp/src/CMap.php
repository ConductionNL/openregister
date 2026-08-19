<?php
/*
    This file is part of SAPP.

    SAPP — Simple and Agnostic PDF Parser.
    LGPL-3.0-or-later — see the SAPP license header in PDFObject.php.
*/

namespace ddn\sapp;

use function ddn\sapp\helpers\p_error;

/**
 * Parser + lookup table for ToUnicode CMap streams.
 *
 * Implements the subset of Adobe Tech Note 5411 ("ToUnicode Mapping
 * File Tutorial") used by every Word-emitted Identity-H subset font:
 * `beginbfchar`/`endbfchar` and `beginbfrange`/`endbfrange` blocks.
 * Builds a forward map (Unicode string → CID byte sequence) and a
 * reverse map (CID byte sequence → Unicode string) from the parsed
 * entries. Multi-codepoint Unicode targets (e.g. the `fi` ligature
 * `<0050>` → `<0066 0069>`) are flattened to NFC before storage.
 *
 * Unsupported directives (`usecmap`, `begincidrange`, `cmapname`,
 * etc.) are silently tolerated — the parser only acts on
 * `beginbfchar` / `beginbfrange` blocks and ignores everything else.
 * Truly malformed CMap streams produce an empty map and a `p_error`
 * log line.
 *
 * @category Helper
 * @package  ddn\sapp
 */
class CMap
{
    /**
     * Forward map: NFC Unicode string → CID byte sequence (string).
     *
     * @var array<string, string>
     */
    private $forward = [];

    /**
     * Reverse map: CID byte sequence (string) → NFC Unicode string.
     *
     * @var array<string, string>
     */
    private $reverse = [];

    /**
     * Cached width of CID values in bytes (1 for simple fonts, 2 for
     * Identity-H / -V). Set on first parsed entry.
     *
     * @var int|null
     */
    private $cidWidth = null;

    /**
     * Build a CMap by parsing a decoded /ToUnicode stream.
     *
     * @param string $bytes The raw decoded CMap stream content (after FlateDecode etc.).
     *
     * @return CMap
     */
    public static function fromStream($bytes) {
        $cmap = new self();
        $cmap->parse($bytes);
        return $cmap;
    }

    /**
     * Resolve a CID byte sequence to its Unicode string.
     *
     * @param string $cidBytes Raw bytes from a Tj operator's operand.
     *
     * @return string Unicode string (NFC); empty if the CID is unknown.
     */
    public function cidToUnicode($cidBytes) {
        return $this->reverse[$cidBytes] ?? '';
    }

    /**
     * Resolve a single Unicode codepoint to its CID byte sequence.
     *
     * @param string $unicode A single NFC-normalised Unicode character
     *                        (may be multi-byte in UTF-8 terms).
     *
     * @return string|null CID bytes; null when the font can't encode the codepoint.
     */
    public function unicodeToCid($unicode) {
        return $this->forward[$unicode] ?? null;
    }

    /**
     * Width of CID byte sequences in this map (1 for simple fonts,
     * 2 for Identity-H / -V). Returns 0 if the map is empty.
     *
     * @return int
     */
    public function cidWidth() {
        return $this->cidWidth ?? 0;
    }

    /**
     * Map is empty (no entries parsed successfully).
     *
     * @return bool
     */
    public function isEmpty() {
        return count($this->reverse) === 0;
    }

    /**
     * Walk the CMap stream tokens and populate the forward + reverse maps.
     *
     * @param string $bytes Decoded CMap stream content.
     *
     * @return void
     */
    private function parse($bytes) {
        // Strip comments (anything from % to end-of-line).
        $bytes = preg_replace('/%[^\n]*\n/', "\n", $bytes);

        // Find all beginbfchar/endbfchar blocks.
        if (preg_match_all('/beginbfchar\s+(.*?)\s+endbfchar/s', $bytes, $matches)) {
            foreach ($matches[1] as $block) {
                $this->parseBfcharBlock($block);
            }
        }

        // Find all beginbfrange/endbfrange blocks.
        if (preg_match_all('/beginbfrange\s+(.*?)\s+endbfrange/s', $bytes, $matches)) {
            foreach ($matches[1] as $block) {
                $this->parseBfrangeBlock($block);
            }
        }
    }

    /**
     * Parse a beginbfchar block content.
     *
     * Each line in a bfchar block is `<src> <dst>` where both are hex
     * strings. `<src>` is the CID, `<dst>` is the Unicode value (1 or
     * more BMP / supplementary codepoints encoded as a hex sequence).
     *
     * @param string $block Content between beginbfchar and endbfchar.
     *
     * @return void
     */
    private function parseBfcharBlock($block) {
        // Match pairs of <hex> <hex>.
        if (preg_match_all('/<([0-9A-Fa-f]+)>\s*<([0-9A-Fa-f]+)>/', $block, $pairs, PREG_SET_ORDER) === false) {
            return;
        }
        foreach ($pairs as $pair) {
            $srcHex = $pair[1];
            $dstHex = $pair[2];
            $this->addMapping($srcHex, $this->hexToUnicode($dstHex));
        }
    }

    /**
     * Parse a beginbfrange block content.
     *
     * Three line shapes per Adobe Tech Note 5411:
     *   <srcLo> <srcHi> <dstStart>      — contiguous; dst increments per CID
     *   <srcLo> <srcHi> [<d1> <d2> ...] — explicit per-CID Unicode targets
     *
     * @param string $block Content between beginbfrange and endbfrange.
     *
     * @return void
     */
    private function parseBfrangeBlock($block) {
        // Pattern matches lines of either shape:
        // 1. `<src1> <src2> <dst>` — contiguous
        // 2. `<src1> <src2> [<d1> <d2> ...]` — array form
        $pattern = '/<([0-9A-Fa-f]+)>\s*<([0-9A-Fa-f]+)>\s*(?:<([0-9A-Fa-f]+)>|\[([^\]]+)\])/';
        if (preg_match_all($pattern, $block, $entries, PREG_SET_ORDER) === false) {
            return;
        }
        foreach ($entries as $entry) {
            $loHex = $entry[1];
            $hiHex = $entry[2];
            $contiguousDst = $entry[3] ?? '';
            $arrayDsts = $entry[4] ?? '';

            $loInt = hexdec($loHex);
            $hiInt = hexdec($hiHex);
            $cidWidth = strlen($loHex) / 2;  // bytes

            // Adobe Tech Note 5411 §1.4.5: a single bfrange MUST NOT
            // span more than 256 codes. Reject larger ranges to bound
            // memory use against malicious CMaps that would expand to
            // (e.g.) 65K entries via `<0001> <FFFF> <0041>`.
            if (($hiInt - $loInt) > 255) {
                continue;
            }

            if ($contiguousDst !== '') {
                // Contiguous form: dst increments.
                $dstStart = hexdec($contiguousDst);
                for ($cid = $loInt, $offset = 0; $cid <= $hiInt; $cid++, $offset++) {
                    $cidHex = str_pad(dechex($cid), $cidWidth * 2, '0', STR_PAD_LEFT);
                    $this->addMapping($cidHex, $this->codepointToUnicode($dstStart + $offset));
                }
            } else {
                // Array form: explicit per-CID dst list.
                if (preg_match_all('/<([0-9A-Fa-f]+)>/', $arrayDsts, $dstMatches)) {
                    $dsts = $dstMatches[1];
                    foreach ($dsts as $i => $dstHex) {
                        $cid = $loInt + $i;
                        if ($cid > $hiInt) break;
                        $cidHex = str_pad(dechex($cid), $cidWidth * 2, '0', STR_PAD_LEFT);
                        $this->addMapping($cidHex, $this->hexToUnicode($dstHex));
                    }
                }
            }
        }
    }

    /**
     * Decode a hex string into raw CID bytes, then register a forward
     * + reverse mapping.
     *
     * @param string $cidHex     Hex string for the CID (e.g. "0041").
     * @param string $unicodeStr NFC Unicode string the CID maps to.
     *
     * @return void
     */
    private function addMapping($cidHex, $unicodeStr) {
        $cidBytes = @hex2bin($cidHex);
        if ($cidBytes === false || $unicodeStr === '') {
            return;
        }
        if ($this->cidWidth === null) {
            $this->cidWidth = strlen($cidBytes);
        }
        // NFC normalise the Unicode side for stable comparison.
        $unicodeStr = $this->nfc($unicodeStr);
        $this->reverse[$cidBytes] = $unicodeStr;
        // Forward map: only add for single-codepoint targets (the
        // ambiguous multi-codepoint case picks the lowest CID with
        // a `unset()` guard).
        if (!isset($this->forward[$unicodeStr])) {
            $this->forward[$unicodeStr] = $cidBytes;
        }
    }

    /**
     * Decode a hex string into a Unicode string.
     *
     * Hex pairs are interpreted as UTF-16BE code units; multi-codepoint
     * targets (ligatures, decomposed accents) decode as the concatenation
     * of their codepoints.
     *
     * @param string $hex Hex string, length must be even.
     *
     * @return string Decoded Unicode string (UTF-8 encoded internally).
     */
    private function hexToUnicode($hex) {
        $bytes = @hex2bin($hex);
        if ($bytes === false) return '';
        // UTF-16BE → UTF-8 conversion. PHP's mb_convert_encoding handles
        // surrogate pairs correctly.
        $utf8 = @mb_convert_encoding($bytes, 'UTF-8', 'UTF-16BE');
        if ($utf8 === false) return '';
        return $utf8;
    }

    /**
     * Decode a single Unicode codepoint integer to its UTF-8 string.
     *
     * @param int $codepoint Integer codepoint value.
     *
     * @return string UTF-8 encoded character.
     */
    private function codepointToUnicode($codepoint) {
        $utf8 = @mb_convert_encoding(pack('N', $codepoint), 'UTF-8', 'UTF-32BE');
        // String '0' is falsy — explicit false check, not `?:`.
        return $utf8 === false ? '' : $utf8;
    }

    /**
     * NFC-normalise a Unicode string if the intl extension is available.
     * Falls back to pass-through otherwise (common in CI images without
     * the intl extension).
     *
     * @param string $str UTF-8 string.
     *
     * @return string NFC-normalised UTF-8 string.
     */
    private function nfc($str) {
        if (class_exists('Normalizer', false) === true) {
            $result = \Normalizer::normalize($str, \Normalizer::FORM_C);
            if (is_string($result)) return $result;
        }
        return $str;
    }
}
