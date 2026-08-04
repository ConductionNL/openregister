<?php
/**
 * Warn when a PHP class or public method lacks an @spec PHPDoc tag.
 *
 * ConductionNL ADR-003 (Backend rules) mandates that every class and public
 * method MUST have one or more @spec PHPDoc tags linking back to the OpenSpec
 * change that caused the code to exist:
 *
 *     @spec openspec/changes/{change-name}/tasks.md#task-N
 *
 * This sniff emits warnings (not errors) so that CI surfaces the gap without
 * blocking merges while teams backfill coverage. Tests files and magic
 * methods are skipped, as are private/protected methods — the ADR only
 * mandates @spec for classes and public methods.
 *
 * @author  Conduction
 * @package CustomSniffs
 */

namespace CustomSniffs\Sniffs\Commenting;

use PHP_CodeSniffer\Sniffs\Sniff;
use PHP_CodeSniffer\Files\File;

/**
 * SpecTagSniff — warns when @spec PHPDoc tag is missing on classes / public methods.
 */
class SpecTagSniff implements Sniff
{


    /**
     * PHP magic methods that are exempt from the @spec requirement.
     *
     * @var array<string>
     */
    private const MAGIC_METHODS = [
        '__construct',
        '__destruct',
        '__get',
        '__set',
        '__call',
        '__callstatic',
        '__isset',
        '__unset',
        '__tostring',
        '__invoke',
        '__clone',
        '__sleep',
        '__wakeup',
        '__serialize',
        '__unserialize',
        '__set_state',
        '__debuginfo',
    ];


    /**
     * Returns tokens this sniff listens for.
     *
     * @return array<int>
     */
    public function register(): array
    {
        return [T_CLASS, T_FUNCTION];

    }//end register()


    /**
     * Process a T_CLASS or T_FUNCTION token.
     *
     * @param File $phpcsFile The file being scanned.
     * @param int  $stackPtr  Position of the token.
     *
     * @return void
     */
    public function process(File $phpcsFile, $stackPtr): void
    {
        // Skip test files.
        if ($this->isTestFile(phpcsFile: $phpcsFile) === true) {
            return;
        }

        $tokens = $phpcsFile->getTokens();
        $code   = $tokens[$stackPtr]['code'];

        if ($code === T_CLASS) {
            $this->processClass(phpcsFile: $phpcsFile, stackPtr: $stackPtr);
            return;
        }

        if ($code === T_FUNCTION) {
            $this->processFunction(phpcsFile: $phpcsFile, stackPtr: $stackPtr);
            return;
        }

    }//end process()


    /**
     * Check a class declaration for an @spec docblock tag.
     *
     * Skips anonymous classes (no name follows the T_CLASS keyword).
     *
     * @param File $phpcsFile The file being scanned.
     * @param int  $stackPtr  Position of the T_CLASS token.
     *
     * @return void
     */
    private function processClass(File $phpcsFile, int $stackPtr): void
    {
        $tokens = $phpcsFile->getTokens();

        // Anonymous classes — $var = new class { ... } — have no name; skip.
        $namePtr = $phpcsFile->findNext(T_STRING, ($stackPtr + 1), null, false, null, true);
        if ($namePtr === false) {
            return;
        }

        // Sanity: name should be on the same line or within a short window.
        $openBracePtr = $phpcsFile->findNext(T_OPEN_CURLY_BRACKET, ($stackPtr + 1));
        if ($openBracePtr !== false && $namePtr > $openBracePtr) {
            return;
        }

        $className = $tokens[$namePtr]['content'];

        if ($this->hasSpecTag(phpcsFile: $phpcsFile, stackPtr: $stackPtr) === true) {
            return;
        }

        $message = 'Class %s is missing @spec PHPDoc tag — link back to openspec/changes/{name}/tasks.md#task-N';
        $phpcsFile->addWarning($message, $stackPtr, 'MissingClassSpec', [$className]);

    }//end processClass()


    /**
     * Check a function declaration for an @spec docblock tag.
     *
     * Only flags public methods declared inside a class. Global functions,
     * private/protected methods, and magic methods are skipped.
     *
     * @param File $phpcsFile The file being scanned.
     * @param int  $stackPtr  Position of the T_FUNCTION token.
     *
     * @return void
     */
    private function processFunction(File $phpcsFile, int $stackPtr): void
    {
        $tokens = $phpcsFile->getTokens();

        // Must be inside a class scope.
        $className = $this->getEnclosingClassName(phpcsFile: $phpcsFile, stackPtr: $stackPtr);
        if ($className === null) {
            return;
        }

        // Get method name.
        $namePtr = $phpcsFile->findNext(T_STRING, ($stackPtr + 1));
        if ($namePtr === false) {
            return;
        }

        $methodName = $tokens[$namePtr]['content'];

        // Skip magic methods.
        if (in_array(strtolower($methodName), self::MAGIC_METHODS, true) === true) {
            return;
        }

        // Determine visibility: default is public when no modifier present.
        if ($this->isPublicMethod(phpcsFile: $phpcsFile, stackPtr: $stackPtr) === false) {
            return;
        }

        if ($this->hasSpecTag(phpcsFile: $phpcsFile, stackPtr: $stackPtr) === true) {
            return;
        }

        $message = 'Public method %s::%s() is missing @spec PHPDoc tag';
        $phpcsFile->addWarning($message, $stackPtr, 'MissingMethodSpec', [$className, $methodName]);

    }//end processFunction()


