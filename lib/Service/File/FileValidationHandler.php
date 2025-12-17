<?php

/**
 * FileValidationHandler
 *
 * This file is part of the OpenRegister app for Nextcloud.
 *
 * @category Service
 * @package  OCA\OpenRegister
 * @author   Conduction <info@conduction.nl>
 * @license  AGPL-3.0-or-later https://www.gnu.org/licenses/agpl-3.0.html
 * @link     https://github.com/ConductionNL/openregister
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\File;

use Exception;
use OCA\OpenRegister\Db\FileMapper;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\Node;
use OCP\Files\NotFoundException;
use OCP\Files\NotPermittedException;
use OCP\IUser;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Handles file validation and security operations.
 *
 * This handler is responsible for:
 * - Blocking executable files for security
 * - Detecting executable magic bytes in file content
 * - Checking and fixing file ownership issues
 * - Validating file access permissions
 *
 * @category Service
 * @package  OCA\OpenRegister
 * @author   Conduction <info@conduction.nl>
 * @license  AGPL-3.0-or-later https://www.gnu.org/licenses/agpl-3.0.html
 * @link     https://github.com/ConductionNL/openregister
 * @version  1.0.0
 */
class FileValidationHandler
{
    /**
     * Constructor for FileValidationHandler.
     *
     * @param FileMapper      $fileMapper  File mapper for ownership operations.
     * @param IUserSession    $userSession User session for user context.
     * @param LoggerInterface $logger      Logger for logging operations.
     */
    public function __construct(
        private readonly FileMapper $fileMapper,
        private readonly IUserSession $userSession,
        private readonly LoggerInterface $logger
    ) {

    }//end __construct()

    /**
     * Block executable files from being uploaded for security reasons.
     *
     * This method checks both the file extension and the file content (magic bytes)
     * to detect executable files. If an executable file is detected, an exception
     * is thrown to prevent the upload.
     *
     * @param string $fileName    The name of the file to check.
     * @param string $fileContent The content of the file to check.
     *
     * @return void
     *
     * @throws Exception If an executable file is detected.
     *
     * @psalm-return   void
     * @phpstan-return void
     */
    public function blockExecutableFile(string $fileName, string $fileContent): void
    {
        // List of dangerous executable extensions.
        $dangerousExtensions = [
            // Windows executables.
            'exe',
            'bat',
            'cmd',
            'com',
            'msi',
            'scr',
            'vbs',
            'vbe',
            'js',
            'jse',
            'wsf',
            'wsh',
            'ps1',
            'dll',
            // Unix/Linux executables.
            'sh',
            'bash',
            'csh',
            'ksh',
            'zsh',
            'run',
            'bin',
            'app',
            'deb',
            'rpm',
            // Scripts and code.
            'php',
            'phtml',
            'php3',
            'php4',
            'php5',
            'phps',
            'phar',
            'py',
            'pyc',
            'pyo',
            'pyw',
            'pl',
            'pm',
            'cgi',
            'rb',
            'rbw',
            'jar',
            'war',
            'ear',
            'class',
            // Containers and packages.
            'appimage',
            'snap',
            'flatpak',
            // MacOS.
            'dmg',
            'pkg',
            'command',
            // Android.
            'apk',
            // Other dangerous.
            'elf',
            'out',
            'o',
            'so',
            'dylib',
        ];

        // Check file extension.
        $extension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
        if (in_array($extension, $dangerousExtensions, true) === true) {
            $this->logger->warning(
                    message: 'Executable file upload blocked',
                    context: [
                        'app'       => 'openregister',
                        'filename'  => $fileName,
                        'extension' => $extension,
                    ]
                    );

            throw new Exception(
                "File '$fileName' is an executable file (.$extension). "."Executable files are blocked for security reasons. "."Allowed formats: documents, images, archives, data files."
            );
        }

        // Check magic bytes (file signatures) in content.
        if (empty($fileContent) === false) {
            $this->detectExecutableMagicBytes(content: $fileContent, fileName: $fileName);
        }

    }//end blockExecutableFile()

