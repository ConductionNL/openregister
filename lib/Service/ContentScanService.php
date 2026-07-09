<?php

/**
 * ContentScanService — heuristic static scan of untrusted text content.
 *
 * OpenRegister's SecurityService is auth rate-limiting; it does not inspect content. Apps
 * that ingest externally-authored text (Hermiq agent skills from a hub/org, imported
 * prompts, uploaded instructions) need a shared, dependency-free way to flag the classic
 * dangerous patterns BEFORE that content is trusted — download-and-execute one-liners,
 * destructive shell, credential exfiltration, embedded secrets, and prompt-injection.
 *
 * This is a heuristic gate, not a sandbox: it raises signal for a human/review gate, it does
 * not execute or prove intent. A `dangerous` verdict means "do not auto-trust"; `suspicious`
 * means "needs a human look"; `clean` means "no known-bad pattern matched" (never "proven
 * safe"). Callers own the policy decision.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

use Psr\Log\LoggerInterface;

/**
 * Dependency-free heuristic content scanner for untrusted text.
 */
class ContentScanService
{

    /**
     * Verdict: no known-bad pattern matched (NOT a proof of safety).
     *
     * @var string
     */
    public const SEVERITY_CLEAN = 'clean';

    /**
     * Verdict: matched a pattern that warrants a human review before trust.
     *
     * @var string
     */
    public const SEVERITY_SUSPICIOUS = 'suspicious';

    /**
     * Verdict: matched a pattern that must not be auto-trusted.
     *
     * @var string
     */
    public const SEVERITY_DANGEROUS = 'dangerous';

    /**
     * Cap on how much content is scanned, to bound worst-case regex cost on a hostile
     * megabyte-sized payload. Content beyond this is ignored (and flagged as truncated).
     *
     * @var int
     */
    private const MAX_SCAN_BYTES = 262144;

