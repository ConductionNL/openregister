<?php

/**
 * ReferenceService: a short code typed in a sentence becomes a link, and the
 * link is recorded on both sides.
 *
 * ZAAK-2026-0412 written in a note should reach the zaak. The pattern that
 * recognises it is administered, not coded, so an instance that declares no
 * pattern renders code-shaped text unchanged and no leaf app ships a regex.
 *
 * Rendering the link is presentation. RECORDING the reference is what makes
 * "which zaken mention this besluit" answerable from the other end, and what
 * survives somebody rewriting the sentence around the code (D-6). The two are
 * separate methods here for exactly that reason.
 *
 * ADMINISTERED REGEXES ARE INPUT. The stored pattern is a BODY, without
 * delimiters and without flags; this service supplies both. An administrator
 * therefore cannot change what the expression means by writing its delimiters
 * themselves, and a pattern that does not compile is refused at declaration
 * time rather than throwing on the next note somebody writes.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Service
 * @package   OCA\OpenRegister\Service\Timeline
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git-id>
 * @link      https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Timeline;

use DateTime;
use OCA\OpenRegister\Db\EntryReference;
use OCA\OpenRegister\Db\EntryReferenceMapper;
use OCA\OpenRegister\Db\ReferencePattern;
use OCA\OpenRegister\Db\ReferencePatternMapper;
use OCA\OpenRegister\Service\ObjectService;
use Psr\Log\LoggerInterface;
use Symfony\Component\Uid\Uuid;
use Throwable;

/**
 * Administered reference patterns and the references they record.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Timeline
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 *
 * @spec openspec/changes/timeline-entries-are-records/specs/object-interactions/spec.md
 */
class ReferenceService {

	/**
	 * How many codes one entry's text may resolve.
	 *
	 * Each match costs one object lookup, so a note pasted full of codes would
	 * otherwise turn one write into hundreds of reads. Past the cap the rest
	 * render as plain text, which is what they did before this change.
	 *
	 * @var integer
	 */
	public const MAX_MATCHES = 25;

	/**
	 * Constructor.
	 *
	 * @param ReferencePatternMapper $patterns   The administered patterns.
	 * @param EntryReferenceMapper   $references The recorded references.
	 * @param ObjectService          $objects    Resolves a code to an object the caller may read.
	 * @param LoggerInterface        $logger     Logger for the paths that decline rather than throw.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ReferencePatternMapper $patterns,
		private readonly EntryReferenceMapper $references,
		private readonly ObjectService $objects,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Every administered pattern.
	 *
	 * @param boolean $enabledOnly Whether to leave out the disabled ones.
	 *
	 * @return array<int, ReferencePattern> The declarations.
	 *
	 * @spec openspec/changes/timeline-entries-are-records/specs/object-interactions/spec.md
	 */
	public function listPatterns(bool $enabledOnly = false): array {
		return $this->patterns->findAll(enabledOnly: $enabledOnly);
	}//end listPatterns()

	/**
	 * Declare a pattern, or rewrite the declaration that carries the name.
	 *
	 * @param array<string,mixed> $data slug, title, pattern, register, schema, targetProperty, urlTemplate, enabled.
	 *
	 * @return ReferencePattern The stored declaration.
	 *
	 * @throws TimelineValidationException When the slug is missing or the expression does not compile.
	 *
	 * @spec openspec/changes/timeline-entries-are-records/specs/object-interactions/spec.md
	 */
	public function declarePattern(array $data): ReferencePattern {
		$slug = strtolower(trim((string)($data['slug'] ?? '')));
		if ($slug === '') {
			throw new TimelineValidationException(['slug' => 'A reference pattern needs a slug']);
		}

		$body = (string)($data['pattern'] ?? '');
		if (trim($body) === '') {
			throw new TimelineValidationException(['pattern' => 'A reference pattern needs an expression']);
		}

		if ($this->compiles(body: $body) === false) {
			throw new TimelineValidationException(['pattern' => 'That expression is not a usable pattern']);
		}

		$pattern = $this->patterns->findBySlug(slug: $slug);
		$isNew = ($pattern === null);
		if ($isNew === true) {
			$pattern = new ReferencePattern();
			$pattern->setUuid((string)Uuid::v4());
			$pattern->setSlug($slug);
			$pattern->setCreated(new DateTime());
		}

		$pattern->setTitle($this->stringOrNull(data: $data, key: 'title'));
		$pattern->setPattern($body);
		$pattern->setRegister($this->stringOrNull(data: $data, key: 'register'));
		$pattern->setSchema($this->stringOrNull(data: $data, key: 'schema'));
		$pattern->setTargetProperty($this->stringOrNull(data: $data, key: 'targetProperty'));
		$pattern->setUrlTemplate($this->stringOrNull(data: $data, key: 'urlTemplate'));
		$pattern->setEnabled(($data['enabled'] ?? true) !== false);
		$pattern->setUpdated(new DateTime());

		if ($isNew === true) {
			return $this->patterns->insert($pattern);
		}

		return $this->patterns->update($pattern);
	}//end declarePattern()

