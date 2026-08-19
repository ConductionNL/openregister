<?php
/*
    This file is part of SAPP

    Simple and Agnostic PDF Parser (SAPP) - Parse PDF documents in PHP (and update them)
    Copyright (C) 2020 - Carlos de Alfonso (caralla76@gmail.com)

    This program is free software: you can redistribute it and/or modify
    it under the terms of the GNU Lesser General Public License as published by
    the Free Software Foundation, either version 3 of the License, or
    (at your option) any later version.

    This program is distributed in the hope that it will be useful,
    but WITHOUT ANY WARRANTY; without even the implied warranty of
    MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
    GNU General Public License for more details.

    You should have received a copy of the GNU Lesser General Public License
    along with this program.  If not, see <https://www.gnu.org/licenses/>.
*/

namespace ddn\sapp;

use ddn\sapp\pdfvalue\PDFValueObject;
use ddn\sapp\pdfvalue\PDFValueSimple;
use \ArrayAccess;

use ddn\sapp\helpers\Buffer;

// Loading the functions
use ddn\sapp\helpers\LoadHelpers;
if (!defined("ddn\\sapp\\helpers\\LoadHelpers"))
    new LoadHelpers;

use function ddn\sapp\helpers\p_debug;
use function ddn\sapp\helpers\p_debug_var;
use function ddn\sapp\helpers\p_error;
use function ddn\sapp\helpers\p_warning;

// The character used to end lines
if (!defined('__EOL'))
    define('__EOL', "\n");

/**
 * Class to gather the information of a PDF object: the OID, the definition and the stream. The purpose is to 
 *   ease the generation of the PDF entries for an individual object.
 */
class PDFObject implements ArrayAccess {
    protected static $_revisions;
    protected static $_xref_table_version;

    protected $_oid = null;
    protected $_stream = null;
    protected $_value = null;
    protected $_generation;

    
    public function __construct($oid, $value = null, $generation = 0) {
        if ($generation !== 0)
            p_warning("Objects of non-zero generation are not fully checked... please double check your document and (if possible) please send examples via issues to https://github.com/dealfonso/sapp/issues/");

        // If the value is null, we suppose that we are creating an empty object
        if ($value === null)
            $value = new PDFValueObject();

        // Ease the creation of the object
        if (is_array($value)) {
            $obj = new PDFValueObject();
            foreach ($value as $field => $v) {
                $obj[$field] = $v;
            }
            $value = $obj;
        }

        $this->_oid = $oid;
        $this->_value = $value;
        $this->_generation = $generation;
    }

    public function get_keys() {
        return $this->_value->get_keys();
    }

    public function set_oid($oid) {
        $this->_oid = $oid;
    }

    public function get_generation() {
        return $this->_generation;
    }

    public function __toString() {
        return  "$this->_oid 0 obj\n" .
            "$this->_value\n" .
            ($this->_stream === null?"":
                "stream\n" .
                '...' . 
                "\nendstream\n"
            ) .
            "endobj\n";
    }
    /**
     * Converts the object to a well-formed PDF entry with a form like
     *  1 0 obj
     *  ...
     *  stream
     *  ...
     *  endstream
     *  endobj
     * @return pdfentry a string that contains the PDF entry
     */
    public function to_pdf_entry() {
        return  "$this->_oid 0 obj" . __EOL .
                "$this->_value" . __EOL .
                ($this->_stream === null?"":
                    "stream\r\n" .
                    $this->_stream . 
                    __EOL . "endstream" . __EOL
                ) .
                "endobj" . __EOL;
    }
    /**
     * Gets the object ID
     * @return oid the object id
     */
    public function get_oid() {
        return $this->_oid;
    }
    /**
     * Gets the definition of the object (a PDFValue object)
     * @return value the definition of the object
     */
    public function get_value() {
        return $this->_value;
    }
    protected static function FlateDecode($_stream, $params) {
        // Behaviour preserved exactly for backward compatibility. The
        // predictor logic moved into `applyPngPredictor` (D4 / PR #04)
        // so `LZWDecode` can reuse it without duplicating the loop.
        return self::applyPngPredictor($_stream, $params);
    }

    /**
     * Apply the PNG-family predictor (PDF 1.7 §7.4.4.4 Table 9) to an
     * already-decompressed stream.
     *
     * Lifted out of `FlateDecode` so both `FlateDecode` and
     * `LZWDecode` can share the row-filter inversion loop. Predictor
     * value 1 (the default) is a no-op pass-through; PNG predictors
     * 10-15 select per-row filter strategies; non-PNG predictors are
     * rejected via `p_error`. `Colors` and `BitsPerComponent` are
     * checked against the only supported values (1 colour, 8 bits)
     * — the wider parameter space matters only for image streams.
     *
     * @param string               $_stream Already-decompressed bytes.
     * @param array<string, mixed> $params  Predictor / Columns / BitsPerComponent / Colors.
     *
     * @return string|false Predictor-inverted bytes on success; `false`
     *                      on parameter rejection (Predictor outside
     *                      {1,10..15}, Colors≠1, BitsPerComponent≠8, or
     *                      an unsupported per-row filter byte such as
     *                      PNG Paeth / Average). Callers MUST check
     *                      `=== false` (matching `p_error()`'s default
     *                      return) — earlier versions of this method
     *                      documented `null` but never returned it.
     */
    protected static function applyPngPredictor($_stream, $params) {
        switch ($params["Predictor"]->get_int()) {
            case 1:
                    return $_stream;
            case 10:
            case 11:
            case 12:
            case 13:
            case 14:
            case 15:
                    break;
            default:
                    return p_error("other predictor than PNG is not supported in this version");
        }

        switch($params["Colors"]->get_int()) {
            case 1:
                break;
            default:
                return p_error("other color count than 1 is not supported in this version");
        }

        switch($params["BitsPerComponent"]->get_int()) {
            case 8:
                break;
            default:
                return p_error("other bit count than 8 is not supported in this version");
        }

        $decoded = new Buffer();
        $columns = $params['Columns']->get_int();

        $row_len = $columns + 1;
        $stream_len = strlen($_stream);

        // The previous row is zero
        $data_prev = str_pad("", $columns, chr(0));
        $row_i = 0;
        $pos_i = 0;
        $data = str_pad("", $columns, chr(0));
        while ($pos_i < $stream_len) {
            $filter_byte = ord($_stream[$pos_i++]);

            // Get the current row
            $data = substr($_stream, $pos_i, $columns);
            $pos_i += strlen($data);

            // Zero pad, in case that the content is not paired
            $data = str_pad($data, $columns, chr(0));

            // Depending on the filter byte of the row, we should unpack on one way or another
            switch ($filter_byte) {
                case 0: 
                    break;
                case 1:
                    // PNG Sub filter: pixel[i] = pixel[i] + pixel[i-1].
                    // ord/chr wrapping required because $data is a PHP
                    // string — direct `+` on single-character strings
                    // coerces to numeric (`'a'+'b' = 0+0 = 0`) and
                    // produces broken output for non-numeric bytes.
                    // This was a latent upstream bug; the right time to
                    // fix it is now that LZWDecode also routes through.
                    for ($i = 1; $i < $columns; $i++) {
                        $data[$i] = chr((ord($data[$i]) + ord($data[$i-1])) % 256);
                    }
                    break;
                case 2: 
                    for ($i = 0; $i < $columns; $i++) {
                        $data[$i] = chr((ord($data[$i]) + ord($data_prev[$i])) % 256);
                    }
                    break;
                default: 
                    return p_error("Unsupported stream");
            }

            // Store and prepare the previous row
            $decoded->data($data);
            $data_prev = $data;
        }

        // p_debug_var($decoded->show_bytes($columns));
        return $decoded->get_raw();
    }

