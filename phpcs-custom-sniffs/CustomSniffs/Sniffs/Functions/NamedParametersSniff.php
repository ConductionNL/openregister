<?php
/**
 * Custom PHPCS Sniff to encourage named parameters usage
 * 
 * This sniff checks for function calls and suggests using named parameters
 * for better code readability and maintainability.
 * 
 * @author OpenRegister Team
 * @package CustomSniffs
 */

namespace CustomSniffs\Sniffs\Functions;

use PHP_CodeSniffer\Sniffs\Sniff;
use PHP_CodeSniffer\Files\File;

/**
 * NamedParametersSniff
 * 
 * Encourages the use of named parameters in function calls
 */
class NamedParametersSniff implements Sniff
{
    /**
     * Returns an array of tokens this test wants to listen for.
     *
     * @return array
     */
    public function register()
    {
        return [T_STRING];
    }

    /**
     * Processes this test, when one of its tokens is encountered.
     *
     * @param File $phpcsFile The file being scanned.
     * @param int  $stackPtr  The position of the current token in the stack.
     *
     * @return void
     */
    public function process(File $phpcsFile, $stackPtr)
    {
        $tokens = $phpcsFile->getTokens();
        
        // Check if this is a function call (look for opening parenthesis after the function name).
        $next = $phpcsFile->findNext(T_WHITESPACE, ($stackPtr + 1), null, true);
        if ($next === false || $tokens[$next]['code'] !== T_OPEN_PARENTHESIS) {
            return;
        }
        
        // Check if this is a method call (preceded by -> or ::).
        $isMethodCall = false;
        $prevToken = $phpcsFile->findPrevious([T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], ($stackPtr - 1), null, true);
        if ($prevToken !== false && 
            ($tokens[$prevToken]['code'] === T_OBJECT_OPERATOR || 
             $tokens[$prevToken]['code'] === T_DOUBLE_COLON)) {
            $isMethodCall = true;
        }
        
        // Skip function definitions - look for 'function' keyword before this token.
        // We need to check if this T_STRING is part of a function declaration.
        $prev = $stackPtr - 1;
        while ($prev >= 0 && isset($tokens[$prev])) {
            if ($tokens[$prev]['code'] === T_FUNCTION) {
                // This is a function definition, skip it.
                return;
            }
            if ($tokens[$prev]['code'] === T_SEMICOLON || 
                $tokens[$prev]['code'] === T_OPEN_CURLY_BRACKET ||
                $tokens[$prev]['code'] === T_CLOSE_CURLY_BRACKET) {
                // We've gone past a statement boundary, this is likely a function call.
                break;
            }
            $prev--;
        }
        
        // Skip parent class methods that don't support named parameters.
        // QBMapper::find() and similar parent class methods.
        $functionName = $tokens[$stackPtr]['content'];
        $parentClassMethods = ['find', 'findEntity', 'findAll', 'findEntities', 'insert', 'update', 'delete', 'insertOrUpdate'];
        if ($isMethodCall && in_array(strtolower($functionName), $parentClassMethods)) {
            // This is likely a parent class method call, skip named parameter checking.
            return;
        }
        
        // Find the closing parenthesis.
        // Check if PHP_CodeSniffer has already parsed the parenthesis pair.
        if (isset($tokens[$next]['parenthesis_closer'])) {
            $closer = $tokens[$next]['parenthesis_closer'];
        } else {
            // Manually find the matching closing parenthesis.
            $parenLevel = 1;
            $closer = $next + 1;
            while ($closer < $phpcsFile->numTokens && $parenLevel > 0) {
                if ($tokens[$closer]['code'] === T_OPEN_PARENTHESIS) {
                    $parenLevel++;
                } elseif ($tokens[$closer]['code'] === T_CLOSE_PARENTHESIS) {
                    $parenLevel--;
                }
                // Only increment if we haven't found the matching closing parenthesis yet.
                if ($parenLevel > 0) {
                    $closer++;
                }
            }
            // If we couldn't find a matching closing parenthesis, skip this token.
            if ($parenLevel !== 0 || $closer >= $phpcsFile->numTokens) {
                return;
            }
        }
        
        // Check if there are parameters.
        $paramStart = $next + 1;
        $paramEnd = $closer - 1;
        
        if ($paramStart >= $paramEnd) {
            return; // No parameters
        }
        
        // Check for positional arguments after named arguments (PHP 8+ fatal error).
        // This is a critical error that must be caught.
        $hasNamedParam = false;
        $parenLevel = 1;
        $lastCommaPos = null;
        
        for ($i = $paramStart; $i <= $paramEnd && $parenLevel > 0; $i++) {
            if ($tokens[$i]['code'] === T_OPEN_PARENTHESIS) {
                $parenLevel++;
            } elseif ($tokens[$i]['code'] === T_CLOSE_PARENTHESIS) {
                $parenLevel--;
            } elseif ($tokens[$i]['code'] === T_COMMA && $parenLevel === 1) {
                $lastCommaPos = $i;
            } elseif ($tokens[$i]['code'] === T_GOTO_LABEL && $parenLevel === 1) {
                // Found a named parameter (label followed by colon).
                $hasNamedParam = true;
            } elseif ($hasNamedParam && $lastCommaPos !== null && $i > $lastCommaPos && $parenLevel === 1) {
                // We have a named parameter and we're past a comma.
                // Check if this is a positional argument (not a named one).
                if ($tokens[$i]['code'] !== T_WHITESPACE && 
                    $tokens[$i]['code'] !== T_GOTO_LABEL &&
                    $tokens[$i]['code'] !== T_COLON) {
                    // Check if next non-whitespace token is NOT a colon (which would indicate named param).
                    $nextNonWhitespace = $phpcsFile->findNext(T_WHITESPACE, $i + 1, $paramEnd + 1, true);
                    if ($nextNonWhitespace === false || 
                        ($tokens[$nextNonWhitespace]['code'] !== T_COLON && 
                         $tokens[$nextNonWhitespace]['code'] !== T_GOTO_LABEL)) {
                        // This looks like a positional argument after a named one!
                        $error = 'Cannot use positional argument after named argument (PHP 8+ fatal error). ' .
                                 'All arguments after the first named argument must also be named.';
                        $phpcsFile->addError($error, $stackPtr, 'PositionalAfterNamedArgument');
                        return; // Don't continue with warnings if we found this critical error.
                    }
                }
            }
        }
        
        // Count parameters by counting commas + 1 (if there are any non-whitespace tokens).
        $parameterCount = 0;
        $hasNamedParameters = false;
        $hasContent = false;
        
        $parenLevel = 1;
        for ($i = $paramStart; $i <= $paramEnd && $parenLevel > 0; $i++) {
            if ($tokens[$i]['code'] === T_OPEN_PARENTHESIS) {
                $parenLevel++;
            } elseif ($tokens[$i]['code'] === T_CLOSE_PARENTHESIS) {
                $parenLevel--;
                if ($parenLevel === 0 && $hasContent) {
                    $parameterCount++; // Count the last parameter
                }
            } elseif ($tokens[$i]['code'] === T_COMMA && $parenLevel === 1) {
                $parameterCount++;
            } elseif ($tokens[$i]['code'] === T_GOTO_LABEL && $parenLevel === 1) {
                $hasNamedParameters = true;
            } elseif ($tokens[$i]['code'] !== T_WHITESPACE) {
                $hasContent = true;
            }
        }
        
        // Suggest named parameters for functions with 1+ parameters (they might have defaults).
        if ($parameterCount >= 1 && !$hasNamedParameters) {
            $functionName = $tokens[$stackPtr]['content'];
            
            // Skip built-in functions that don't support named parameters or don't benefit from them.
            $skipFunctions = [
                // Basic output functions.
                'echo', 'print', 'var_dump', 'print_r', 'var_export',
                
                // Type checking functions.
                'empty', 'isset', 'is_null', 'is_array', 'is_string', 'is_int', 'is_bool',
                'is_object', 'is_numeric', 'is_callable', 'is_resource',
                
                // String functions (simple ones).
                'strlen', 'trim', 'ltrim', 'rtrim', 'strtolower', 'strtoupper', 'ucfirst',
                'ucwords', 'lcfirst', 'ord', 'chr', 'md5', 'sha1', 'crc32',
                
                // Array functions (simple ones).
                'count', 'sizeof', 'array_push', 'array_pop', 'array_shift', 'array_unshift',
                'array_keys', 'array_values', 'array_reverse', 'array_unique', 'array_sum',
                'array_product', 'min', 'max', 'end', 'reset', 'key', 'current', 'next', 'prev',
                
                // Array functions that commonly use callbacks (might benefit from named params but often don't).
                'array_filter', 'array_map', 'array_reduce', 'array_walk', 'usort', 'uksort',
                'uasort', 'array_search', 'array_key_exists', 'in_array',
                
                // String manipulation that's usually obvious.
                'implode', 'explode', 'str_repeat', 'str_pad', 'wordwrap',
                
                // Built-in functions that DON'T support named parameters (PHP built-ins).
                // These use variadic arguments or have special calling conventions.
                'sprintf', 'printf', 'fprintf', 'vprintf', 'vfprintf', 'vsprintf',
                'unset', 'isset', 'empty',
                'call_user_func', 'call_user_func_array',
                'array_merge', 'array_merge_recursive',
                
                // Serialization.
                'json_encode', 'json_decode', 'serialize', 'unserialize',
                
                // Math functions.
                'abs', 'ceil', 'floor', 'round', 'sqrt', 'pow', 'log', 'sin', 'cos', 'tan',
                'rand', 'mt_rand', 'srand', 'mt_srand',
                
                // File functions (simple ones).
                'file_exists', 'is_file', 'is_dir', 'is_readable', 'is_writable',
                'filesize', 'filemtime', 'filectime', 'fileatime', 'dirname', 'basename',
                
                // DateTime (simple constructors).
                'time', 'microtime', 'date', 'gmdate', 'mktime', 'gmmktime'
            ];
            
            if (!in_array(strtolower($functionName), $skipFunctions)) {
                $warning = 'Consider using named parameters for function "%s" to improve code readability: %s(parameterName: $value)';
                $data = [$functionName, $functionName];
                $phpcsFile->addWarning($warning, $stackPtr, 'ShouldUseNamedParameters', $data);
            }
        }
    }
} 