	/**
	 * Withdraw a pattern.
	 *
	 * References already recorded stay: they are what was written, and the
	 * link they carry still points where it pointed.
	 *
	 * @param string $slug The pattern name.
	 *
	 * @return boolean True when a declaration was removed.
	 *
	 * @spec openspec/changes/timeline-entries-are-records/specs/object-interactions/spec.md
	 */
	public function withdrawPattern(string $slug): bool {
		$pattern = $this->patterns->findBySlug(slug: $slug);
		if ($pattern === null) {
			return false;
		}

		$this->patterns->delete($pattern);

		return true;
	}//end withdrawPattern()

	/**
	 * The codes a text carries, with what each resolves to.
	 *
	 * An instance that declares no pattern returns nothing, and its text is
	 * therefore rendered exactly as it was written.
	 *
	 * @param string|null $text The text to read.
	 *
	 * @return array<int, array{code: string, patternSlug: string, targetUuid: string|null, url: string|null}> The matches.
	 *
	 * @spec openspec/changes/timeline-entries-are-records/specs/object-interactions/spec.md
	 */
	public function scan(?string $text): array {
		if (is_string($text) === false || trim($text) === '') {
			return [];
		}

		$found = [];
		$seen = [];

		foreach ($this->patterns->findAll(enabledOnly: true) as $pattern) {
			$matches = [];
			$compiled = $this->delimit(body: (string)$pattern->getPattern());
			if (@preg_match_all($compiled, $text, $matches) === false) {
				$this->logger->warning(
					'[ReferenceService] Pattern '.(string)$pattern->getSlug().' did not run; it is declared but unusable'
				);
				continue;
			}

			foreach (($matches[0] ?? []) as $code) {
				$code = (string)$code;
				if ($code === '' || isset($seen[$code]) === true) {
					continue;
				}

				$seen[$code] = true;
				$found[] = $this->resolve(pattern: $pattern, code: $code);

				if (count($found) >= self::MAX_MATCHES) {
					return $found;
				}
			}
		}//end foreach

		return $found;
	}//end scan()

	/**
	 * Record what an entry's text points at, replacing what it pointed at before.
	 *
	 * Rewriting is a delete and a write rather than a merge, which is what
	 * makes REMOVING the code remove the reference: a merge would keep the
	 * reference to a code the sentence no longer carries.
	 *
	 * Only codes that RESOLVED are recorded. A code shaped like a case number
	 * that names no case is a link to nowhere, and recording it would put a
	 * dangling row on the other end of a graph people query.
	 *
	 * @param string      $entryUuid  The entry.
	 * @param string      $sourceUuid The object the entry hangs on.
	 * @param string|null $text       The entry text.
	 *
	 * @return array<int, EntryReference> The references now recorded for this entry.
	 *
	 * @spec openspec/changes/timeline-entries-are-records/specs/object-interactions/spec.md
	 */
	public function record(string $entryUuid, string $sourceUuid, ?string $text): array {
		$this->references->deleteForEntry(entryUuid: $entryUuid);

		$written = [];
		foreach ($this->scan(text: $text) as $match) {
			if ($match['targetUuid'] === null) {
				continue;
			}

			$reference = new EntryReference();
			$reference->setEntryUuid($entryUuid);
			$reference->setSourceUuid($sourceUuid);
			$reference->setTargetUuid($match['targetUuid']);
			$reference->setCode($match['code']);
			$reference->setPatternSlug($match['patternSlug']);
			$reference->setUrl($match['url']);
			$reference->setCreated(new DateTime());

			$written[] = $this->references->insert($reference);
		}

		return $written;
	}//end record()