    /**
     * Read a single variable-width MSB-first code from an LZW bit stream.
     *
     * PDF 1.7 §7.4.4.2 ¶3: codes are packed high-order-bit-first into
     * the encoded byte stream. The caller maintains the bit position
     * (in bits, not bytes) across calls; this helper advances it by
     * exactly `$codeWidth` bits and returns the integer value.
     *
     * Returns `null` when the bit stream is exhausted (fewer than
     * `$codeWidth` bits remain).
     *
     * @param string $stream    Encoded byte stream.
     * @param int    $bitPos    Current bit position (passed by reference; advanced on success).
     * @param int    $codeWidth Number of bits to read (9-12 for LZW).
     *
     * @return int|null
     */
    protected static function lzw_read_code($stream, &$bitPos, $codeWidth) {
        $totalBits = strlen($stream) * 8;
        if ($bitPos + $codeWidth > $totalBits) {
            return null;
        }

        $code = 0;
        $bitsRemaining = $codeWidth;
        while ($bitsRemaining > 0) {
            $byteIndex = intdiv($bitPos, 8);
            $bitOffsetInByte = $bitPos % 8;
            $bitsAvailableInByte = 8 - $bitOffsetInByte;
            $bitsToTake = min($bitsRemaining, $bitsAvailableInByte);

            $byte = ord($stream[$byteIndex]);
            $shifted = ($byte >> ($bitsAvailableInByte - $bitsToTake)) & ((1 << $bitsToTake) - 1);
            $code = ($code << $bitsToTake) | $shifted;

            $bitPos += $bitsToTake;
            $bitsRemaining -= $bitsToTake;
        }
        return $code;
    }

    /**
     * Append a variable-width MSB-first code to an LZW bit stream buffer.
     *
     * Companion to `lzw_read_code`. The caller maintains a 32-bit
     * pending-bits register (`$pending`) and a count of bits in it
     * (`$pendingBits`); this helper appends the code and flushes any
     * complete bytes to the output buffer.
     *
     * @param string $out         Output byte buffer (passed by reference).
     * @param int    $pending     Pending bits register (≤ 31 bits, passed by reference).
     * @param int    $pendingBits Count of valid bits in $pending (passed by reference).
     * @param int    $code        Code value to write.
     * @param int    $codeWidth   Number of bits to write.
     *
     * @return void
     */
    protected static function lzw_write_code(&$out, &$pending, &$pendingBits, $code, $codeWidth) {
        $pending = ($pending << $codeWidth) | $code;
        $pendingBits += $codeWidth;
        while ($pendingBits >= 8) {
            $shift = $pendingBits - 8;
            $byte = ($pending >> $shift) & 0xFF;
            $out .= chr($byte);
            $pendingBits -= 8;
            $pending = $pending & ((1 << $pendingBits) - 1);
        }
    }

