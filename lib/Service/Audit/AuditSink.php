<?php

/**
 * Writes the audit trail to a file the organisation's own log platform takes.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Audit
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/audit-trail-shipped-and-purpose-bound/specs/enhanced-audit-trail/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Audit;

use OCA\OpenRegister\Db\AuditTrail;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The second sink, never the second truth.
 *
 * An audit log that only exists inside one application is one nobody is
 * watching. A gemeente's security operations centre watches one platform, and
 * it reads files. So every entry is written to a structured file as well as to
 * the database, in a documented format, for something else to read.
 *
 * The database stays the trail and the hash chain stays over it (D-1). The file
 * carries the row id, so a line can be pointed back at the row it came from and
 * the chain remains the thing that proves the row was not altered. Nothing
 * reads the file back into the application.
 *
 * ⚠️ WHAT THE LINE DOES NOT CARRY, and why. Sealing is deferred to
 * `AuditSealJob`, so at the moment a row is shipped its `hash` is still null.
 * Shipping AFTER sealing would mean either holding entries for up to five
 * minutes, or reading them back out of the database a second time — and a sink
 * that lags the trail by five minutes is a sink that loses the last five
 * minutes of an incident. The line is therefore the row as written, and the
 * `sealed` field says plainly that the hash was not yet assigned rather than
 * implying the row has none.
 *
 * @spec openspec/changes/audit-trail-shipped-and-purpose-bound/specs/enhanced-audit-trail/spec.md
 */
class AuditSink {
	/**
	 * The app the configuration belongs to.
	 *
	 * @var string
	 */
	private const APP = 'openregister';

	/**
	 * Where the file is written. Empty means no sink, which is the default and
	 * behaves exactly as the instance did before this change.
	 *
	 * @var string
	 */
	public const CONFIG_PATH = 'audit_sink_path';

	/**
	 * The format the file is written in.
	 *
	 * @var string
	 */
	public const CONFIG_FORMAT = 'audit_sink_format';

	/**
	 * How many times one line is attempted before the gap is recorded.
	 *
	 * @var string
	 */
	public const CONFIG_ATTEMPTS = 'audit_sink_attempts';

	/**
	 * Microseconds between attempts. 0 disables the pause.
	 *
	 * @var string
	 */
	public const CONFIG_RETRY_DELAY = 'audit_sink_retry_delay_usec';

	/**
	 * JSON Lines: one complete JSON object per line, newline terminated, no
	 * enclosing array. Every log shipper in use at a gemeente reads it, and it
	 * is appendable without rewriting what is already there, which a JSON
	 * document is not.
	 *
	 * @var string
	 */
	public const FORMAT_JSONL = 'jsonl';

	/**
	 * The formats this sink writes.
	 *
	 * @var string[]
	 */
	public const FORMATS = [self::FORMAT_JSONL];

	/**
	 * The version stamped on every line, so a reader can tell when the shape
	 * changed without guessing from the keys present.
	 *
	 * @var int
	 */
	public const LINE_VERSION = 1;

	/**
	 * The audit action a failure to ship is recorded under.
	 *
	 * @var string
	 */
	public const ACTION_SINK_FAILED = 'audit.sink-failed';

	/**
	 * Default attempts per line.
	 *
	 * @var int
	 */
	private const DEFAULT_ATTEMPTS = 3;

	/**
	 * Default pause between attempts, in microseconds.
	 *
	 * @var int
	 */
	private const DEFAULT_RETRY_DELAY = 5000;

	/**
	 * True while a failure is being recorded, so the entry that records a
	 * broken sink is not itself shipped into the same broken sink, forever.
	 *
	 * @var bool
	 */
	private bool $recordingFailure = false;

	/**
	 * How the sink records a gap on the trail itself.
	 *
	 * Injected as a callable rather than as the mapper, because the mapper is
	 * what CALLS this sink: taking it as a constructor dependency would be a
	 * cycle, and resolving it out of the container at call time hides the
	 * dependency from anybody reading the constructor.
	 *
	 * @var callable(AuditTrail, string): void|null
	 */
	private $failureRecorder = null;