	/**
	 * Everything one entry points at.
	 *
	 * @param string $entryUuid The entry.
	 *
	 * @return array<int, EntryReference> The rows.
	 *
	 * @spec openspec/changes/timeline-entries-are-records/specs/object-interactions/spec.md
	 */
	public function forEntry(string $entryUuid): array {
		return $this->references->findForEntry(entryUuid: $entryUuid);
	}//end forEntry()

	/**
	 * Everything that points at one object: the other end of the link.
	 *
	 * @param string $targetUuid The referenced object.
	 *
	 * @return array<int, EntryReference> The rows.
	 *
	 * @spec openspec/changes/timeline-entries-are-records/specs/object-interactions/spec.md
	 */
	public function forTarget(string $targetUuid): array {
		return $this->references->findForTarget(targetUuid: $targetUuid);
	}//end forTarget()

	/**
	 * Forget what an entry pointed at.
	 *
	 * @param string $entryUuid The entry.
	 *
	 * @return integer The number of references removed.
	 *
	 * @spec openspec/changes/timeline-entries-are-records/specs/object-interactions/spec.md
	 */
	public function forget(string $entryUuid): int {
		return $this->references->deleteForEntry(entryUuid: $entryUuid);
	}//end forget()

	/**
	 * Resolve one matched code to an object and a url.
	 *
	 * The lookup goes through ObjectService with RBAC ON, so a code naming an
	 * object the writer may not read resolves to nothing: a reference is not a
	 * way to confirm that a case exists.
	 *
	 * @param ReferencePattern $pattern The pattern that matched.
	 * @param string           $code    The matched code.
	 *
	 * @return array{code: string, patternSlug: string, targetUuid: string|null, url: string|null} The match.
	 */
	private function resolve(ReferencePattern $pattern, string $code): array {
		$url = null;
		$template = $pattern->getUrlTemplate();
		if ($template !== null) {
			$url = str_replace('{code}', rawurlencode($code), $template);
		}

		$targetUuid = null;
		try {
			$object = $this->objects->find(
				id: $code,
				register: $pattern->getRegister(),
				schema: $pattern->getSchema(),
				_render: false,
				_audit: false
			);
			if ($object !== null) {
				$targetUuid = (string)$object->getUuid();
			}
		} catch (Throwable $e) {
			// A code that names nothing is the ordinary case, not a failure:
			// somebody typed a number that is not a case number. It renders as
			// plain text and records nothing.
			$targetUuid = null;
		}

		return [
			'code' => $code,
			'patternSlug' => (string)$pattern->getSlug(),
			'targetUuid' => $targetUuid,
			'url' => $url,
		];
	}//end resolve()

	/**
	 * Wrap an administered expression body in delimiters this service chooses.
	 *
	 * @param string $body The stored expression body.
	 *
	 * @return string The compilable expression.
	 */
	private function delimit(string $body): string {
		return '/'.str_replace('/', '\/', $body).'/u';
	}//end delimit()

	/**
	 * Whether an administered expression body compiles at all.
	 *
	 * @param string $body The expression body.
	 *
	 * @return boolean True when it can be used.
	 */
	private function compiles(string $body): bool {
		return (@preg_match($this->delimit(body: $body), '') !== false);
	}//end compiles()

	/**
	 * Read one optional string off a payload.
	 *
	 * @param array<string,mixed> $data The payload.
	 * @param string              $key  The key.
	 *
	 * @return string|null The value, or null when absent, empty or not a string.
	 */
	private function stringOrNull(array $data, string $key): ?string {
		if (isset($data[$key]) === false || is_string($data[$key]) === false) {
			return null;
		}

		$value = trim($data[$key]);
		if ($value === '') {
			return null;
		}

		return $value;
	}//end stringOrNull()
}//end class