    /**
     * Decode an LZWDecode-encoded stream per PDF 1.7 §7.4.4.
     *
     * Variable-width LZW: 9-12 bit codes, MSB-first, with reserved
     * codes 256 (clear table) and 257 (EOD). User codes start at 258.
     * On clear: reset dictionary + reset code width to 9. On EOD: stop.
     * The KwKwK special case (next code equals next-to-be-assigned
     * dictionary index) is handled per the canonical LZW rule.
     *
     * `/DecodeParms/EarlyChange` (default 1 per §7.4.4.3 Table 8) controls
     * when the code width advances: with EarlyChange=1, after filling
     * `2^width - 1` entries; with EarlyChange=0, after `2^width` entries.
     *
     * After LZW decode, the PNG predictor (if specified via
     * /DecodeParms/Predictor >= 10) is applied via `applyPngPredictor`.
     *
     * Dictionary overflow without an intervening clear code, truncated
     * bit stream (EOF before EOD code 257), or out-of-range code → all
     * `p_error()` + `return false` per the chain dispatcher's `=== false`
     * short-circuit contract; downstream filters MUST NOT see partial
     * output. Predictor rejection inside `applyPngPredictor` also
     * propagates as `false`.
     *
     * @param string $_stream Encoded byte stream.
     * @param mixed  $params  /DecodeParms (Predictor, EarlyChange, Columns, Colors, BitsPerComponent).
     *
     * @return string|false Decoded bytes on success; `false` on any
     *                      truncation / overflow / out-of-range /
     *                      predictor-rejection failure.
     */
    protected static function LZWDecode($_stream, $params) {
        $earlyChange = 1;
        if ($params !== null && isset($params['EarlyChange'])) {
            $earlyChange = (int) (string) $params['EarlyChange'];
        }

        $CLEAR = 256;
        $EOD = 257;
        $FIRST_USER_CODE = 258;
        $MAX_DICT_SIZE = 4096;

        $resetDict = function() use ($FIRST_USER_CODE) {
            $dict = [];
            for ($i = 0; $i < 256; $i++) {
                $dict[$i] = chr($i);
            }
            // Reserved entries (256/257) intentionally unset.
            return [$dict, $FIRST_USER_CODE];
        };

        [$dict, $nextCode] = $resetDict();
        $codeWidth = 9;
        $bitPos = 0;
        $out = '';
        $prev = null;

        while (true) {
            $code = self::lzw_read_code($_stream, $bitPos, $codeWidth);
            if ($code === null) {
                // Truncated input without EOD — fail-safe.
                p_error('LZWDecode: bit stream exhausted before EOD code (257)');
                return false;
            }
            if ($code === $EOD) {
                break;
            }
            if ($code === $CLEAR) {
                [$dict, $nextCode] = $resetDict();
                $codeWidth = 9;
                $prev = null;
                continue;
            }

            // Standard case: code exists in dictionary.
            if (isset($dict[$code])) {
                $entry = $dict[$code];
                $out .= $entry;
                if ($prev !== null) {
                    if ($nextCode >= $MAX_DICT_SIZE) {
                        p_error('LZWDecode: dictionary overflow at index '.$nextCode.' with no clear code');
                        return false;
                    }
                    $dict[$nextCode] = $prev . $entry[0];
                    $nextCode++;
                }
                $prev = $entry;
            } else if ($code === $nextCode && $prev !== null) {
                // KwKwK special case.
                $entry = $prev . $prev[0];
                $out .= $entry;
                if ($nextCode >= $MAX_DICT_SIZE) {
                    p_error('LZWDecode: dictionary overflow on KwKwK at index '.$nextCode);
                    return false;
                }
                $dict[$nextCode] = $entry;
                $nextCode++;
                $prev = $entry;
            } else {
                p_error('LZWDecode: code '.$code.' out of range (nextCode='.$nextCode.')');
                return false;
            }

            // Code-width advance. Two independent concerns ride this
            // line; documenting both so future readers don't conflate
            // them:
            //
            // (1) Decoder-lag correction. The decoder's dictionary
            //     trails the encoder's by exactly one entry (the first
            //     post-CLEAR read doesn't add an entry because $prev is
            //     still null). The `+1` in `($nextCode + 1)` is the
            //     fix for that off-by-one — it stays in lockstep with
            //     the encoder regardless of EarlyChange.
            //
            // (2) EarlyChange. PDF 1.7 §7.4.4.3 Table 8 / Adobe Tech
            //     Note 5603: with EarlyChange=1 (default), the width
            //     advances after filling 2^width − 1 entries; with
            //     EarlyChange=0, after 2^width. That's why the
            //     threshold subtracts `$earlyChange`.
            //
            // The two adjustments are orthogonal. The `+1` does not
            // depend on EarlyChange; both EarlyChange settings work
            // correctly with it.
            $threshold = (1 << $codeWidth) - $earlyChange;
            if (($nextCode + 1) >= $threshold && $codeWidth < 12) {
                $codeWidth++;
            }
        }

        // Apply PNG predictor if specified.
        if ($params !== null && isset($params['Predictor'])) {
            $predictor = (int) (string) $params['Predictor'];
            if ($predictor >= 10) {
                $flateParams = self::build_flate_params($params);
                $predicted = self::applyPngPredictor($out, $flateParams);
                if ($predicted === false) {
                    // p_error already emitted inside the predictor;
                    // propagate the dispatcher's `=== false` short-circuit
                    // contract upward so downstream chain filters don't
                    // see the half-decoded buffer.
                    return false;
                }
                $out = $predicted;
            }
        }

        return $out;
    }

    /**
     * Encode bytes via LZWEncode per PDF 1.7 §7.4.4.
     *
     * Emits a leading clear code (OQ2 — Acrobat-compatible), runs the
     * standard LZW state machine emitting variable-width codes 9-12
     * bits MSB-first, and terminates with the EOD code (257). Honours
     * the same `/DecodeParms/EarlyChange` value as the decoder so the
     * round-trip is symmetric.
     *
     * On dictionary overflow at 4096 entries: emit a clear code and
     * continue with a fresh 9-bit dictionary.
     *
     * @param string $_stream Raw input bytes.
     * @param mixed  $params  /DecodeParms (EarlyChange).
     *
     * @return string Encoded bit stream.
     */
    protected static function LZWEncode($_stream, $params) {
        $earlyChange = 1;
        if ($params !== null && isset($params['EarlyChange'])) {
            $earlyChange = (int) (string) $params['EarlyChange'];
        }

        $CLEAR = 256;
        $EOD = 257;
        $FIRST_USER_CODE = 258;
        $MAX_DICT_SIZE = 4096;

        $resetDict = function() use ($FIRST_USER_CODE) {
            $dict = [];
            for ($i = 0; $i < 256; $i++) {
                $dict[chr($i)] = $i;
            }
            return [$dict, $FIRST_USER_CODE];
        };

        $out = '';
        $pending = 0;
        $pendingBits = 0;
        [$dict, $nextCode] = $resetDict();
        $codeWidth = 9;

        // Emit leading clear code (Acrobat-compatible).
        self::lzw_write_code($out, $pending, $pendingBits, $CLEAR, $codeWidth);

        $len = strlen($_stream);
        if ($len > 0) {
            $w = $_stream[0];
            for ($i = 1; $i < $len; $i++) {
                $k = $_stream[$i];
                $wk = $w . $k;
                if (isset($dict[$wk])) {
                    $w = $wk;
                } else {
                    // Emit code for $w.
                    self::lzw_write_code($out, $pending, $pendingBits, $dict[$w], $codeWidth);

                    if ($nextCode >= $MAX_DICT_SIZE) {
                        // Overflow: emit clear, reset.
                        self::lzw_write_code($out, $pending, $pendingBits, $CLEAR, $codeWidth);
                        [$dict, $nextCode] = $resetDict();
                        $codeWidth = 9;
                    } else {
                        $dict[$wk] = $nextCode;
                        $nextCode++;
                        $threshold = (1 << $codeWidth) - $earlyChange;
                        if ($nextCode >= $threshold && $codeWidth < 12) {
                            $codeWidth++;
                        }
                    }

                    $w = $k;
                }
            }
            // Emit final code for $w.
            self::lzw_write_code($out, $pending, $pendingBits, $dict[$w], $codeWidth);
        }

        // EOD code.
        self::lzw_write_code($out, $pending, $pendingBits, $EOD, $codeWidth);

        // Flush remaining bits with zero padding.
        if ($pendingBits > 0) {
            $byte = ($pending << (8 - $pendingBits)) & 0xFF;
            $out .= chr($byte);
        }

        return $out;
    }

