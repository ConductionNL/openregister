<?php

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Exception;

use OCA\OpenRegister\Exception\FolderAccessDeniedException;
use OCP\Files\NotPermittedException;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for `FolderAccessDeniedException`.
 *
 * Asserts that this exception is a distinct class extending `\Exception`
 * directly, not a subclass of any Nextcloud exception. This guards against
 * generic `catch (NotPermittedException)` blocks accidentally absorbing a
 * folder-access denial and downgrading the response from a structured 403
 * to whatever the catching site does with `NotPermittedException`.
 */
class FolderAccessDeniedExceptionTest extends TestCase
{
    /**
     * Reflection-based parent-class assertion: must extend `\Exception` directly.
     */
    public function testExtendsExceptionDirectly(): void
    {
        $reflection = new \ReflectionClass(FolderAccessDeniedException::class);
        $parent     = $reflection->getParentClass();

        $this->assertNotFalse($parent, 'FolderAccessDeniedException must have a parent class');
        $this->assertSame(\Exception::class, $parent->getName());
    }//end testExtendsExceptionDirectly()

    /**
     * The exception must NOT be a subclass of any Nextcloud-specific exception.
     */
    public function testNotASubclassOfNotPermittedException(): void
    {
        $this->assertFalse(
            is_subclass_of(FolderAccessDeniedException::class, NotPermittedException::class),
            'FolderAccessDeniedException must not extend NotPermittedException — generic '.'catch-blocks for NotPermittedException would otherwise absorb the denial.'
        );
    }//end testNotASubclassOfNotPermittedException()

    /**
     * The attempted folder ID must be retrievable for response shaping.
     *
     * Exception::getCode() returns the constructor default (0) — the
     * exception is HTTP-agnostic. Controllers MUST use the
     * `FolderAccessDeniedException::HTTP_STATUS` constant for the HTTP
     * status mapping. Locking both invariants here so a future reader
     * cannot accidentally re-couple the two.
     *
     * @return void
     */
    public function testCarriesAttemptedFolderId(): void
    {
        $exception = new FolderAccessDeniedException(attemptedFolderId: '999');

        $this->assertSame(expected: '999', actual: $exception->getAttemptedFolderId());
        $this->assertSame(expected: 0, actual: $exception->getCode());
        $this->assertSame(expected: 403, actual: FolderAccessDeniedException::HTTP_STATUS);
        $this->assertStringContainsString(needle: '999', haystack: $exception->getMessage());
    }//end testCarriesAttemptedFolderId()
}//end class