    /**
     * Constructor.
     *
     * @param LoggerInterface $logger PSR-3 logger.
     */
    public function __construct(
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Scan a body of text (optionally with structured metadata) for dangerous patterns.
     *
     * @param string               $content  The primary text to scan (e.g. a skill body).
     * @param array<string, mixed> $metadata Optional structured metadata to fold in (e.g.
     *                                       a skill's frontmatter); scalar leaves are scanned.
     *
     * @return array{safe: bool, severity: string, findings: array<int, array<string, string>>, scannedBytes: int, truncated: bool}
     *         The scan report. `safe` is true only when severity is `clean`.
     */
    public function scan(string $content, array $metadata=[]): array
    {
        $haystack  = $content;
        $flattened = $this->flattenMetadata(metadata: $metadata);
        if ($flattened !== '') {
            $haystack .= "\n".$flattened;
        }

        $truncated = false;
        if (strlen($haystack) > self::MAX_SCAN_BYTES) {
            $haystack  = substr($haystack, 0, self::MAX_SCAN_BYTES);
            $truncated = true;
        }

        $findings   = [];
        $normalised = $this->normalise(text: $haystack);
        foreach ($this->rules() as $rule) {
            if (preg_match($rule['pattern'], $normalised, $matches) !== 1) {
                continue;
            }

            $findings[] = [
                'category' => $rule['category'],
                'severity' => $rule['severity'],
                'reason'   => $rule['reason'],
                'excerpt'  => $this->excerpt(match: (string) ($matches[0] ?? '')),
            ];
        }//end foreach

        $severity = $this->worstSeverity(findings: $findings);

        if ($severity !== self::SEVERITY_CLEAN) {
            $this->logger->info(
                '[ContentScanService] content scan flagged patterns',
                ['severity' => $severity, 'findingCount' => count($findings)]
            );
        }

        return [
            'safe'         => ($severity === self::SEVERITY_CLEAN),
            'severity'     => $severity,
            'findings'     => $findings,
            'scannedBytes' => strlen($haystack),
            'truncated'    => $truncated,
        ];

    }//end scan()

    /**
     * The heuristic rule table. Each rule is a case-insensitive regex plus a category,
     * a severity, and a human-readable reason. Ordered dangerous-first for readability;
     * the final verdict is the worst matched severity regardless of order.
     *
     * @return array<int, array{pattern: string, category: string, severity: string, reason: string}>
     */
    private function rules(): array
    {
        $dangerous  = self::SEVERITY_DANGEROUS;
        $suspicious = self::SEVERITY_SUSPICIOUS;

        return [
            // Download-and-execute: the single most common skill/prompt supply-chain attack.
            [
                'pattern'  => '/\b(?:curl|wget|fetch)\b[^\n|]*\|\s*(?:sudo\s+)?(?:ba)?sh\b/i',
                'category' => 'remote-code',
                'severity' => $dangerous,
                'reason'   => 'Pipes a remote download straight into a shell (curl|bash).',
            ],
            [
                'pattern'  => '/\b(?:ba)?sh\b\s*<\s*\(\s*(?:curl|wget)\b/i',
                'category' => 'remote-code',
                'severity' => $dangerous,
                'reason'   => 'Executes a remotely fetched script via process substitution.',
            ],
            [
                'pattern'  => '/\b(?:eval|exec|system|passthru|popen|proc_open)\s*\(\s*(?:\$?_?(?:GET|POST|REQUEST)|base64_decode|curl)/i',
                'category' => 'remote-code',
                'severity' => $dangerous,
                'reason'   => 'Evaluates dynamically-fetched or request-supplied code.',
            ],
            [
                'pattern'  => '/(?:iex|invoke-expression)\s*\(\s*(?:new-object\s+net\.webclient|iwr|invoke-webrequest)/i',
                'category' => 'remote-code',
                'severity' => $dangerous,
                'reason'   => 'PowerShell download-and-execute (IEX + WebClient).',
            ],
            // Destructive shell.
            [
                'pattern'  => '/\brm\s+-[a-z]*r[a-z]*f[a-z]*\s+(?:\/|~|\$HOME|\*)/i',
                'category' => 'destructive',
                'severity' => $dangerous,
                'reason'   => 'Recursive force-delete of a root/home/glob path (rm -rf).',
            ],
            [
                'pattern'  => '/\b(?:mkfs|dd\s+if=\S+\s+of=\/dev\/|>\s*\/dev\/sd[a-z])\b/i',
                'category' => 'destructive',
                'severity' => $dangerous,
                'reason'   => 'Overwrites a raw block device (mkfs/dd/redirect to /dev/sdX).',
            ],
            [
                'pattern'  => '/:\s*\(\s*\)\s*\{\s*:\s*\|\s*:\s*&\s*\}\s*;\s*:/',
                'category' => 'destructive',
                'severity' => $dangerous,
                'reason'   => 'Fork-bomb definition.',
            ],
            // Credential / data exfiltration.
            [
                'pattern'  => '/\b(?:nc|ncat|netcat)\b[^\n]*\s-\w*e\w*\s/i',
                'category' => 'exfiltration',
                'severity' => $dangerous,
                'reason'   => 'Netcat with -e (reverse/bind shell).',
            ],
            [
                'pattern'  => '#(?:/dev/tcp/|/dev/udp/)\d#i',
                'category' => 'exfiltration',
                'severity' => $dangerous,
                'reason'   => 'Bash /dev/tcp network socket (reverse shell / exfil).',
            ],
            [
                'pattern'  => '#(?:cat|cp|curl|scp|tar|base64)\b[^\n]*(?:~|/root|/home/[^\s/]+)?'
                    .'/\.(?:ssh/id_|aws/credentials|kube/config|netrc|npmrc|docker/config)#i',
                'category' => 'exfiltration',
                'severity' => $dangerous,
                'reason'   => 'Reads/copies known credential files (~/.ssh, ~/.aws, .netrc …).',
            ],
            // Embedded secrets (someone shipping live keys inside skill content).
            [
                'pattern'  => '/-----BEGIN\s+(?:RSA|OPENSSH|EC|DSA|PGP)?\s*PRIVATE KEY-----/i',
                'category' => 'embedded-secret',
                'severity' => $dangerous,
                'reason'   => 'Embeds a private key block.',
            ],
            [
                'pattern'  => '/\b(?:AKIA|ASIA)[0-9A-Z]{16}\b/',
                'category' => 'embedded-secret',
                'severity' => $dangerous,
                'reason'   => 'Embeds an AWS access-key id.',
            ],
            [
                'pattern'  => '/\b(?:ghp|gho|ghs|ghr)_[A-Za-z0-9]{30,}\b/',
                'category' => 'embedded-secret',
                'severity' => $dangerous,
                'reason'   => 'Embeds a GitHub access token.',
            ],
            // Prompt-injection — the LLM-specific manipulation surface.
            [
                'pattern'  => '/\b(?:ignore|disregard|forget)\b[^\n]{0,40}\b(?:previous|prior|above|earlier|all)\b'
                    .'[^\n]{0,20}\b(?:instruction|prompt|rule|direction)/i',
                'category' => 'prompt-injection',
                'severity' => $suspicious,
                'reason'   => 'Instructs the model to ignore prior instructions.',
            ],
            [
                'pattern'  => '/\b(?:reveal|print|repeat|show|leak|exfiltrate)\b[^\n]{0,30}'
                    .'\b(?:system prompt|your instructions|initial prompt|api[\s_-]?key|secret)/i',
                'category' => 'prompt-injection',
                'severity' => $suspicious,
                'reason'   => 'Attempts to extract the system prompt or secrets.',
            ],
            [
                'pattern'  => '/<\|im_(?:start|end)\|>\s*system|<\/?(?:system|assistant)>\s*(?:you are|ignore)/i',
                'category' => 'prompt-injection',
                'severity' => $suspicious,
                'reason'   => 'Injects a forged system/role turn into the conversation.',
            ],
        ];

    }//end rules()

    /**
     * Flatten scalar leaves of a metadata array into newline-joined text for scanning.
     *
     * @param array<string, mixed> $metadata The structured metadata.
     *
     * @return string The concatenated scalar values.
     */
    private function flattenMetadata(array $metadata): string
    {
        $parts = [];
        array_walk_recursive(
            $metadata,
            static function ($leaf) use (&$parts) {
                if (is_scalar($leaf) === true) {
                    $parts[] = (string) $leaf;
                }
            }
        );

        return implode("\n", $parts);

    }//end flattenMetadata()

    /**
     * Normalise text to defeat trivial obfuscation before matching: collapse runs of
     * whitespace (so `curl   |   bash` matches) while keeping newlines as boundaries.
     *
     * @param string $text The raw text.
     *
     * @return string The normalised text.
     */
    private function normalise(string $text): string
    {
        // Collapse horizontal whitespace runs to a single space; keep newlines.
        $collapsed = preg_replace('/[ \t\x0B\f]+/', ' ', $text);
        if (is_string($collapsed) === false) {
            return $text;
        }

        return $collapsed;

    }//end normalise()

    /**
     * The worst severity across all findings (clean when there are none).
     *
     * @param array<int, array<string, string>> $findings The findings.
     *
     * @return string The worst severity.
     */
    private function worstSeverity(array $findings): string
    {
        $worst = self::SEVERITY_CLEAN;
        foreach ($findings as $finding) {
            if (($finding['severity'] ?? '') === self::SEVERITY_DANGEROUS) {
                return self::SEVERITY_DANGEROUS;
            }

            if (($finding['severity'] ?? '') === self::SEVERITY_SUSPICIOUS) {
                $worst = self::SEVERITY_SUSPICIOUS;
            }
        }

        return $worst;

    }//end worstSeverity()

    /**
     * A short, single-line, length-bounded excerpt of a matched fragment for the report.
     *
     * @param string $match The matched substring.
     *
     * @return string The sanitised excerpt.
     */
    private function excerpt(string $match): string
    {
        $oneLine = preg_replace('/\s+/', ' ', trim($match));
        if (is_string($oneLine) === false) {
            $oneLine = '';
        }

        if (strlen($oneLine) > 120) {
            $oneLine = substr($oneLine, 0, 117).'…';
        }

        return $oneLine;

    }//end excerpt()
}//end class