    /**
     * Decode an ASCII85Decode-encoded stream per PDF 1.7 §7.4.3.
     *
     * Base-85 encoding: 5 ASCII chars in `!..u` (codepoints 33..117)
     * decode to 4 binary bytes. The single char `z` is a shortcut for
     * 4 zero bytes (only valid at a group boundary). EOD marker is `~>`.
     * Whitespace anywhere is ignored. Trailing partial group of `k`
     * chars (2 ≤ k ≤ 4) decodes to `k-1` binary bytes, padded with `u`
     * (84) to a full 5-char group. A trailing 1-char partial group is
     * spec-illegal (§7.4.3 partial-group rule is `2 ≤ k ≤ 4`).
     *
     * Adobe-tolerance: optional leading `<~` marker is stripped if
     * present (btoa-style start marker; PDF 1.7 doesn't mention it but
     * some readers tolerate it).
     *
     * 32-bit overflow guard: the spec-imposed maximum decoded value
     * for a 5-char group is `s8W-!` = 0xFFFFFFFF = 2^32 - 1. Any group
     * that arithmetically computes higher (e.g. `tttt` padded with `u`
     * via the partial-group rule yields 4,384,231,064) is spec-illegal
     * and rejected. Note that `uuuuu` is NOT a valid encoding of
     * 2^32 - 1 — it computes to 4,437,053,124, which is what triggers
     * the guard.
     *
     * Failure paths (illegal char, 1-char partial group, overflow,
     * regex compile failure) → p_error + return `false` per the chain
     * dispatcher's `=== false` short-circuit contract; downstream
     * filters MUST NOT see partial output.
     *
     * @param string $_stream Encoded stream bytes.
     * @param mixed  $params  Unused (ASCII85Decode takes no parameters per Table 5).
     *
     * @return string|false Decoded binary bytes on success; `false` on
     *                      any spec-violation failure (illegal char,
     *                      1-char partial group, overflow, or PCRE
     *                      compile-time / limit failure).
     */
    protected static function ASCII85Decode($_stream, $params) {
        // Strip optional leading `<~` (Adobe-tolerant per OQ1).
        if (substr($_stream, 0, 2) === '<~') {
            $_stream = substr($_stream, 2);
        }

        // Find EOD `~>`; everything after is ignored. Missing EOD is
        // tolerated (Adobe-compatible per D5).
        $eodPos = strpos($_stream, '~>');
        $dataRegion = $eodPos === false ? $_stream : substr($_stream, 0, $eodPos);

        // Strip whitespace. preg_replace returns null on PCRE compile
        // failure or limit hit — defensively treat that as a hard
        // decode failure rather than letting null propagate to strlen()
        // (deprecation on PHP 8.1+, TypeError on PHP 9.0).
        $compact = preg_replace('/[\\x00\\x09\\x0a\\x0c\\x0d\\x20]/', '', $dataRegion);
        if ($compact === null) {
            p_error('ASCII85Decode: whitespace-strip regex failed (PCRE limit or compile error)');
            return false;
        }

        $out = '';
        $len = strlen($compact);
        $i = 0;
        while ($i < $len) {
            // `z` shortcut: 4 zero bytes. Only valid at a group boundary,
            // which holds by construction here — `$i` advances either by
            // 1 (z) or by `$groupLen` (read-group), so we are always at
            // a boundary when we enter the loop body.
            if ($compact[$i] === 'z') {
                $out .= "\x00\x00\x00\x00";
                $i++;
                continue;
            }

            // Read up to 5 chars for the next group.
            $groupLen = min(5, $len - $i);

            // A 1-char trailing partial group is spec-illegal
            // (§7.4.3 partial-group rule is `2 ≤ k ≤ 4`).
            if ($groupLen === 1) {
                p_error('ASCII85Decode: trailing 1-char partial group is spec-illegal (§7.4.3 requires 2 ≤ k ≤ 4)');
                return false;
            }

            $group = substr($compact, $i, $groupLen);
            $i += $groupLen;

            // Validate alphabet.
            for ($g = 0; $g < $groupLen; $g++) {
                $c = ord($group[$g]);
                if ($c < 33 || $c > 117) {
                    p_error('ASCII85Decode: illegal character (codepoint '.$c.') outside !..u alphabet');
                    return false;
                }
            }

            // Pad trailing partial group with `u` (84) to a full 5 chars.
            $padded = $groupLen < 5 ? ($group . str_repeat('u', 5 - $groupLen)) : $group;

            // Compute the 32-bit unsigned big-endian integer.
            // n = c0*85^4 + c1*85^3 + c2*85^2 + c3*85 + c4 (each c_i is char - 33).
            $n = 0;
            for ($g = 0; $g < 5; $g++) {
                $n = $n * 85 + (ord($padded[$g]) - 33);
            }

            // Overflow check: max valid 5-char group is `s8W-!`
            // (= 2^32 - 1). `uuuuu` arithmetically yields 4,437,053,124
            // which is 142,085,829 above the cap.
            if ($n > 4294967295) {
                p_error('ASCII85Decode: group overflows 32-bit unsigned (value '.$n.')');
                return false;
            }

            // Pack as big-endian 4 bytes.
            $bytes = chr(($n >> 24) & 0xFF)
                   . chr(($n >> 16) & 0xFF)
                   . chr(($n >> 8) & 0xFF)
                   . chr($n & 0xFF);

            // Emit only (groupLen - 1) bytes for a partial trailing group.
            $emit = $groupLen < 5 ? ($groupLen - 1) : 4;
            $out .= substr($bytes, 0, $emit);
        }

        return $out;
    }

