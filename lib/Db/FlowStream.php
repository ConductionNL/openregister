<?php

/**
 * One STREAM of a run: a token's independent branch through the marking.
 *
 * A marking holding several tokens describes several things happening at
 * once; each token is a stream with its own id, ordinal, status and wake time,
 * so a branch waiting on a person never holds up a sibling. Stream status
 * reuses FlowRun's status vocabulary rather than a second one — a stream is a
 * run-shaped thing, and one set of strings means `FlowRun::TERMINAL` and
 * `FlowRun::ACTIVE` apply to both.
 *
 * `ordinal_path` is a zero-padded dotted path (`0001`, `0001.0002`) derived
 * from the AUTHOR's declaration order at each split, so the run log reads in
 * branch order whatever the timing.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Db
 * @package  OCA\OpenRegister\Db
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/flow-parallel-streams/specs/flow-parallel-streams/spec.md#requirement-independent-branches-of-one-run-must-advance-independently
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

use DateTime;
use JsonSerializable;
use LengthException;
use OCP\AppFramework\Db\Entity;

/**
 * One run stream.
 *
 * @method string|null getRunUuid()
 * @method void setRunUuid(?string $runUuid)
 * @method string|null getStreamId()
 * @method void setStreamId(?string $streamId)
 * @method string|null getOrdinalPath()
 * @method void setOrdinalPath(?string $ordinalPath)
 * @method string|null getParentStreamId()
 * @method void setParentStreamId(?string $parentStreamId)
 * @method string|null getPlace()
 * @method void setPlace(?string $place)
 * @method string|null getStatus()
 * @method void setStatus(?string $status)
 * @method DateTime|null getResumeAt()
 * @method void setResumeAt(?DateTime $resumeAt)
 * @method int|null getNextSequence()
 * @method void setNextSequence(?int $nextSequence)
 * @method string|null getError()
 * @method void setError(?string $error)
 * @method DateTime|null getCreated()
 * @method void setCreated(?DateTime $created)
 * @method DateTime|null getUpdated()
 * @method void setUpdated(?DateTime $updated)
 */
class FlowStream extends Entity implements JsonSerializable {

	/**
	 * The root stream's ordinal path.
	 */
	public const ROOT_PATH = '0001';

	/**
	 * Digits per ordinal segment: four keeps lexicographic order equal to tree
	 * order without parsing, and `'' < '.'` puts a parent before its children.
	 */
	public const SEGMENT_WIDTH = 4;

	/**
	 * The `ordinal_path` column width; a path that would exceed it fails the
	 * run rather than sorting wrongly.
	 */
	public const MAX_PATH_LENGTH = 255;

	/**
	 * The run this stream belongs to.
	 *
	 * @var string|null
	 */
	protected ?string $runUuid = null;

	/**
	 * The stream id, unique within the run.
	 *
	 * @var string|null
	 */
	protected ?string $streamId = null;

	/**
	 * The declaration-derived ordinal path.
	 *
	 * @var string|null
	 */
	protected ?string $ordinalPath = null;

	/**
	 * The stream this one was minted from, null for the root.
	 *
	 * @var string|null
	 */
	protected ?string $parentStreamId = null;

	/**
	 * The place currently holding this stream's token.
	 *
	 * @var string|null
	 */
	protected ?string $place = null;

	/**
	 * One of FlowRun's status constants.
	 *
	 * @var string|null
	 */
	protected ?string $status = null;

	/**
	 * When a suspended stream wants waking; null while waiting on a signal.
	 *
	 * @var DateTime|null
	 */
	protected ?DateTime $resumeAt = null;

	/**
	 * The next step sequence WITHIN this stream.
	 *
	 * @var int|null
	 */
	protected ?int $nextSequence = 1;

	/**
	 * Why the stream failed, when it did.
	 *
	 * @var string|null
	 */
	protected ?string $error = null;

	/**
	 * Creation time.
	 *
	 * @var DateTime|null
	 */
	protected ?DateTime $created = null;