    /**
     * Check whether the docblock directly preceding $stackPtr contains an @spec tag.
     *
     * Walks backwards from the token skipping whitespace, attribute tokens, and
     * visibility/abstract/final/static modifiers. If the next non-skipped token
     * is the close of a doc comment, scan the block for @spec.
     *
     * @param File $phpcsFile The file being scanned.
     * @param int  $stackPtr  Position of the class/function token.
     *
     * @return bool True when an @spec tag is present.
     */
    private function hasSpecTag(File $phpcsFile, int $stackPtr): bool
    {
        $tokens = $phpcsFile->getTokens();

        $skip = [
            T_WHITESPACE,
            T_ABSTRACT,
            T_FINAL,
            T_STATIC,
            T_PUBLIC,
            T_PROTECTED,
            T_PRIVATE,
            T_READONLY,
            T_ATTRIBUTE,
            T_ATTRIBUTE_END,
        ];

        $ptr = ($stackPtr - 1);
        while ($ptr >= 0) {
            $code = $tokens[$ptr]['code'];

            // Skip over attribute blocks (PHP 8 #[Attribute]) in full.
            if ($code === T_ATTRIBUTE_END && isset($tokens[$ptr]['attribute_opener']) === true) {
                $ptr = ($tokens[$ptr]['attribute_opener'] - 1);
                continue;
            }

            if (in_array($code, $skip, true) === true) {
                $ptr--;
                continue;
            }

            break;
        }

        if ($ptr < 0) {
            return false;
        }

        if ($tokens[$ptr]['code'] !== T_DOC_COMMENT_CLOSE_TAG) {
            return false;
        }

        if (isset($tokens[$ptr]['comment_opener']) === false) {
            return false;
        }

        $opener = $tokens[$ptr]['comment_opener'];
        for ($i = $opener; $i <= $ptr; $i++) {
            if ($tokens[$i]['code'] === T_DOC_COMMENT_TAG
                && strtolower($tokens[$i]['content']) === '@spec'
            ) {
                return true;
            }
        }

        return false;

    }//end hasSpecTag()


    /**
     * Determine if the function at $stackPtr is a public method.
     *
     * Methods default to public when no visibility modifier is present.
     *
     * @param File $phpcsFile The file being scanned.
     * @param int  $stackPtr  Position of the T_FUNCTION token.
     *
     * @return bool True when the method is public (explicit or default).
     */
    private function isPublicMethod(File $phpcsFile, int $stackPtr): bool
    {
        $tokens = $phpcsFile->getTokens();

        $ptr = ($stackPtr - 1);
        while ($ptr >= 0) {
            $code = $tokens[$ptr]['code'];
            if ($code === T_PUBLIC) {
                return true;
            }

            if ($code === T_PROTECTED || $code === T_PRIVATE) {
                return false;
            }

            if ($code === T_WHITESPACE
                || $code === T_ABSTRACT
                || $code === T_FINAL
                || $code === T_STATIC
                || $code === T_READONLY
            ) {
                $ptr--;
                continue;
            }

            // Skip attributes in full.
            if ($code === T_ATTRIBUTE_END && isset($tokens[$ptr]['attribute_opener']) === true) {
                $ptr = ($tokens[$ptr]['attribute_opener'] - 1);
                continue;
            }

            if ($code === T_DOC_COMMENT_CLOSE_TAG
                || $code === T_COMMENT
                || $code === T_OPEN_CURLY_BRACKET
                || $code === T_CLOSE_CURLY_BRACKET
                || $code === T_SEMICOLON
            ) {
                // No visibility modifier found — default public.
                return true;
            }

            $ptr--;
        }

        return true;

    }//end isPublicMethod()


    /**
     * Return the name of the class/interface/trait/enum enclosing $stackPtr, or null.
     *
     * @param File $phpcsFile The file being scanned.
     * @param int  $stackPtr  Position of the token to inspect.
     *
     * @return string|null The enclosing class name, or null when at file scope.
     */
    private function getEnclosingClassName(File $phpcsFile, int $stackPtr): ?string
    {
        $tokens = $phpcsFile->getTokens();

        if (isset($tokens[$stackPtr]['conditions']) === false) {
            return null;
        }

        // Walk the conditions chain looking for the innermost class-like scope.
        $classLike = [T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM, T_ANON_CLASS];

        foreach (array_reverse($tokens[$stackPtr]['conditions'], true) as $scopePtr => $scopeCode) {
            if (in_array($scopeCode, $classLike, true) === true) {
                $namePtr = $phpcsFile->findNext(T_STRING, ($scopePtr + 1));
                if ($namePtr === false) {
                    return '{anonymous}';
                }

                // Sanity: ensure the name is before the opening brace for that class.
                if (isset($tokens[$scopePtr]['scope_opener']) === true
                    && $namePtr > $tokens[$scopePtr]['scope_opener']
                ) {
                    return '{anonymous}';
                }

                return $tokens[$namePtr]['content'];
            }
        }

        return null;

    }//end getEnclosingClassName()


    /**
     * Check whether the currently-scanned file is a test file.
     *
     * @param File $phpcsFile The file being scanned.
     *
     * @return bool True for files under /tests/ or /Tests/.
     */
    private function isTestFile(File $phpcsFile): bool
    {
        $path = str_replace('\\', '/', $phpcsFile->getFilename());
        return (stripos($path, '/tests/') !== false);

    }//end isTestFile()


}//end class