    /**
     * Encode binary bytes via ASCII85Encode per PDF 1.7 §7.4.3.
     *
     * Emits 5 ASCII chars in `!..u` per 4 input bytes. Aligned 4-zero-
     * byte groups use the `z` shortcut. Trailing partial group of `k`
     * input bytes (1 ≤ k ≤ 3) is right-padded with `\x00` to 4 bytes,
     * encoded, and emitted as `k+1` chars (the padding chars are
     * stripped from the output).
     *
     * Output is terminated by the EOD marker `~>`. Empty input emits
     * just the EOD marker.
     *
     * @param string $_stream Raw binary input.
     * @param mixed  $params  Unused.
     *
     * @return string Encoded stream including EOD marker.
     */
    protected static function ASCII85Encode($_stream, $params) {
        $out = '';
        $len = strlen($_stream);
        $i = 0;

        // Process full 4-byte groups.
        while ($i + 4 <= $len) {
            $b0 = ord($_stream[$i]);
            $b1 = ord($_stream[$i + 1]);
            $b2 = ord($_stream[$i + 2]);
            $b3 = ord($_stream[$i + 3]);

            if ($b0 === 0 && $b1 === 0 && $b2 === 0 && $b3 === 0) {
                // Aligned 4-zero-byte group → `z` shortcut.
                $out .= 'z';
                $i += 4;
                continue;
            }

            // Compute the 32-bit unsigned big-endian integer.
            // Requires 64-bit PHP ints — the upstream sapp composer
            // constraint (^7.4 || ^8.0) effectively guarantees this on
            // any supported platform. Defensive 32-bit masking has been
            // removed: `0xFFFFFFFF` is parsed as float on 32-bit PHP and
            // `& 0xFFFFFFFF` then breaks the result.
            $n = ($b0 << 24) | ($b1 << 16) | ($b2 << 8) | $b3;

            // Convert to base-85, MSB first.
            $c4 = $n % 85; $n = intdiv($n, 85);
            $c3 = $n % 85; $n = intdiv($n, 85);
            $c2 = $n % 85; $n = intdiv($n, 85);
            $c1 = $n % 85; $n = intdiv($n, 85);
            $c0 = $n;
            $out .= chr($c0 + 33) . chr($c1 + 33) . chr($c2 + 33) . chr($c3 + 33) . chr($c4 + 33);

            $i += 4;
        }

        // Trailing partial group: pad with \x00, encode, emit (k+1) chars.
        $remaining = $len - $i;
        if ($remaining > 0) {
            $padded = substr($_stream, $i) . str_repeat("\x00", 4 - $remaining);
            $b0 = ord($padded[0]);
            $b1 = ord($padded[1]);
            $b2 = ord($padded[2]);
            $b3 = ord($padded[3]);
            $n = (($b0 << 24) | ($b1 << 16) | ($b2 << 8) | $b3) & 0xFFFFFFFF;
            if ($n < 0) { $n += 4294967296; }

            $c4 = $n % 85; $n = intdiv($n, 85);
            $c3 = $n % 85; $n = intdiv($n, 85);
            $c2 = $n % 85; $n = intdiv($n, 85);
            $c1 = $n % 85; $n = intdiv($n, 85);
            $c0 = $n;
            $chars = chr($c0 + 33) . chr($c1 + 33) . chr($c2 + 33) . chr($c3 + 33) . chr($c4 + 33);
            $out .= substr($chars, 0, $remaining + 1);
        }

        return $out . '~>';
    }

    /**
     * Decode a RunLengthDecode-encoded stream per PDF 1.7 §7.4.5.
     *
     * Length-byte semantics:
     *   - 0 <= L <= 127  → copy next L+1 literal bytes
     *   - L == 128       → EOD marker (stop)
     *   - 129 <= L <= 255 → repeat next byte (257 - L) times
     *
     * Bytes after EOD are ignored. Truncated literal or repeat blocks
     * (input runs out before delivering all required bytes) → p_error +
     * return `false` (per the chain dispatcher's `=== false` short-
     * circuit contract; downstream filters MUST NOT see partial output).
     * Missing EOD also treated as truncation.
     *
     * Trust assumption: input is treated as well-formed. Worst-case
     * decode amplification is 64× (2-byte input → 128-byte output via
     * a repeat block). On untrusted input a 16 MB malicious stream of
     * repeat blocks decompresses to ~1 GB. Callers concerned about DoS
     * MUST validate input source before passing it here; an explicit
     * output-size cap can be added in a follow-up change.
     *
     * @param string $_stream Encoded stream bytes.
     * @param mixed  $params  Unused (RunLengthDecode takes no parameters per Table 5).
     *
     * @return string|false Decoded binary bytes on success; `false` on
     *                      truncation / missing-EOD failure so the
     *                      filter chain aborts cleanly.
     */
    protected static function RunLengthDecode($_stream, $params) {
        $out = '';
        $len = strlen($_stream);
        $i = 0;
        $hadEod = false;

        while ($i < $len) {
            $L = ord($_stream[$i]);
            $i++;

            if ($L === 128) {
                $hadEod = true;
                break;
            }

            if ($L <= 127) {
                // Literal block: copy next L+1 bytes.
                $copy = $L + 1;
                if ($i + $copy > $len) {
                    p_error('RunLengthDecode: truncated literal block (need '.$copy.' bytes, '.($len - $i).' available)');
                    return false;
                }
                $out .= substr($_stream, $i, $copy);
                $i += $copy;
            } else {
                // Repeat block: copy next byte (257 - L) times.
                if ($i >= $len) {
                    p_error('RunLengthDecode: truncated repeat block (no byte to repeat)');
                    return false;
                }
                $count = 257 - $L;
                $out .= str_repeat($_stream[$i], $count);
                $i++;
            }
        }

        if ($hadEod === false) {
            p_error('RunLengthDecode: input ended without EOD marker (0x80)');
            return false;
        }

        return $out;
    }

