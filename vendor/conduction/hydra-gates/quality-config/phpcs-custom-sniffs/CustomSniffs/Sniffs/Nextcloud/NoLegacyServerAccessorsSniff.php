<?php
/**
 * Forbid the legacy named accessors on \OC::$server that were removed in Nextcloud 34.
 *
 * Flags patterns like:
 *   \OC::$server->getDatabaseConnection()
 *   \OC::$server->getSystemConfig()
 *   \OC::$server->getLogger()
 *
 * These named accessors were removed in Nextcloud 34. The replacement pattern
 * is constructor dependency injection of the equivalent OCP interface.
 *
 * PSR-11 lookups such as \OC::$server->get(SomeClass::class) are NOT flagged
 * here; service-locator deprecation is tracked separately (design.md, D4).
 *
 * @author  Conduction
 * @package CustomSniffs
 */

namespace CustomSniffs\Sniffs\Nextcloud;

use PHP_CodeSniffer\Sniffs\Sniff;
use PHP_CodeSniffer\Files\File;

/**
 * NoLegacyServerAccessorsSniff — forbids removed \OC::$server->getX() accessors.
 */
class NoLegacyServerAccessorsSniff implements Sniff
{


    /**
     * Map of known named accessors to their approved OCP replacement interface.
     *
     * Covers the accessors that still appeared in this codebase plus the most
     * frequently used Nextcloud 34 removals. The error message interpolates the
     * accessor name and the replacement from this table so engineers see the
     * intended DI target at the violation site.
     *
     * @var array<string, string>
     */
    private const REPLACEMENTS = [
        'getSystemConfig'           => '\OCP\IConfig',
        'getConfig'                 => '\OCP\IConfig',
        'getDatabaseConnection'     => '\OCP\IDBConnection',
        'getLogger'                 => '\Psr\Log\LoggerInterface',
        'getL10NFactory'            => '\OCP\L10N\IFactory',
        'getL10N'                   => '\OCP\IL10N (via \OCP\L10N\IFactory)',
        'getUserSession'            => '\OCP\IUserSession',
        'getUserManager'            => '\OCP\IUserManager',
        'getGroupManager'           => '\OCP\IGroupManager',
        'getURLGenerator'           => '\OCP\IURLGenerator',
        'getRequest'                => '\OCP\IRequest',
        'getRootFolder'             => '\OCP\Files\IRootFolder',
        'getAppManager'             => '\OCP\App\IAppManager',
        'getSession'                => '\OCP\ISession',
        'getMemCacheFactory'        => '\OCP\ICacheFactory',
        'getEventDispatcher'        => '\OCP\EventDispatcher\IEventDispatcher',
        'getNotificationManager'    => '\OCP\Notification\IManager',
        'getTempManager'            => '\OCP\ITempManager',
        'getMimeTypeDetector'       => '\OCP\Files\IMimeTypeDetector',
        'getMimeTypeLoader'         => '\OCP\Files\IMimeTypeLoader',
        'getActivityManager'        => '\OCP\Activity\IManager',
        'getDateTimeFormatter'      => '\OCP\IDateTimeFormatter',
        'getDateTimeZone'           => '\OCP\IDateTimeZone',
        'getTrustedDomainHelper'    => '\OCP\Security\ITrustedDomainHelper',
        'getRegisteredAppContainer' => 'explicit constructor injection of the specific service',
    ];

    /**
     * Returns tokens this sniff listens for.
     *
     * Anchors on T_DOUBLE_COLON so we can reconstruct the full pattern
     * \OC :: $server -> getX ( in a single process() call.
     *
     * @return array<int>
     */
    public function register(): array
    {
        return [T_DOUBLE_COLON];

    }//end register()

    /**
     * Process a T_DOUBLE_COLON token — flag if part of \OC::$server->getX().
     *
     * @param File $phpcsFile The file being scanned.
     * @param int  $stackPtr  Position of the T_DOUBLE_COLON token.
     *
     * @return void
     */
    public function process(File $phpcsFile, $stackPtr): void
    {
        $tokens = $phpcsFile->getTokens();

        // Previous non-whitespace token must be T_STRING "OC".
        $prev = $phpcsFile->findPrevious(
            types: [T_WHITESPACE],
            start: ($stackPtr - 1),
            end: null,
            exclude: true
        );
        if ($prev === false
            || $tokens[$prev]['code'] !== T_STRING
            || $tokens[$prev]['content'] !== 'OC'
        ) {
            return;
        }

        // Next non-whitespace token must be T_VARIABLE "$server".
        $afterColon = $phpcsFile->findNext(
            types: [T_WHITESPACE],
            start: ($stackPtr + 1),
            end: null,
            exclude: true
        );
        if ($afterColon === false
            || $tokens[$afterColon]['code'] !== T_VARIABLE
            || $tokens[$afterColon]['content'] !== '$server'
        ) {
            return;
        }

        // Expect T_OBJECT_OPERATOR '->'.
        $arrow = $phpcsFile->findNext(
            types: [T_WHITESPACE],
            start: ($afterColon + 1),
            end: null,
            exclude: true
        );
        if ($arrow === false || $tokens[$arrow]['code'] !== T_OBJECT_OPERATOR) {
            return;
        }

        // Expect T_STRING method name.
        $methodPtr = $phpcsFile->findNext(
            types: [T_WHITESPACE],
            start: ($arrow + 1),
            end: null,
            exclude: true
        );
        if ($methodPtr === false || $tokens[$methodPtr]['code'] !== T_STRING) {
            return;
        }

        // Must be followed by ( to be a call.
        $openParen = $phpcsFile->findNext(
            types: [T_WHITESPACE],
            start: ($methodPtr + 1),
            end: null,
            exclude: true
        );
        if ($openParen === false || $tokens[$openParen]['code'] !== T_OPEN_PARENTHESIS) {
            return;
        }

        $methodName = $tokens[$methodPtr]['content'];

        // PSR-11 ->get(...) is deferred (D4 in design.md) — not flagged here.
        if ($methodName === 'get') {
            return;
        }

        // Only flag named accessors: getX where X starts with an uppercase letter.
        if (preg_match(pattern: '/^get[A-Z]/', subject: $methodName) !== 1) {
            return;
        }

        $replacement = self::REPLACEMENTS[$methodName] ?? 'the corresponding OCP interface';

        $error = 'Named accessor \\OC::$server->%s() is removed in Nextcloud 34. Inject %s via the constructor instead.';
        $phpcsFile->addError(
            $error,
            $stackPtr,
            'LegacyNamedAccessor',
            [$methodName, $replacement]
        );

    }//end process()
}//end class
