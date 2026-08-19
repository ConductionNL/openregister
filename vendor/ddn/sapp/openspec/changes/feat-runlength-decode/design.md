## Context

RunLengthDecode operates on bytes with a simple length-prefix scheme:

| Length byte `L` | Interpretation |
|---|---|
| `0 ≤ L ≤ 127` | Copy the next `L + 1` literal bytes |
| `L == 128` | EOD — stop decoding |
| `129 ≤ L ≤ 255` | Copy the next byte `257 - L` times |

Encode is greedy: scan the input for runs of 2+ identical bytes (max 128 per repeat) and emit the repeat form; otherwise accumulate literals (max 128 per literal block).

## Goals / Non-Goals

**Goals:**

- Lossless round-trip on arbitrary binary input.
- Recognise EOD at any position; bytes after EOD are ignored.
- Encode produces compact output for runs (≥3 bytes per emitted repeat group).

**Non-Goals:**

- Optimal encoding (we use greedy; some pathological inputs may compress slightly larger than an optimal RLE).
- Streaming / incremental decode (operates on full buffers, matching upstream's filter convention).

## Decisions

### D1 — Mirror `FlateDecode` shape

`protected static function RunLengthDecode($_stream, $params)` and `protected static function RunLengthEncode($_stream, $params)` as static helpers on `PDFObject`. Same calling convention as the existing FlateDecode helper.

### D2 — Encode literal-then-runs greedy heuristic

Walk the input; when we see ≥ 2 identical adjacent bytes, flush any pending literal run and emit a repeat block (capped at 128 bytes per block). Otherwise accumulate literals (capped at 128 per block). Greedy — not optimal but ~5 LOC vs. dozens for an optimal encoder, and the difference is irrelevant for the small content streams we deal with.

### D3 — Decode rejects truncated input via `p_error` + return `false`

If the decoder reads a length byte `L ∈ [0, 127]` but the stream runs out before delivering `L + 1` literal bytes, OR reads `L ∈ [129, 255]` but the stream runs out before delivering the repeat byte, OR reaches end-of-stream before encountering EOD (`L == 128`) — `p_error()` is called and the function returns `false`. This matches the chain dispatcher's `=== false` short-circuit contract introduced in `feat-filter-chain-dispatch` (so downstream filters in a chain MUST NOT observe a partially-decoded buffer), and aligns with `p_error()`'s default return value.

### D4 — EOD is mandatory on encode, tolerated on decode

The encoder MUST emit the EOD marker (`\x80`) at the end of every output stream. The decoder treats end-of-input without EOD as truncation (D3), but `\x80` followed by trailing garbage is fine — garbage is ignored.

### D5 — Chain-failure-propagation contract

When `RunLengthDecode` is used as one arm of an array-form `/Filter` chain (e.g. `[/RunLengthDecode /FlateDecode]`), a truncation failure in the outer RunLength arm MUST short-circuit the dispatcher: the inner arm (`FlateDecode` in the example) MUST NOT be invoked on the partial buffer. The `return false` semantics from D3 are what makes this contract enforceable — the dispatcher's `if ($decoded === false) return false;` arm fires before the next filter sees any bytes. Exercised by `examples/poc-filter-roundtrip-runlength.php` "REQ-4 chain-failure-propagation" scenario.

### D6 — Decode amplification: caller-trust contract

Worst-case decode amplification is 64× (a 2-byte input — one length byte + one repeat byte — expands to 128 output bytes via the repeat block). A 16 MB malicious stream of repeat blocks decompresses to ~1 GB. For trusted PDF input this is acceptable; for untrusted/attacker-controlled input the caller MUST validate the input source before passing it to `RunLengthDecode`. An explicit output-size cap (e.g. max 64× input length, `p_error()` + `return false` on overrun) is a follow-up change; documented here as a known trade-off so reviewers and consumers don't accidentally route untrusted data through this filter without validation.

## Risks / Trade-offs

- **Trade-off**: Greedy encoding leaves a few bytes on the floor vs. optimal RLE. → **Mitigation**: documented; in-the-wild RLE streams are typically short and the size difference is negligible. Future optimisation is a one-method swap. Pathological case worth surfacing in fixtures: input `"ABBC"` emits `\x00A\xFFB\x00C\x80` (7 bytes) where an optimal split would produce `\x03ABBC\x80` (6 bytes) — the encoder aborts the literal block on the first 2-byte run. Raising the run threshold from 2 to 3 is a single-line change deferred to a follow-up.

- **Risk**: An attacker-crafted input could request a 128-byte literal run with only 1 byte present, causing a buffer over-read in the decoder. → **Mitigation**: defensive bounds check before each `substr` call; `p_error()` + `return false` on underflow (D3).

## Open Questions

- **OQ1**: Should the encoder emit an empty stream for empty input, or a single EOD byte `\x80`? **Provisional**: single EOD byte. Matches the convention used by other RLE codecs and makes the encoder's output non-empty even for trivial input (easier round-trip testing).

## Migration Plan

Strictly additive change. No migration required for consumers — string-form callers (and unaware-of-this-change callers) observe byte-for-byte identical behaviour after merge. Rollback path is a clean revert of the commit.