    /**
     * Detects executable magic bytes in file content.
     *
     * Magic bytes are signatures at the start of files that identify the file type.
     * This provides defense-in-depth against renamed executables.
     *
     * @param string $content  The file content to check.
     * @param string $fileName The filename for error messages.
     *
     * @return void
     *
     * @throws Exception If executable magic bytes are detected.
     *
     * @psalm-return   void
     * @phpstan-return void
     */
    public function detectExecutableMagicBytes(string $content, string $fileName): void
    {
        // Common executable magic bytes.
        $magicBytes = [
            'MZ'               => 'Windows executable (PE/EXE)',
            "\x7FELF"          => 'Linux/Unix executable (ELF)',
            "#!/bin/sh"        => 'Shell script',
            "#!/bin/bash"      => 'Bash script',
            "#!/usr/bin/env"   => 'Script with env shebang',
            "<?php"            => 'PHP script',
            "\xCA\xFE\xBA\xBE" => 'Java class file',
        ];

        foreach ($magicBytes as $signature => $description) {
            if (strpos($content, $signature) === 0) {
                $this->logger->warning(
                        message: 'Executable magic bytes detected',
                        context: [
                            'app'      => 'openregister',
                            'filename' => $fileName,
                            'type'     => $description,
                        ]
                        );

                throw new Exception(
                    "File '$fileName' contains executable code ($description). "."Executable files are blocked for security reasons."
                );
            }
        }

        // Check for script shebangs anywhere in first 4 lines.
        $firstLines = substr($content, 0, 1024);
        if (preg_match('/^#!.*\/(sh|bash|zsh|ksh|csh|python|perl|ruby|php|node)/m', $firstLines) === 1) {
            throw new Exception(
                "File '$fileName' contains script shebang. "."Script files are blocked for security reasons."
            );
        }

        // Check for embedded PHP tags.
        if (preg_match('/<\?php|<\?=|<script\s+language\s*=\s*["\']php/i', $firstLines) === 1) {
            throw new Exception(
                "File '$fileName' contains PHP code. "."PHP files are blocked for security reasons."
            );
        }

    }//end detectExecutableMagicBytes()