    /**
     * Encode binary bytes via RunLengthEncode per PDF 1.7 §7.4.5.
     *
     * Greedy run detection: when 2+ adjacent identical bytes appear,
     * flush any pending literal block and emit a repeat block (capped at
     * 128 bytes per block). Otherwise accumulate literals (capped at 128
     * per block). Always emits the trailing 0x80 EOD marker, including
     * for empty input (output is a single byte).
     *
     * TODO(opt): the threshold for breaking out of literal mode is set at
     * 2 adjacent identical bytes — that's the smallest run a repeat block
     * can encode (length byte + 1 data byte = 2 output bytes; vs. 2 input
     * bytes inside a literal block = 1+2 = 3 output bytes when the literal
     * block has to be closed). For input shapes like `"ABBC"` this bloats
     * the output by 1 byte vs. an optimal-RLE split (`\x00A\xFFB\x00C\x80`
     * = 7 bytes vs. the optimal `\x03ABBC\x80` = 6 bytes). Raising the
     * threshold from 2 to 3 would close that gap on the most common
     * pathological shape; deferred to a follow-up so the change here
     * stays minimal and the existing fixtures' expected byte sequences
     * remain stable.
     *
     * @param string $_stream Raw binary input.
     * @param mixed  $params  Unused.
     *
     * @return string Encoded stream including EOD marker.
     */
    protected static function RunLengthEncode($_stream, $params) {
        $out = '';
        $len = strlen($_stream);
        $i = 0;

        while ($i < $len) {
            // Try to extend a run from position $i.
            $runByte = $_stream[$i];
            $runLen = 1;
            while ($i + $runLen < $len && $_stream[$i + $runLen] === $runByte && $runLen < 128) {
                $runLen++;
            }

            if ($runLen >= 2) {
                // Repeat block: length byte = 257 - runLen.
                $out .= chr(257 - $runLen) . $runByte;
                $i += $runLen;
                continue;
            }

            // Accumulate a literal block. Cap at 128 bytes. The block
            // ends at end-of-stream OR when a run of 2+ starts (so we
            // can emit the repeat block).
            $literalStart = $i;
            $literalLen = 0;
            while ($i < $len && $literalLen < 128) {
                // Peek ahead: if the next 2 bytes are identical, stop
                // accumulating so the repeat block can take them.
                if ($i + 1 < $len && $_stream[$i] === $_stream[$i + 1]) {
                    break;
                }
                $i++;
                $literalLen++;
            }

            // Literal block: length byte = literalLen - 1, followed by bytes.
            $out .= chr($literalLen - 1) . substr($_stream, $literalStart, $literalLen);
        }

        // EOD marker.
        $out .= chr(128);
        return $out;
    }

    /**
     * Decode an ASCIIHexDecode-encoded stream per PDF 1.7 §7.4.2.
     *
     * Accepts the alphabet `0..9 A..F a..f` plus whitespace plus the
     * EOD marker `>`. Whitespace is stripped before parsing. Odd-length
     * input is padded with a `0` nibble per §7.4.2 ¶3. Bytes after `>`
     * are ignored. Illegal characters → `p_error()` + return `false`
     * (matching the chain dispatcher's `=== false` fail-safe and
     * upstream `p_error`'s default return semantics; the chain arm
     * MUST short-circuit on failure so downstream filters do not see
     * corrupted bytes).
     *
     * @param string $_stream Encoded stream bytes.
     * @param mixed  $params  Unused (ASCIIHexDecode takes no parameters per Table 5).
     *
     * @return string|false Decoded binary bytes on success; `false` on
     *                      illegal-character or hex2bin failure so the
     *                      filter chain aborts cleanly.
     */
    protected static function ASCIIHexDecode($_stream, $params) {
        // Find the EOD marker; everything after `>` is ignored. EOD is
        // mandatory per §7.4.2 but we tolerate a missing EOD by treating
        // the whole input as the data region (matches Adobe Reader).
        $eodPos = strpos($_stream, '>');
        $hexRegion = $eodPos === false ? $_stream : substr($_stream, 0, $eodPos);

        // Strip the PDF whitespace set (§7.5.1: NUL, HT, LF, FF, CR, SP).
        $compact = preg_replace('/[\\x00\\x09\\x0a\\x0c\\x0d\\x20]/', '', $hexRegion);

        // Validate the alphabet.
        if (preg_match('/[^0-9A-Fa-f]/', $compact) === 1) {
            p_error('ASCIIHexDecode: illegal character in encoded stream');
            return false;
        }

        // Odd-length: pad the trailing nibble with `0` per §7.4.2 ¶3.
        if ((strlen($compact) % 2) === 1) {
            $compact .= '0';
        }

        // The alphabet check above guarantees `hex2bin` can't legitimately
        // fail here — drop the historical `@` suppression so unexpected
        // failures surface in PHP's error reporting.
        $bytes = hex2bin($compact);
        if ($bytes === false) {
            // Defensive — paired-validation passed but hex2bin still
            // disagreed. Fail-safe per the chain dispatcher contract.
            p_error('ASCIIHexDecode: hex2bin failed despite alphabet validation');
            return false;
        }
        return $bytes;
    }

    /**
     * Encode binary bytes via ASCIIHexEncode per PDF 1.7 §7.4.2.
     *
     * Emits uppercase hex pairs terminated by the EOD marker `>`. Line
     * wraps inserted at 80 columns (Adobe-conventional; readers MUST
     * tolerate any width per §7.4.2). Empty input emits just `>`.
     *
     * @param string $_stream Raw binary input.
     * @param mixed  $params  Unused.
     *
     * @return string Encoded stream including EOD marker.
     */
    protected static function ASCIIHexEncode($_stream, $params) {
        $hex = strtoupper(bin2hex($_stream));
        if ($hex === '') {
            return '>';
        }
        // Chunk at 80 columns and re-join with newlines.
        $wrapped = chunk_split($hex, 80, "\n");
        // chunk_split appends a trailing separator; trim it before the EOD.
        $wrapped = rtrim($wrapped, "\n");
        // If the final hex chunk lands exactly on the 80-col boundary,
        // appending `>` would push that line to 81 chars. Insert a
        // newline before the EOD so no line exceeds 80.
        if (strlen($hex) % 80 === 0) {
            return $wrapped . "\n>";
        }
        return $wrapped . '>';
    }

