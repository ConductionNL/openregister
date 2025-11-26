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
        
        // Find the closing parenthesis.
        $closer = $tokens[$next]['parenthesis_closer'];
        
        // Check if there are parameters.
        $paramStart = $next + 1;
        $paramEnd = $closer - 1;
        
        if ($paramStart >= $paramEnd) {
            return; // No parameters
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
            
            // Skip built-in functions that commonly don't benefit from named parameters.
            $skipFunctions = [
                // Basic output functions.
                
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