    /**
     * Check file ownership and fix it if needed to prevent "File not found" errors.
     *
     * This method attempts to access the file to check if there are any ownership
     * or permission issues. If the file exists but cannot be accessed, it attempts
     * to fix the ownership by setting it to the OpenRegister user.
     *
     * @param Node $file The file node to check ownership for.
     *
     * @return void
     *
     * @throws Exception If ownership check/fix fails.
     *
     * @TODO This is a hack to fix NextCloud file ownership issues on production
     * @TODO where files exist but can't be accessed due to permission problems.
     * @TODO This should be removed once the underlying NextCloud rights issue is resolved.
     *
     * @psalm-return   void
     * @phpstan-return void
     */
    public function checkOwnership(Node $file): void
    {
        try {
            // Try to read the file to trigger any potential access issues.
            if ($file instanceof File) {
                $file->getContent();
            } else if ($file instanceof Folder) {
                // For folders, try to list contents.
                $file->getDirectoryListing();
            }

            // If we get here, the file is accessible.
            $this->logger->debug(
                message: "checkOwnership: File {$file->getName()} (ID: {$file->getId()}) is accessible, no ownership fix needed"
            );
        } catch (NotFoundException $e) {
            // File exists but we can't access it - likely an ownership issue.
            $this->logger->warning(
                message: "checkOwnership: File {$file->getName()} (ID: {$file->getId()}) exists but not accessible, checking ownership"
            );

            try {
                $fileOwner        = $file->getOwner();
                $openRegisterUser = $this->getUser();

                if ($fileOwner === null || $fileOwner->getUID() !== $openRegisterUser->getUID()) {
                    $this->logger->info(
                        message: "checkOwnership: File {$file->getName()} (ID: {$file->getId()}) has incorrect owner, attempting to fix"
                    );

                    // Try to fix the ownership.
                    $ownershipFixed = $this->ownFile($file);

                    if ($ownershipFixed === true) {
                        $this->logger->info(
                            message: "checkOwnership: Successfully fixed ownership for file {$file->getName()} (ID: {$file->getId()})"
                        );
                    } else {
                        $this->logger->error(
                            message: "checkOwnership: Failed to fix ownership for file {$file->getName()} (ID: {$file->getId()})"
                        );
                        throw new Exception("Failed to fix file ownership for file: ".$file->getName());
                    }
                } else {
                    $this->logger->info(
                        message: "checkOwnership: File {$file->getName()} (ID: {$file->getId()}) already has correct owner, but still not accessible"
                    );
                }//end if
            } catch (Exception $ownershipException) {
                $this->logger->error(
                    message: "checkOwnership: Error checking/fixing ownership for file {$file->getName()}: ".$ownershipException->getMessage()
                );
                throw new Exception("Ownership check failed for file: ".$file->getName());
            }//end try
        } catch (NotPermittedException $e) {
            // Permission denied - likely an ownership issue.
            $this->logger->warning(
                message: "checkOwnership: Permission denied for file {$file->getName()} (ID: {$file->getId()}), attempting ownership fix"
            );

            try {
                // Try to fix the ownership.
                $this->ownFile($file);
                $this->logger->info(
                    message: "checkOwnership: Fixed ownership for file {$file->getName()} (ID: {$file->getId()}) after permission error"
                );
            } catch (Exception $ownershipException) {
                $this->logger->error(
                    message: "checkOwnership: Failed to fix ownership after permission error for file {$file->getName()}: ".$ownershipException->getMessage()
                );
                throw new Exception("Ownership fix failed after permission error for file: ".$file->getName());
            }
        }//end try

    }//end checkOwnership()

    /**
     * Set file ownership to the OpenRegister user.
     *
     * This method updates the file ownership in the database to the OpenRegister
     * user to fix access permission issues.
     *
     * @param Node $file The file node to set ownership for.
     *
     * @return bool True if ownership was set successfully, false otherwise.
     *
     * @throws Exception If ownership update fails.
     *
     * @psalm-return   bool
     * @phpstan-return bool
     */
    public function ownFile(Node $file): bool
    {
        try {
            $openRegisterUser = $this->getUser();
            $userId           = $openRegisterUser->getUID();
            $fileId           = $file->getId();

            $this->logger->info(
                message: "ownFile: Attempting to set ownership of file {$file->getName()} (ID: $fileId) to user: $userId"
            );

            $result = $this->fileMapper->setFileOwnership(fileId: $fileId, userId: $userId);

            if ($result === true) {
                $this->logger->info(
                    message: "ownFile: Successfully set ownership of file {$file->getName()} (ID: $fileId) to user: $userId"
                );
            } else {
                $this->logger->warning(
                    message: "ownFile: Failed to set ownership of file {$file->getName()} (ID: $fileId) to user: $userId"
                );
            }

            return $result;
        } catch (Exception $e) {
            $this->logger->error(
                message: "ownFile: Error setting ownership of file {$file->getName()}: ".$e->getMessage()
            );
            throw new Exception("Failed to set file ownership: ".$e->getMessage());
        }//end try

    }//end ownFile()

    /**
     * Get the OpenRegister user from the session.
     *
     * This method retrieves the current user from the session context.
     * The OpenRegister user is used for file ownership operations.
     *
     * @return IUser The OpenRegister user.
     *
     * @throws Exception If user is not logged in.
     *
     * @psalm-return   IUser
     * @phpstan-return IUser
     */
    private function getUser(): IUser
    {
        $user = $this->userSession->getUser();

        if ($user === null) {
            throw new Exception('User not logged in');
        }

        return $user;

    }//end getUser()
}//end class
