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
        $isConstructor = false;
        $prevToken = $phpcsFile->findPrevious([T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], ($stackPtr - 1), null, true);
        if ($prevToken !== false && 
            ($tokens[$prevToken]['code'] === T_OBJECT_OPERATOR || 
             $tokens[$prevToken]['code'] === T_DOUBLE_COLON)) {
            $isMethodCall = true;
        }
        
        // Check if this is a constructor call (preceded by 'new' keyword).
        if ($prevToken !== false && $tokens[$prevToken]['code'] === T_NEW) {
            $isConstructor = true;
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
        
        // Skip parent::__construct calls - they're calling parent class constructors we don't control.
        if ($isMethodCall && strtolower($functionName) === '__construct') {
            // Check if this is a parent:: call by looking backwards for 'parent' keyword.
            $checkToken = $prevToken;
            while ($checkToken !== false && $checkToken >= 0) {
                if ($tokens[$checkToken]['code'] === T_DOUBLE_COLON) {
                    $prevPrevToken = $phpcsFile->findPrevious([T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], ($checkToken - 1), null, true);
                    if ($prevPrevToken !== false && strtolower($tokens[$prevPrevToken]['content']) === 'parent') {
                        return; // Skip parent::__construct calls.
                    }
                    break;
                }
                if ($tokens[$checkToken]['code'] !== T_WHITESPACE && $tokens[$checkToken]['code'] !== T_COMMENT && $tokens[$checkToken]['code'] !== T_DOC_COMMENT) {
                    break;
                }
                $checkToken--;
            }
        }
        
        $parentClassMethods = ['find', 'findEntity', 'findAll', 'findEntities', 'insert', 'update', 'delete', 'insertOrUpdate'];
        if ($isMethodCall && in_array(strtolower($functionName), $parentClassMethods)) {
            // This is likely a parent class method call, skip named parameter checking.
            return;
        }
        
        // Skip Nextcloud/Doctrine QueryBuilder methods that don't support named parameters well.
        // These are fluent interface methods where named parameters don't make sense or aren't supported.
        $queryBuilderMethods = [
            // QueryBuilder fluent interface methods.
            'select', 'from', 'where', 'andwhere', 'orwhere', 'orderby', 'groupby', 'selectalias',
            'having', 'andhaving', 'orhaving', 'setmaxresults', 'setfirstresult',
            'setparameter', 'setparameters', 'createnamedparameter', 'createparameter',
            'createpositionalparameter', 'createfunction', 'executequery', 'executestatement',
            'getsql', 'getparameters', 'getparameter', 'getparametertypes',
            'set', 'update', 'insert', 'delete', 'values',
            // QueryBuilder join methods.
            'leftjoin', 'rightjoin', 'innerjoin', 'join',
            // QueryBuilder expression methods.
            'expr', 'eq', 'neq', 'lt', 'lte', 'gt', 'gte', 'like', 'notlike',
            'in', 'notin', 'isnull', 'isnotnull', 'between', 'notbetween',
            'orx', 'andx', 'add', 'addgroupby', 'addorderby',
            // Result set methods.
            'fetch', 'fetchall', 'fetchone', 'fetchassociative', 'fetchnumeric',
            'fetchcolumn', 'fetchfirstcolumn', 'rowcount', 'closecursor',
            // Database connection methods.
            'getquerybuilder', 'getconnection', 'getentitymanager', 'getrepository',
            'begintransaction', 'commit', 'rollback', 'prepare', 'execute',
            // PDO methods.
            'bindvalue', 'bindparam', 'execute', 'fetch', 'fetchall', 'fetchcolumn',
            // Other Nextcloud/Doctrine methods.
            'getdb', 'gettable', 'gettableName',
            // PSR Logger methods.
            'emergency', 'alert', 'critical', 'error', 'warning', 'notice', 'info', 'debug', 'log',
            // PHP Reflection methods.
            'setvalue', 'getvalue', 'setaccessible', 'getaccessible', 'invoke', 'invokeargs',
            'newinstance', 'newinstanceargs',
            // Getter methods (typically don't need named parameters).
            'getproperty', 'getmessage', 'getcode', 'getfile', 'getline', 'gettrace', 'getprevious',
            // Nextcloud Response methods.
            'addheader', 'setheader', 'setstatus', 'setcontenttype',
            // Nextcloud IRequest methods.
            'getparam', 'getparams', 'getuploadedfile', 'getuploadedfiles',
            // Nextcloud IAppConfig methods.
            'getvaluebool', 'getvalueint', 'getvaluestring', 'getvaluearray', 'setvaluestring',
            // Nextcloud IConfig methods.
            'getappvalue', 'setappvalue', 'getuservalue', 'setuservalue', 'deleteuservalue', 'getsystemvalue',
            // Nextcloud IUserManager methods.
            'createuser', 'checkpassword',
            // Nextcloud Files_Versions methods.
            'getversionfile',
            // Nextcloud IURLGenerator methods.
            'linktoroute', 'getabsoluteurl',
            // GuzzleHttp Client methods.
            'request', 'get', 'post', 'put', 'delete', 'patch', 'head', 'options',
            // Opis JsonSchema library methods.
            'register', 'registerprotocol', 'validate', 'format', 'getproperty', 'geterrors',
            // GuzzleHttp Psr7 Uri static methods.
            'fromparts',
            // ReactPHP Promise methods.
            'promise', 'then', 'catch', 'finally', 'otherwise', 'always',
            // ZipArchive methods.
            'open', 'addfromstring',
            // Doctrine Schema Builder methods (used in migrations).
            'addcolumn', 'addindex', 'adduniqueindex', 'addtype', 'addoption',
            'dropcolumn', 'dropindex', 'dropuniqueindex', 'droptype', 'dropoption',
            'modifycolumn', 'changecolumn', 'renamecolumn', 'renameindex',
            'setprimarykey', 'dropprimarykey', 'addforeignkey', 'dropforeignkey',
            'setcomment', 'setcharset', 'setcollation',
            // OpenRegister domain-specific methods.
            'ismagicmappingenabledforschema'
        ];
        if ($isMethodCall && in_array(strtolower($functionName), $queryBuilderMethods)) {
            // This is a Nextcloud/Doctrine method that doesn't support named parameters well.
            return;
        }
        
        // Skip Nextcloud framework constructor calls (DataDownloadResponse, JSONResponse, etc.).
        if ($isConstructor) {
            $nextcloudConstructors = [
                'datadownloadresponse', 'jsonresponse', 'templateresponse', 'streamresponse',
                'fileresponse', 'redirectresponse', 'downloadresponse',
                // OpenRegister setup classes.
                'solrsetup',
                // Third-party library constructors (Opis JsonSchema).
                'validationresult', 'errorformatter', 'validator',
                // PHP built-in exception constructors.
                'exception', 'runtimeexception', 'invalidargumentexception', 'logicexception',
                // Nextcloud exception constructors.
                'ocpdbexception',
                // QBMapper parent class constructor.
                'qbmapper',
                // Event classes (extend OCP\EventDispatcher\Event).
                'registerupdatedevent', 'organisationupdatedevent', 'registercreatedevent', 'registerdeletedevent',
                'schemaupdatedevent', 'schemacreatedevent', 'schemadeletedevent',
                'objectupdatedevent', 'objectcreatedevent', 'objectdeletedevent', 'objectrevertedevent', 'objectdeletingevent',
                'viewupdatedevent', 'viewcreatedevent', 'viewdeletedevent',
                'sourceupdatedevent', 'sourcecreatedevent', 'sourcedeletedevent',
                'agentupdatedevent', 'agentcreatedevent', 'agentdeletedevent',
                'applicationupdatedevent', 'applicationcreatedevent', 'applicationdeletedevent',
                'conversationupdatedevent', 'conversationcreatedevent', 'conversationdeletedevent',
                'configurationupdatedevent', 'configurationcreatedevent', 'configurationdeletedevent',
                'toolregistrationevent',
                // OpenRegister internal constructors.
                'objecthandler',
                // ReactPHP Promise constructor.
                'promise'
            ];
            if (in_array(strtolower($functionName), $nextcloudConstructors)) {
                // This is a Nextcloud framework constructor, skip named parameter checking.
                return;
            }
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
            } elseif ($tokens[$i]['code'] === T_STRING && $parenLevel === 1) {
                // Check if this is a named parameter: T_STRING followed by T_COLON.
                // Make sure it's not part of a class name (like ClassName::class).
                $prevNonWhitespace = $phpcsFile->findPrevious([T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], $i - 1, $paramStart, true);
                $nextNonWhitespace = $phpcsFile->findNext(T_WHITESPACE, $i + 1, $paramEnd + 1, true);
                // Named parameter: T_STRING followed by T_COLON, and not preceded by T_DOUBLE_COLON.
                if ($nextNonWhitespace !== false 
                    && $tokens[$nextNonWhitespace]['code'] === T_COLON
                    && ($prevNonWhitespace === false || $tokens[$prevNonWhitespace]['code'] !== T_DOUBLE_COLON)) {
                    // Found a named parameter (parameter name followed by colon).
                    $hasNamedParam = true;
                }
            } elseif ($tokens[$i]['code'] === T_GOTO_LABEL && $parenLevel === 1) {
                // Also check for goto label syntax (though less common for named params).
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
        
        // Quick heuristic check: if we detected named params in the first loop, use that.
        if ($hasNamedParam === true) {
            $hasNamedParameters = true;
        }
        
        // Also do a quick string-based check for named parameter pattern.
        // Build parameter content string for regex matching.
        $paramContent = '';
        for ($contentIdx = $paramStart; $contentIdx <= $paramEnd; $contentIdx++) {
            if (isset($tokens[$contentIdx]['content'])) {
                $paramContent .= $tokens[$contentIdx]['content'];
            }
        }
        // Check for named parameter pattern: word followed by colon and value.
        // Pattern: identifier : $variable or identifier : 'string' or identifier : 123
        // But exclude :: (double colon) patterns.
        if (preg_match('/\b[a-zA-Z_][a-zA-Z0-9_]*\s*:\s*(?:\$[a-zA-Z0-9_]|[0-9]+|["\']|null|true|false|array\s*\(|\[)/', $paramContent) === 1) {
            // Found named parameter pattern. Verify it's not just :: patterns.
            // Remove all :: patterns and check again.
            $withoutDoubleColon = preg_replace('/::/', '', $paramContent);
            if (preg_match('/\b[a-zA-Z_][a-zA-Z0-9_]*\s*:\s*(?:\$[a-zA-Z0-9_]|[0-9]+|["\']|null|true|false|array\s*\(|\[)/', $withoutDoubleColon) === 1) {
                $hasNamedParameters = true;
            }
        }
        
        // First, check if there are any named parameters by looking for T_COLON in the parameter list.
        // A named parameter has the format: parameterName: value
        // We look for T_COLON that's preceded by T_STRING (not part of ::) and followed by a value.
        // Note: We're already inside the function call's parentheses, so start at level 1.
        $checkParenLevel = 1;
        $checkBracketLevel = 0;
        $checkBraceLevel = 0;
        for ($checkIdx = $paramStart; $checkIdx <= $paramEnd; $checkIdx++) {
            if ($tokens[$checkIdx]['code'] === T_OPEN_PARENTHESIS) {
                $checkParenLevel++;
            } elseif ($tokens[$checkIdx]['code'] === T_CLOSE_PARENTHESIS) {
                $checkParenLevel--;
            } elseif ($tokens[$checkIdx]['code'] === T_OPEN_SQUARE_BRACKET || $tokens[$checkIdx]['code'] === T_OPEN_SHORT_ARRAY) {
                $checkBracketLevel++;
            } elseif ($tokens[$checkIdx]['code'] === T_CLOSE_SQUARE_BRACKET) {
                $checkBracketLevel--;
            } elseif ($tokens[$checkIdx]['code'] === T_OPEN_CURLY_BRACKET) {
                $checkBraceLevel++;
            } elseif ($tokens[$checkIdx]['code'] === T_CLOSE_CURLY_BRACKET) {
                $checkBraceLevel--;
            } elseif ($tokens[$checkIdx]['code'] === T_COLON 
                      && $checkParenLevel === 1 
                      && $checkBracketLevel === 0 
                      && $checkBraceLevel === 0) {
                // Found a colon at parameter level. Check if it's part of a named parameter.
                // A named parameter colon should be preceded by T_STRING (parameter name).
                $prevToken = $phpcsFile->findPrevious([T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], $checkIdx - 1, $paramStart - 1, true);
                if ($prevToken !== false 
                    && $prevToken >= $paramStart
                    && isset($tokens[$prevToken])
                    && $tokens[$prevToken]['code'] === T_STRING) {
                    // Found T_STRING before colon - check it's not part of :: (double colon).
                    $isNamedParam = true;
                    // Check token immediately before prevToken (skip whitespace).
                    if ($prevToken > $paramStart) {
                        $immediatePrev = $prevToken - 1;
                        while ($immediatePrev >= $paramStart && in_array($tokens[$immediatePrev]['code'], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                            $immediatePrev--;
                        }
                        if ($immediatePrev >= $paramStart && isset($tokens[$immediatePrev]) && $tokens[$immediatePrev]['code'] === T_DOUBLE_COLON) {
                            // This is part of :: (like ClassName::class), not a named parameter.
                            $isNamedParam = false;
                        }
                    }
                    // Check if next token after colon is a valid value.
                    if ($isNamedParam === true) {
                        $valueToken = $phpcsFile->findNext([T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], $checkIdx + 1, $paramEnd + 1, true);
                        if ($valueToken !== false && $valueToken <= $paramEnd) {
                            // Valid value tokens for named parameters.
                            $validValueTokens = [
                                T_VARIABLE, T_CONSTANT_ENCAPSED_STRING, T_LNUMBER, T_DNUMBER,
                                T_STRING, T_ARRAY, T_OPEN_SHORT_ARRAY, T_NULL, T_TRUE, T_FALSE,
                                T_OPEN_PARENTHESIS, T_STATIC, T_NEW
                            ];
                            if (in_array($tokens[$valueToken]['code'], $validValueTokens, true)) {
                                $hasNamedParameters = true;
                                break; // Found at least one named parameter, no need to continue.
                            }
                        }
                    }
                }
            }
        }
        
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
                'str_contains', 'str_starts_with', 'str_ends_with', 'strpos', 'stripos', 'strrpos', 'strripos',
            'mb_check_encoding', 'mb_convert_encoding',
                
                // Array functions (simple ones).
                'count', 'sizeof', 'array_push', 'array_pop', 'array_shift', 'array_unshift',
                'array_keys', 'array_values', 'array_reverse', 'array_unique', 'array_sum',
                'array_product', 'min', 'max', 'end', 'reset', 'key', 'current', 'next', 'prev',
                'array_fill', 'array_fill_keys', 'array_combine', 'array_flip', 'array_pad',
                'array_diff', 'array_diff_key', 'array_diff_assoc', 'array_intersect', 'array_intersect_key',
                'array_slice', 'array_chunk', 'array_column',
                
                // Array functions that commonly use callbacks (might benefit from named params but often don't).
                'array_filter', 'array_map', 'array_reduce', 'array_walk', 'array_walk_recursive', 'usort', 'uksort',
                'uasort', 'array_search', 'array_key_exists', 'in_array',
                
                // String manipulation that's usually obvious.
                'implode', 'explode', 'str_repeat', 'str_pad', 'wordwrap',
                'strpos', 'stripos', 'strrpos', 'strripos', 'strstr', 'stristr', 'strcasecmp',
                'str_replace', 'str_ireplace', 'substr', 'substr_replace',
                'str_split', 'chunk_split', 'str_shuffle', 'strrev',
                'str_starts_with', 'str_ends_with', 'str_contains', 'str_equals',
                
                // Regular expression functions (usually obvious from context).
                'preg_match', 'preg_match_all', 'preg_replace', 'preg_replace_callback',
                'preg_split', 'preg_filter', 'preg_grep', 'preg_quote',
                
                // Built-in functions that DON'T support named parameters (PHP built-ins).
                // These use variadic arguments or have special calling conventions.
                'sprintf', 'printf', 'fprintf', 'vprintf', 'vfprintf', 'vsprintf',
                'unset', 'isset', 'empty',
                'call_user_func', 'call_user_func_array',
                'array_merge', 'array_merge_recursive', 'in_array',
                
                // Serialization.
                'json_encode', 'json_decode', 'serialize', 'unserialize',
                
                // Hash functions.
                'hash_hmac', 'hash', 'md5', 'sha1', 'sha256', 'sha512',
                
                // Math functions.
                'abs', 'ceil', 'floor', 'round', 'sqrt', 'pow', 'log', 'sin', 'cos', 'tan',
                'rand', 'mt_rand', 'srand', 'mt_srand', 'range', 'number_format',
                // Encoding/decoding functions.
                'base64_encode', 'base64_decode',
                
                // File functions (simple ones).
                'file_exists', 'is_file', 'is_dir', 'is_readable', 'is_writable',
                'filesize', 'filemtime', 'filectime', 'fileatime', 'dirname', 'basename',
                'fopen', 'fclose', 'fread', 'fwrite', 'fgets', 'fgetcsv', 'fputcsv', 'feof',
            'chown', 'open', 'stream_copy_to_stream',
                'stream_context_create',
                
                // DateTime (simple constructors).
                'time', 'microtime', 'date', 'gmdate', 'mktime', 'gmmktime',
            'array_replace_recursive', 'version_compare',
                
                // URL and validation functions.
                'filter_var', 'parse_url', 'urlencode', 'urldecode', 'htmlspecialchars', 'htmlentities',
                'preg_match', 'preg_match_all', 'preg_replace', 'preg_replace_callback', 'preg_split',
                
                // PHP debug functions.
                'debug_backtrace', 'var_dump', 'print_r',
                // PHP reflection and type checking functions.
                'method_exists', 'class_exists', 'function_exists', 'property_exists', 'is_a', 'is_subclass_of',
                
                // PHP file/path functions.
                'pathinfo', 'dirname', 'basename', 'realpath',
                'file_put_contents', 'file_get_contents', 'readfile', 'filesize',
                'unlink', 'sys_get_temp_dir', 'tempnam',
                
                // PHP DateTime static methods.
                'createfromformat',
                
                // PHP string functions (additional).
                'addcslashes', 'strcmp', 'fnmatch', 'mb_strcut', 'iconv', 'str_starts_with', 'str_ends_with', 'str_contains',
                
                // PHP system functions.
                'exec', 'ini_set', 'random_int', 'apcu_store',
                
                // PHP URL/HTTP functions.
                'http_build_query',
                
                // cURL functions (already partially added, ensuring completeness).
                'curl_setopt', 'curl_setopt_array', 'curl_exec', 'curl_init', 'curl_close',
                'curl_getinfo', 'curl_error',
                
                // Exception classes - most don't benefit from named parameters for simple message/code/previous.
                'exception', 'ocpdbexception', 'doesnotexistexception', 'multipleobjectsreturnedexception',
                'runtimeexception', 'invalidargumentexception', 'logicexception', 'badmethodcallexception',
                'domainexception', 'rangeexception', 'outofboundsexception', 'overflowexception', 'underflowexception',
                
                // PHP reflection classes - named parameters don't add value.
                'reflectionmethod', 'reflectionclass', 'reflectionproperty', 'reflectionfunction', 'reflectionparameter',
                
                // Nextcloud framework registration methods - simple 2-parameter methods.
                'registereventlistener', 'registerservice', 'registerstrategy', 'dispatch', 'dispatchtyped',
                'addforeignkeyconstraint', 'addindex', 'addcolumn',
                
                // Nextcloud framework classes - simple constructors.
                'searchresultentry',
                
                // Simple query/check methods - typically obvious from context (very generic names only).
                'has', 'exists', 'includes', 'warmupsolrindex', 'testschemawaremapping', 'callollamawithtools', 'scheduleafter',
                'preparefilters', 'lockobject', 'search', 'indexfiles', 'reindexall', 'warmupindex', 'fixmismatchedfields',
                'createwriter', 'save', 'getexpectedschemafields', 'createconfigset', 'createcollection',
                'findnotindexedinsolr', 'findbystatus', 'postraw', 'deletefile', 'deletefield', 'callfireworkschatapiwithhistory',
                'findrecentbyconversation', 'applyfilefilters', 'applybasefilters', 'converttopaginatedformat',
                'parameter', 'functioninfo', 'findbysource', 'setdeleted',
                
                // HTTP client constructors - obvious parameters.
                'guzzleclient',
                
                // Query builder expression methods - obvious from context.
                'lt', 'lte', 'gt', 'gte', 'eq', 'neq', 'like', 'ilike', 'notlike', 'in', 'notin', 'isnotnull', 'isnull',
                
                // Translation and formatting functions - simple and obvious from context.
                't', 'date', 'strtotime'
            ];
            
            if (!in_array(strtolower($functionName), $skipFunctions)) {
                $warning = 'Consider using named parameters for function "%s" to improve code readability: %s(parameterName: $value)';
                $data = [$functionName, $functionName];
                $phpcsFile->addWarning($warning, $stackPtr, 'ShouldUseNamedParameters', $data);
            }
        }
    }
} 