	/**
	 * Constructor.
	 *
	 * @param IAppConfig       $appConfig Where the sink is configured.
	 * @param AuditSinkStatus  $status    The sink's health.
	 * @param LoggerInterface  $logger    PSR-3 logger.
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
		private readonly AuditSinkStatus $status,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Where the sink writes, or null when none is configured.
	 *
	 * @return string|null The configured path.
	 *
	 * @spec openspec/changes/audit-trail-shipped-and-purpose-bound/specs/enhanced-audit-trail/spec.md
	 */
	public function path(): ?string {
		try {
			$path = trim($this->appConfig->getValueString(self::APP, self::CONFIG_PATH, ''));
		} catch (Throwable $e) {
			return null;
		}

		if ($path === '') {
			return null;
		}

		return $path;
	}//end path()

	/**
	 * The format the sink writes in.
	 *
	 * An unrecognised format falls back to JSON Lines rather than refusing to
	 * ship. A typo in a format name must not be a reason the trail stops
	 * leaving the instance.
	 *
	 * @return string One of {@see AuditSink::FORMATS}.
	 *
	 * @spec openspec/changes/audit-trail-shipped-and-purpose-bound/specs/enhanced-audit-trail/spec.md
	 */
	public function format(): string {
		try {
			$format = strtolower(trim($this->appConfig->getValueString(self::APP, self::CONFIG_FORMAT, '')));
		} catch (Throwable $e) {
			return self::FORMAT_JSONL;
		}

		if (in_array($format, self::FORMATS, true) === true) {
			return $format;
		}

		return self::FORMAT_JSONL;
	}//end format()

	/**
	 * Whether a sink is configured at all.
	 *
	 * @return bool True when the instance ships its trail.
	 *
	 * @spec openspec/changes/audit-trail-shipped-and-purpose-bound/specs/enhanced-audit-trail/spec.md
	 */
	public function isConfigured(): bool {
		return $this->path() !== null;
	}//end isConfigured()