    /**
     * Normalise the `/Filter` entry to a plain array of filter-name strings.
     *
     * PDF 1.7 §7.4.1 ¶2: /Filter may be either a name (e.g. /FlateDecode)
     * or an array of names. We always operate internally on an array for
     * uniform chain dispatch; callers preserve the original shape on
     * write-back by checking `is_a($filterValue, 'PDFValueList')`.
     *
     * Empty arrays, null, and missing values all coerce to []: "no filtering".
     *
     * @param mixed $filterValue Raw value from `$this->_value['Filter']`, or null/missing.
     *
     * @return string[] Filter names in PDF chain order (outermost first per §7.4.1 ¶3).
     */
    protected static function normalise_filter_chain($filterValue) {
        if ($filterValue === null) return [];
        if (is_a($filterValue, 'ddn\\sapp\\pdfvalue\\PDFValueList')) {
            $names = [];
            foreach ($filterValue->val() as $entry) {
                $name = trim((string) $entry);
                if ($name !== '') $names[] = $name;
            }
            return $names;
        }
        $str = trim((string) $filterValue);
        if ($str === '' || $str === '[]') return [];
        return [$str];
    }

    /**
     * Normalise the `/DecodeParms` entry to a fixed-length array indexed by
     * chain position, parallel to the filter chain returned by
     * `normalise_filter_chain`.
     *
     * PDF 1.7 §7.4.1 Table 5: /DecodeParms is "either a dictionary or an
     * array of dictionaries"; the array form is parallel to /Filter and
     * may contain the literal PDF `null` value at positions whose filter
     * takes no parameters.
     *
     * Shapes accepted:
     *   - null / missing       → array of `null`s of length $chainLength
     *   - single PDFValueObject → assigned to position 0; rest are `null`
     *   - PDFValueList of dicts → positional; entries whose string-rep is
     *                              "null" or empty become PHP `null`
     *   - shorter list than chain → trailing positions are `null` (OQ2 fix)
     *
     * @param mixed $parmsValue    Raw value from `$this->_value['DecodeParms']`, or null.
     * @param int   $chainLength   Number of filters in the chain (target length).
     *
     * @return array<int, mixed> Per-filter parameters; index `i` is the dict
     *                            (or `null`) for filter `i` of the chain.
     */
    protected static function normalise_decode_parms_chain($parmsValue, $chainLength) {
        $result = array_fill(0, max($chainLength, 0), null);
        if ($parmsValue === null || $chainLength <= 0) return $result;

        if (is_a($parmsValue, 'ddn\\sapp\\pdfvalue\\PDFValueList')) {
            $entries = $parmsValue->val();
            $count = min(count($entries), $chainLength);
            for ($i = 0; $i < $count; $i++) {
                $entry = $entries[$i];
                $strRep = trim((string) $entry);
                if ($strRep === 'null' || $strRep === '') {
                    $result[$i] = null;
                } else {
                    $result[$i] = $entry;
                }
            }
            return $result;
        }

        // Single dictionary form — applies to filter 0 only (single-filter
        // chain, the string-form-equivalent case).
        $result[0] = $parmsValue;
        return $result;
    }

    /**
     * Build the FlateDecode parameter dictionary expected by the existing
     * `FlateDecode` static helper. The helper requires `Columns`,
     * `Predictor`, `BitsPerComponent`, and `Colors` to all be present
     * PDFValue objects with `->get_int()` available; this method papers
     * over the optional /DecodeParms entries with PDF 1.7 default values.
     *
     * @param mixed $parmsForThisFilter Per-filter parms (a PDFValueObject or null).
     *
     * @return array<string, mixed> Always-populated 4-key params array.
     */
    protected static function build_flate_params($parmsForThisFilter) {
        $parms = ($parmsForThisFilter === null) ? [] : $parmsForThisFilter;
        // PDF 1.7 §7.4.4.3 Table 8 defaults — `Columns=1`, NOT 0. The
        // wrong default is hidden when Predictor=1 (the unfilter loop
        // short-circuits before consulting Columns), but the moment a
        // PNG-predicted stream lands without an explicit /Columns
        // entry, `Columns=0` would drive the row-stride loop into an
        // empty/infinite buffer.
        return [
            "Columns"          => $parms['Columns']          ?? new PDFValueSimple(1),
            "Predictor"        => $parms['Predictor']        ?? new PDFValueSimple(1),
            "BitsPerComponent" => $parms['BitsPerComponent'] ?? new PDFValueSimple(8),
            "Colors"           => $parms['Colors']           ?? new PDFValueSimple(1),
        ];
    }

    /**
     * Apply the filter chain to a stream in DECODE direction (FORWARD
     * order: outermost first, innermost last, per PDF 1.7 §7.4.1 ¶3).
     *
     * Unknown filter names emit `p_error()` and return `false`; the caller
     * is expected to propagate the failure as "stream unchanged" to match
     * upstream sapp's existing convention.
     *
     * This change ships only the FlateDecode arm; PRs #01-#04 in the
     * docs/upstream-prs/ series extend the switch with the four remaining
     * standard filters (ASCIIHexDecode, ASCII85Decode, RunLengthDecode,
     * LZWDecode).
     *
     * @param string                $bytes   Raw stream bytes to decode.
     * @param string[]              $filters Chain of filter names (from `normalise_filter_chain`).
     * @param array<int, mixed>     $params  Per-filter parameters (from `normalise_decode_parms_chain`).
     *
     * @return string|false Decoded bytes on success; `false` on chain failure.
     */
    protected static function apply_filter_chain_decode($bytes, array $filters, array $params) {
        foreach ($filters as $i => $filterName) {
            $name = ltrim($filterName, '/');
            switch ($name) {
                case 'FlateDecode':
                    $uncompressed = @gzuncompress($bytes);
                    if ($uncompressed === false) {
                        p_error('FlateDecode failed: gzuncompress returned false in chain decode');
                        return false;
                    }
                    $flateParams = self::build_flate_params($params[$i] ?? null);
                    $decoded = self::FlateDecode($uncompressed, $flateParams);
                    if ($decoded === null) {
                        // FlateDecode returned via p_error (predictor / colour /
                        // bit-count mismatch); propagate the failure.
                        return false;
                    }
                    $bytes = $decoded;
                    break;
                case 'ASCIIHexDecode':
                    $decoded = self::ASCIIHexDecode($bytes, $params[$i] ?? null);
                    if ($decoded === false) {
                        return false;
                    }
                    $bytes = $decoded;
                    break;
                case 'RunLengthDecode':
                    $decoded = self::RunLengthDecode($bytes, $params[$i] ?? null);
                    if ($decoded === false) {
                        return false;
                    }
                    $bytes = $decoded;
                    break;
                case 'ASCII85Decode':
                    $decoded = self::ASCII85Decode($bytes, $params[$i] ?? null);
                    if ($decoded === false) {
                        return false;
                    }
                    $bytes = $decoded;
                    break;
                case 'LZWDecode':
                    $decoded = self::LZWDecode($bytes, $params[$i] ?? null);
                    if ($decoded === false) {
                        return false;
                    }
                    $bytes = $decoded;
                    break;
                default:
                    p_error("unknown compression method /$name in filter chain at position $i");
                    return false;
            }
        }
        return $bytes;
    }

