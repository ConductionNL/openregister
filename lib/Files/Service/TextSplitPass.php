<?php

/**
 * OpenRegister Text Split Pass
 *
 * One pass of recursive text splitting on a single separator.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 *
 * @author	Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git-id>
 * @link	  https://www.OpenRegister.app
 *
 * @spec openspec/specs/object-lifecycle/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Files\Service;

/**
 * Joins the pieces of a text split on one separator into chunks of at most chunkSize bytes,
 * carrying chunkOverlap bytes of the previous chunk into the next. Chunks shorter than
 * MIN_CHUNK_SIZE after trimming are dropped.
 *
 * @spec openspec/specs/object-lifecycle/spec.md
 */
final class TextSplitPass {

	/**
	 * Minimum trimmed length of a chunk worth keeping.
	 */
	public const MIN_CHUNK_SIZE = 100;

	/**
	 * Chunks emitted so far.
	 *
	 * @var list<array{text: string, start_offset: int, end_offset: int}>
	 */
	private array $chunks = [];

	/**
	 * The chunk being built.
	 *
	 * @var string
	 */
	private string $current = '';

	/**
	 * Offset of the chunk being built in the text.
	 *
	 * @var integer
	 */
	private int $offset = 0;

	/**
	 * Constructor.
	 *
	 * @param string $separator    The separator the text was split on.
	 * @param int    $chunkSize    Maximum chunk size.
	 * @param int    $chunkOverlap Bytes of a chunk repeated at the start of the next.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function __construct(
		private readonly string $separator,
		private readonly int $chunkSize,
		private readonly int $chunkOverlap,
	) {
	}//end __construct()

	/**
	 * Add a piece to the chunk being built when the result still fits.
	 *
	 * @param string $piece The piece.
	 *
	 * @return bool Whether the piece was added.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function append(string $piece): bool {
		$joined = $piece;
		if ($this->current !== '') {
			$joined = $this->current . $this->separator . $piece;
		}

		if (strlen($joined) > $this->chunkSize) {
			return false;
		}

		$this->current = $joined;
		return true;
	}//end append()

	/**
	 * Whether a chunk is being built.
	 *
	 * @return bool True when the current chunk is not empty.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function hasCurrent(): bool {
		return $this->current !== '';
	}//end hasCurrent()

	/**
	 * Emit the current chunk and start the next one with the piece, prefixed by the overlap.
	 *
	 * @param string $piece The piece that did not fit.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function closeWith(string $piece): void {
		$previous = $this->current;
		$length = strlen($previous);
		$this->emit(text: $previous);
		$this->offset += $length;

		$this->current = $piece;
		if ($this->chunkOverlap > 0 && $length > $this->chunkOverlap) {
			$this->current = substr($previous, -$this->chunkOverlap) . $this->separator . $piece;
			$this->offset -= $this->chunkOverlap;
		}
	}//end closeWith()

	/**
	 * Add the chunks of a piece that was split further, shifted to the piece's offset.
	 *
	 * @param string $piece     The piece that was split further.
	 * @param array  $subChunks Its chunks, with offsets relative to the piece.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function addSplitPiece(string $piece, array $subChunks): void {
		foreach ($subChunks as $subChunk) {
			$this->chunks[] = [
				'text' => $subChunk['text'],
				'start_offset' => $this->offset + $subChunk['start_offset'],
				'end_offset' => $this->offset + $subChunk['end_offset'],
			];
		}

		$this->offset += strlen($piece);
	}//end addSplitPiece()

	/**
	 * The chunks of the pass, including the last one, without chunks that are only whitespace.
	 *
	 * @return array<int, array{text: string, start_offset: int, end_offset: int}>
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function chunks(): array {
		if ($this->current !== '') {
			$this->emit(text: $this->current);
		}

		return array_filter(
			$this->chunks,
			function ($chunk) {
				return trim($chunk['text']) !== '';
			}
		);
	}//end chunks()

	/**
	 * Add a chunk at the current offset when its trimmed text is long enough.
	 *
	 * @param string $text The untrimmed chunk text.
	 *
	 * @return void
	 */
	private function emit(string $text): void {
		if (strlen(trim($text)) < self::MIN_CHUNK_SIZE) {
			return;
		}

		$this->chunks[] = [
			'text' => trim($text),
			'start_offset' => $this->offset,
			'end_offset' => $this->offset + strlen($text),
		];
	}//end emit()
}//end class