	/**
	 * Last update time.
	 *
	 * @var DateTime|null
	 */
	protected ?DateTime $updated = null;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->addType(fieldName: 'runUuid', type: 'string');
		$this->addType(fieldName: 'streamId', type: 'string');
		$this->addType(fieldName: 'ordinalPath', type: 'string');
		$this->addType(fieldName: 'parentStreamId', type: 'string');
		$this->addType(fieldName: 'place', type: 'string');
		$this->addType(fieldName: 'status', type: 'string');
		$this->addType(fieldName: 'resumeAt', type: 'datetime');
		$this->addType(fieldName: 'nextSequence', type: 'integer');
		$this->addType(fieldName: 'error', type: 'string');
		$this->addType(fieldName: 'created', type: 'datetime');
		$this->addType(fieldName: 'updated', type: 'datetime');
	}//end __construct()

	/**
	 * Whether this stream has reached a terminal status.
	 *
	 * @return bool True when terminal.
	 */
	public function isTerminal(): bool {
		return in_array($this->status, FlowRun::TERMINAL, true);
	}//end isTerminal()

	/**
	 * The ordinal path of the K-th child of a parent path.
	 *
	 * @param string $parentPath The parent's path.
	 * @param int $index The 1-based position in the taken outputs' declaration order.
	 *
	 * @return string The child path.
	 *
	 * @throws LengthException When the path would exceed the column.
	 *
	 * @spec openspec/changes/flow-parallel-streams/specs/flow-parallel-streams/spec.md#requirement-the-run-log-must-be-ordered-by-branch-never-by-completion
	 */
	public static function childPath(string $parentPath, int $index): string {
		$path = $parentPath . '.' . str_pad((string)$index, self::SEGMENT_WIDTH, '0', STR_PAD_LEFT);
		if (strlen($path) > self::MAX_PATH_LENGTH) {
			throw new LengthException(
				sprintf(
					'Stream ordinal path would exceed %d characters (nesting too deep to order the run log); the run cannot continue.',
					self::MAX_PATH_LENGTH
				)
			);
		}

		return $path;
	}//end childPath()

	/**
	 * The longest common prefix of several ordinal paths, at segment
	 * boundaries — the stream a join folds its inputs back onto.
	 *
	 * Total: branches from different splits share a shorter prefix, possibly
	 * the root, and the answer is still deterministic.
	 *
	 * @param array<int, string> $paths The input paths.
	 *
	 * @return string The common prefix; the root path when nothing is shared.
	 *
	 * @spec openspec/changes/flow-parallel-streams/specs/flow-parallel-streams/spec.md#requirement-the-run-log-must-be-ordered-by-branch-never-by-completion
	 */
	public static function commonPrefix(array $paths): string {
		$paths = array_values(array_filter($paths, static fn (mixed $path): bool => is_string($path) === true && $path !== ''));
		if ($paths === []) {
			return self::ROOT_PATH;
		}

		$segments = array_map(static fn (string $path): array => explode('.', $path), $paths);
		$common = [];
		$depth = min(array_map('count', $segments));
		for ($i = 0; $i < $depth; $i++) {
			$segment = $segments[0][$i];
			foreach ($segments as $candidate) {
				if ($candidate[$i] !== $segment) {
					return self::pathOrRoot(segments: $common);
				}
			}

			$common[] = $segment;
		}

		return self::pathOrRoot(segments: $common);
	}//end commonPrefix()

	/**
	 * Join path segments, or the root path when there are none.
	 *
	 * @param array<int, string> $segments The segments.
	 *
	 * @return string The path.
	 */
	private static function pathOrRoot(array $segments): string {
		if ($segments === []) {
			return self::ROOT_PATH;
		}

		return implode('.', $segments);
	}//end pathOrRoot()

	/**
	 * JSON shape.
	 *
	 * @return array<string, mixed> The stream as an array.
	 */
	public function jsonSerialize(): array {
		return [
			'id' => $this->id,
			'runUuid' => $this->runUuid,
			'streamId' => $this->streamId,
			'ordinalPath' => $this->ordinalPath,
			'parentStreamId' => $this->parentStreamId,
			'place' => $this->place,
			'status' => $this->status,
			'resumeAt' => $this->resumeAt?->format('c'),
			'nextSequence' => $this->nextSequence,
			'error' => $this->error,
			'created' => $this->created?->format('c'),
			'updated' => $this->updated?->format('c'),
		];
	}//end jsonSerialize()
}//end class
