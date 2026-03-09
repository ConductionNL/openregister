<?php
/**
 * Enforce named parameters for all calls to internal (our own) code.
 *
 * Requires named arguments for:
 * - $this->method(name: $value)
 * - self::method(name: $value) / static::method(name: $value)
 * - parent::method(name: $value)
 * - new OurClass(name: $value) — classes from the same app namespace
 *
 * Allows positional arguments for external code:
 * - PHP built-in functions (strlen, array_map, sprintf, etc.)
 * - Nextcloud/third-party method calls ($variable->method() where $variable !== $this)
 * - Any call we cannot determine is "our code"
 *
 * @author  Conduction
 * @package CustomSniffs
 */

namespace CustomSniffs\Sniffs\Functions;

use PHP_CodeSniffer\Sniffs\Sniff;
use PHP_CodeSniffer\Files\File;

/**
 * NamedParametersSniff — enforces named parameters for internal code.
 */
class NamedParametersSniff implements Sniff
{


    /**
     * Returns tokens this sniff listens for.
     *
     * @return array<int>
     */
    public function register(): array
    {
        return [T_STRING];

    }//end register()


    /**
     * Process a T_STRING token — check if it's a function/method call to our code.
     *
     * @param File $phpcsFile The file being scanned.
     * @param int  $stackPtr  Position of the T_STRING token.
     *
     * @return void
     */
    public function process(File $phpcsFile, $stackPtr): void
    {
        $tokens       = $phpcsFile->getTokens();
        $functionName = $tokens[$stackPtr]['content'];

        // Must be followed by ( to be a function/method call.
        $openParen = $phpcsFile->findNext(T_WHITESPACE, ($stackPtr + 1), null, true);
        if ($openParen === false || $tokens[$openParen]['code'] !== T_OPEN_PARENTHESIS) {
            return;
        }

        // Skip function/method definitions.
        if ($this->isFunctionDefinition(phpcsFile: $phpcsFile, stackPtr: $stackPtr) === true) {
            return;
        }

        // Only check calls to our own code.
        if ($this->isInternalCall(phpcsFile: $phpcsFile, stackPtr: $stackPtr) === false) {
            return;
        }

        // Get the closing parenthesis.
        if (isset($tokens[$openParen]['parenthesis_closer']) === false) {
            return;
        }

        $closeParen = $tokens[$openParen]['parenthesis_closer'];

        // Check if there are any arguments.
        $firstContent = $phpcsFile->findNext(
            types: [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT],
            start: ($openParen + 1),
            end: $closeParen,
            exclude: true
        );
        if ($firstContent === false) {
            return;
        }

        // Check if all arguments use named parameters.
        if ($this->hasUnnamedArguments(phpcsFile: $phpcsFile, openParen: $openParen, closeParen: $closeParen) === true) {
            $error = 'All arguments in calls to internal code must use named parameters: %s(paramName: $value)';
            $phpcsFile->addError($error, $stackPtr, 'RequireNamedParameters', [$functionName]);
        }

    }//end process()


    /**
     * Check if the T_STRING at $stackPtr is part of a function/method definition (not a call).
     *
     * @param File $phpcsFile The file being scanned.
     * @param int  $stackPtr  Position of the T_STRING token.
     *
     * @return bool True if this is a definition, false if it's a call.
     */
    private function isFunctionDefinition(File $phpcsFile, int $stackPtr): bool
    {
        $tokens = $phpcsFile->getTokens();
        $prev   = ($stackPtr - 1);
        while ($prev >= 0) {
            $code = $tokens[$prev]['code'];
            if ($code === T_FUNCTION) {
                return true;
            }

            // Stop at statement/block boundaries.
            if ($code === T_SEMICOLON
                || $code === T_OPEN_CURLY_BRACKET
                || $code === T_CLOSE_CURLY_BRACKET
            ) {
                return false;
            }

            $prev--;
        }

        return false;

    }//end isFunctionDefinition()