	/**
	 * The line one entry becomes.
	 *
	 * @param AuditTrail $entry The row to render.
	 *
	 * @return string The JSON Lines record, newline terminated.
	 *
	 * @spec openspec/changes/audit-trail-shipped-and-purpose-bound/specs/enhanced-audit-trail/spec.md
	 */
	public function renderLine(AuditTrail $entry): string {
		$line = $entry->jsonSerialize();
		$line['v'] = self::LINE_VERSION;
		// The purpose column is outside jsonSerialize() because the canonical
		// form is frozen; the sink is not bound by that, and a SIEM asking
		// "which grondslag" should not have to dig into resultSummary.
		$line['purpose'] = $entry->getPurpose();
		// Says what it is rather than what it looks like: a null hash here
		// means "not sealed yet", not "this row has no hash".
		$line['sealed'] = ($entry->getHash() !== null && $entry->getHash() !== '');

		return json_encode($line, (JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) . "\n";
	}//end renderLine()

	/**
	 * Ship one entry.
	 *
	 * @param AuditTrail $entry The row to ship.
	 *
	 * @return bool True when the line reached the file, false when a sink is
	 *              configured and it did not. True when no sink is configured,
	 *              because there was nothing to fail.
	 *
	 * @spec openspec/changes/audit-trail-shipped-and-purpose-bound/specs/enhanced-audit-trail/spec.md
	 */
	public function ship(AuditTrail $entry): bool {
		$path = $this->path();
		if ($path === null) {
			return true;
		}

		$error = null;
		if ($this->append(path: $path, line: $this->renderLine(entry: $entry), error: $error) === true) {
			$this->status->recordSuccess();

			return true;
		}

		$this->onFailure(entry: $entry, error: (string)$error);

		return false;
	}//end ship()

	/**
	 * Ship a batch of entries.
	 *
	 * @param AuditTrail[] $entries The rows to ship.
	 *
	 * @return int How many entries did not reach the file.
	 *
	 * @spec openspec/changes/audit-trail-shipped-and-purpose-bound/specs/enhanced-audit-trail/spec.md
	 */
	public function shipMany(array $entries): int {
		$failed = 0;
		foreach ($entries as $entry) {
			if ($entry instanceof AuditTrail === false) {
				continue;
			}

			if ($this->ship(entry: $entry) === false) {
				$failed++;
			}
		}

		return $failed;
	}//end shipMany()

	/**
	 * Append one line, retrying a transient failure.
	 *
	 * `LOCK_EX` is what makes the file append-only from this application's
	 * side under concurrency: two workers writing an audited act at the same
	 * instant interleave whole lines rather than halves of two.
	 *
	 * @param string      $path  The file to append to.
	 * @param string      $line  The line to write.
	 * @param string|null $error Set to the last failure when every attempt failed.
	 *
	 * @return bool True when the line was written.
	 *
	 * @spec openspec/changes/audit-trail-shipped-and-purpose-bound/specs/enhanced-audit-trail/spec.md
	 */
	private function append(string $path, string $line, ?string &$error): bool {
		$attempts = $this->attempts();
		$delay = $this->retryDelay();

		for ($attempt = 1; $attempt <= $attempts; $attempt++) {
			$written = @file_put_contents($path, $line, (FILE_APPEND | LOCK_EX));
			if ($written !== false && $written === strlen($line)) {
				return true;
			}

			// A SHORT write is worse than no write: the file now holds half a
			// record, and the next append makes it look like one malformed
			// line. Say so, because the operator has to truncate.
			if ($written !== false) {
				$error = sprintf('short write to %s: %d of %d bytes', $path, $written, strlen($line));
			} else {
				$last = error_get_last();
				$error = sprintf('write to %s failed: %s', $path, ($last['message'] ?? 'unknown error'));
			}

			if ($attempt < $attempts && $delay > 0) {
				usleep($delay);
			}
		}

		return false;
	}//end append()

	/**
	 * Record a gap: the entry that did not ship.
	 *
	 * Only the FIRST failure of a healthy sink gets its own entry. A broken
	 * sink fails on every audited act, and an entry per failure would double
	 * the size of the largest table this app has while the operator sleeps.
	 * The running count on the status carries the rest, so the gap's size is
	 * still knowable — and the entry is what makes it loud once.
	 *
	 * @param AuditTrail $entry The row that did not ship.
	 * @param string     $error What went wrong.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/audit-trail-shipped-and-purpose-bound/specs/enhanced-audit-trail/spec.md
	 */
	private function onFailure(AuditTrail $entry, string $error): void {
		$this->logger->error(
			message: '[AuditSink] An audit entry did not reach the configured sink.',
			context: [
				'uuid' => $entry->getUuid(),
				'error' => $error,
			]
		);

		if ($this->recordingFailure === true) {
			return;
		}

		$this->recordingFailure = true;

		try {
			$first = $this->status->recordFailure(error: $error);
			if ($first === true && $this->failureRecorder !== null) {
				($this->failureRecorder)($entry, $error);
			}
		} catch (Throwable $e) {
			$this->logger->error(
				message: '[AuditSink] The sink failure could not be recorded on the trail.',
				context: ['error' => $e->getMessage()]
			);
		} finally {
			$this->recordingFailure = false;
		}
	}//end onFailure()

	/**
	 * Tell the sink how to record a gap on the trail.
	 *
	 * @param callable $recorder Receives the unshipped entry and the error.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/audit-trail-shipped-and-purpose-bound/specs/enhanced-audit-trail/spec.md
	 */
	public function setFailureRecorder(callable $recorder): void {
		$this->failureRecorder = $recorder;
	}//end setFailureRecorder()

	/**
	 * How many attempts one line gets.
	 *
	 * @return int At least one.
	 *
	 * @spec openspec/changes/audit-trail-shipped-and-purpose-bound/specs/enhanced-audit-trail/spec.md
	 */
	private function attempts(): int {
		try {
			$attempts = $this->appConfig->getValueInt(self::APP, self::CONFIG_ATTEMPTS, self::DEFAULT_ATTEMPTS);
		} catch (Throwable $e) {
			return self::DEFAULT_ATTEMPTS;
		}

		return max(1, $attempts);
	}//end attempts()

	/**
	 * The pause between attempts.
	 *
	 * @return int Microseconds, never negative.
	 *
	 * @spec openspec/changes/audit-trail-shipped-and-purpose-bound/specs/enhanced-audit-trail/spec.md
	 */
	private function retryDelay(): int {
		try {
			$delay = $this->appConfig->getValueInt(self::APP, self::CONFIG_RETRY_DELAY, self::DEFAULT_RETRY_DELAY);
		} catch (Throwable $e) {
			return self::DEFAULT_RETRY_DELAY;
		}

		return max(0, $delay);
	}//end retryDelay()
}//end class