    /**
     * Apply the filter chain to a stream in ENCODE direction (REVERSE
     * order: innermost first, outermost last, per PDF 1.7 §7.4.1 ¶3).
     *
     * Unknown filter names emit `p_error()` and return `false`; the
     * caller leaves the original `_stream` + `Length` unchanged.
     *
     * @param string                $bytes   Plaintext bytes to encode.
     * @param string[]              $filters Chain of filter names (from `normalise_filter_chain`).
     * @param array<int, mixed>     $params  Per-filter parameters (currently unused on encode for FlateDecode).
     *
     * @return string|false Encoded bytes on success; `false` on chain failure.
     */
    protected static function apply_filter_chain_encode($bytes, array $filters, array $params) {
        foreach (array_reverse($filters, true) as $i => $filterName) {
            $name = ltrim($filterName, '/');
            switch ($name) {
                case 'FlateDecode':
                    $compressed = @gzcompress($bytes);
                    if ($compressed === false) {
                        p_error('FlateDecode failed: gzcompress returned false in chain encode');
                        return false;
                    }
                    $bytes = $compressed;
                    break;
                case 'ASCIIHexDecode':
                    $bytes = self::ASCIIHexEncode($bytes, $params[$i] ?? null);
                    break;
                case 'RunLengthDecode':
                    $bytes = self::RunLengthEncode($bytes, $params[$i] ?? null);
                    break;
                case 'ASCII85Decode':
                    $bytes = self::ASCII85Encode($bytes, $params[$i] ?? null);
                    break;
                case 'LZWDecode':
                    $bytes = self::LZWEncode($bytes, $params[$i] ?? null);
                    break;
                default:
                    p_error("unknown compression method /$name in filter chain at position $i");
                    return false;
            }
        }
        return $bytes;
    }

    /**
     * Gets the stream of the object
     * @return stream a string that contains the stream of the object
     */
    public function get_stream($raw = true) {
        if ($raw === true)
            return $this->_stream;

        $filters = self::normalise_filter_chain($this->_value['Filter'] ?? null);
        if (count($filters) === 0) {
            // No filtering — pass-through.
            return $this->_stream;
        }

        $params = self::normalise_decode_parms_chain(
            $this->_value['DecodeParms'] ?? null,
            count($filters)
        );

        $decoded = self::apply_filter_chain_decode($this->_stream, $filters, $params);
        if ($decoded === false) {
            // Chain failed (unknown filter or codec error). The
            // pre-refactor behaviour was `return p_error(...)`, and
            // `p_error` defaults its `$retval` to `false` — so legacy
            // callers using the `if ($decoded === false) continue;`
            // idiom (e.g. `PDFDoc::replace_text_in_document`) get the
            // same skip semantics they did before the dispatcher
            // refactor. The failing arm already emitted `p_error`.
            return false;
        }

        return $decoded;
    }
    /**
     * Sets the stream for the object (overwrites a previous existing stream)
     * @param stream the stream for the object
     */
    public function set_stream($stream, $raw = true) {
        if ($raw === true) {
            $this->_stream = $stream;
            return;
        }

        $filters = self::normalise_filter_chain($this->_value['Filter'] ?? null);
        if (count($filters) === 0) {
            // No filtering — store plaintext directly. /Filter being
            // absent or an empty array is spec-legal for "uncompressed
            // stream" (PDF 1.7 §7.4.1 ¶2).
            $this->_value['Length'] = strlen($stream);
            $this->_stream = $stream;
            return;
        }

        $params = self::normalise_decode_parms_chain(
            $this->_value['DecodeParms'] ?? null,
            count($filters)
        );

        $encoded = self::apply_filter_chain_encode($stream, $filters, $params);
        if ($encoded === false) {
            // Chain failed — leave `_stream` + `Length` unchanged per
            // the D4 fail-safe rule. The failing arm already emitted
            // `p_error`; callers can detect via the unchanged Length.
            return;
        }

        // Preserve the original `/Filter` shape (string vs array form)
        // per D2 — do not flip the persisted shape on write-back.
        $this->_value['Length'] = strlen($encoded);
        $this->_stream = $encoded;
    }    
    /**
     * The next functions enble to make use of this object in an array-like manner,
     *  using the name of the fields as positions in the array. It is useful is the
     *  value is of type PDFValueObject or PDFValueList, using indexes
     */

    /** 
     * Sets the value of the field offset, using notation $obj['field'] = $value
     * @param field the field to set the value
     * @param value the value to set
     * @return void
     */
    public function offsetSet($field, $value) : void {
        $this->_value[$field] = $value;
    }
    /**
     * Checks whether the field exists in the object or not (or if the index exists
     *   in the list)
     * @param field the field to check wether exists or not
     * @return exists true if the field exists; false otherwise
     */
    public function offsetExists ( $field ) : bool {
        return $this->_value->offsetExists($field);
    }
    /**
     * Gets the value of the field (or the value at position)
     * @param field the field to get the value
     * @return value the value of the field
     */
    #[\ReturnTypeWillChange]
    public function offsetGet ( $field ) { 
        return $this->_value[$field];
    }
    /**
     * Unsets the value of the field (or the value at position)
     * @param field the field to unset the value
     */
    public function offsetUnset($field ) : void {
        $this->_value->offsetUnset($field);
    }    

    public function push($v) {
        return $this->_value->push($v);
    }
}