    /**
     * Determine if the function/method call at $stackPtr is to our own code.
     *
     * Matches:
     * - $this->method()
     * - self::method() / static::method() / parent::method()
     * - new OurClass() where OurClass is from the same app namespace
     *
     * @param File $phpcsFile The file being scanned.
     * @param int  $stackPtr  Position of the T_STRING (function/method name).
     *
     * @return bool True if call is to internal code.
     */
    private function isInternalCall(File $phpcsFile, int $stackPtr): bool
    {
        $tokens = $phpcsFile->getTokens();

        $prev = $phpcsFile->findPrevious(
            types: [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT],
            start: ($stackPtr - 1),
            end: null,
            exclude: true
        );
        if ($prev === false) {
            return false;
        }

        $prevCode = $tokens[$prev]['code'];

        // Case 1: $this->method().
        if ($prevCode === T_OBJECT_OPERATOR) {
            $beforeArrow = $phpcsFile->findPrevious(
                types: [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT],
                start: ($prev - 1),
                end: null,
                exclude: true
            );
            return ($beforeArrow !== false
                && $tokens[$beforeArrow]['code'] === T_VARIABLE
                && $tokens[$beforeArrow]['content'] === '$this');
        }

        // Case 2: self::method() / static::method() / parent::method().
        if ($prevCode === T_DOUBLE_COLON) {
            $beforeColon = $phpcsFile->findPrevious(
                types: [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT],
                start: ($prev - 1),
                end: null,
                exclude: true
            );
            return ($beforeColon !== false
                && in_array($tokens[$beforeColon]['code'], [T_SELF, T_STATIC, T_PARENT], true) === true);
        }

        // Case 3: new OurClass().
        if ($prevCode === T_NEW) {
            return $this->isClassFromOurNamespace(phpcsFile: $phpcsFile, classNamePtr: $stackPtr);
        }

        return false;

    }//end isInternalCall()


    /**
     * Check if the class at $classNamePtr is from the same app namespace as the current file.
     *
     * Compares the class's use-import path against the file's OCA\AppName\ prefix.
     *
     * @param File $phpcsFile    The file being scanned.
     * @param int  $classNamePtr Position of the class name token.
     *
     * @return bool True if the class is from our app namespace.
     */
    private function isClassFromOurNamespace(File $phpcsFile, int $classNamePtr): bool
    {
        $tokens    = $phpcsFile->getTokens();
        $className = $tokens[$classNamePtr]['content'];

        // Find the file's namespace declaration.
        $appPrefix = $this->getAppNamespacePrefix(phpcsFile: $phpcsFile);
        if ($appPrefix === null) {
            return false;
        }

        // Scan use-statements for an import matching this class name from our namespace.
        for ($i = 0; $i < $phpcsFile->numTokens; $i++) {
            if ($tokens[$i]['code'] === T_USE) {
                $usePath = $this->getUseStatementPath(phpcsFile: $phpcsFile, usePtr: $i);
                if ($usePath === null) {
                    continue;
                }

                // Extract the imported short name (last segment).
                $segments     = explode(separator: '\\', string: $usePath);
                $importedName = end($segments);

                if ($importedName === $className
                    && str_starts_with(haystack: $usePath, needle: $appPrefix) === true
                ) {
                    return true;
                }
            }//end if

            // Stop scanning after the class declaration.
            if (in_array($tokens[$i]['code'], [T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM], true) === true) {
                break;
            }
        }//end for

        return false;

    }//end isClassFromOurNamespace()


    /**
     * Get the app namespace prefix (e.g., "OCA\MyDash") from the file's namespace declaration.
     *
     * @param File $phpcsFile The file being scanned.
     *
     * @return string|null The app prefix or null if not found.
     */
    private function getAppNamespacePrefix(File $phpcsFile): ?string
    {
        $tokens = $phpcsFile->getTokens();
        for ($i = 0; $i < $phpcsFile->numTokens; $i++) {
            if ($tokens[$i]['code'] === T_NAMESPACE) {
                $namespace = '';
                $j         = ($i + 1);
                while ($j < $phpcsFile->numTokens && $tokens[$j]['code'] !== T_SEMICOLON) {
                    if ($tokens[$j]['code'] !== T_WHITESPACE) {
                        $namespace .= $tokens[$j]['content'];
                    }

                    $j++;
                }

                // Extract first two segments: OCA\AppName.
                $parts = explode(separator: '\\', string: $namespace);
                if (count($parts) >= 2) {
                    return $parts[0].'\\'.$parts[1];
                }

                return null;
            }//end if
        }//end for

        return null;

    }//end getAppNamespacePrefix()


    /**
     * Extract the full path from a use-statement starting at $usePtr.
     *
     * @param File $phpcsFile The file being scanned.
     * @param int  $usePtr    Position of the T_USE token.
     *
     * @return string|null The use path or null if parsing failed.
     */
    private function getUseStatementPath(File $phpcsFile, int $usePtr): ?string
    {
        $tokens  = $phpcsFile->getTokens();
        $usePath = '';
        $j       = ($usePtr + 1);
        while ($j < $phpcsFile->numTokens) {
            $code = $tokens[$j]['code'];
            if ($code === T_SEMICOLON || $code === T_OPEN_CURLY_BRACKET) {
                break;
            }

            // Stop at 'as' keyword (aliases) — use the path before it.
            if ($code === T_AS) {
                break;
            }

            if ($code !== T_WHITESPACE) {
                $usePath .= $tokens[$j]['content'];
            }

            $j++;
        }

        $usePath = trim(string: $usePath);
        return ($usePath !== '') ? $usePath : null;

    }//end getUseStatementPath()


    /**
     * Check if the arguments between $openParen and $closeParen contain any unnamed arguments.
     *
     * An argument is "named" if its first significant token is followed by T_COLON.
     * Handles nested parentheses, brackets, and braces correctly.
     *
     * @param File $phpcsFile  The file being scanned.
     * @param int  $openParen  Position of the opening parenthesis.
     * @param int  $closeParen Position of the closing parenthesis.
     *
     * @return bool True if any argument is positional (unnamed).
     */
    private function hasUnnamedArguments(File $phpcsFile, int $openParen, int $closeParen): bool
    {
        $tokens         = $phpcsFile->getTokens();
        $parenDepth     = 0;
        $bracketDepth   = 0;
        $braceDepth     = 0;
        $atArgumentStart = true;

        for ($i = ($openParen + 1); $i < $closeParen; $i++) {
            $code = $tokens[$i]['code'];

            // Track nesting depth.
            if ($code === T_OPEN_PARENTHESIS) {
                $parenDepth++;
            } elseif ($code === T_CLOSE_PARENTHESIS) {
                $parenDepth--;
            } elseif ($code === T_OPEN_SHORT_ARRAY || $code === T_OPEN_SQUARE_BRACKET) {
                $bracketDepth++;
            } elseif ($code === T_CLOSE_SHORT_ARRAY || $code === T_CLOSE_SQUARE_BRACKET) {
                $bracketDepth--;
            } elseif ($code === T_OPEN_CURLY_BRACKET) {
                $braceDepth++;
            } elseif ($code === T_CLOSE_CURLY_BRACKET) {
                $braceDepth--;
            }

            // Only examine tokens at the top level of the argument list.
            if ($parenDepth > 0 || $bracketDepth > 0 || $braceDepth > 0) {
                continue;
            }

            // Comma at top level → next argument starts.
            if ($code === T_COMMA) {
                $atArgumentStart = true;
                continue;
            }

            // Skip whitespace and comments at argument start.
            if ($atArgumentStart === true
                && in_array($code, [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true) === true
            ) {
                continue;
            }

            if ($atArgumentStart === true) {
                $atArgumentStart = false;

                // Skip spread operator (...$args).
                if ($code === T_ELLIPSIS) {
                    continue;
                }

                // Check if this argument is named: token followed by ':'.
                $nextNonWs = $phpcsFile->findNext(
                    types: [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT],
                    start: ($i + 1),
                    end: $closeParen,
                    exclude: true
                );

                $isNamed = ($nextNonWs !== false && $tokens[$nextNonWs]['code'] === T_COLON);

                if ($isNamed === false) {
                    return true;
                }
            }//end if
        }//end for

        return false;

    }//end hasUnnamedArguments()


}//end class
