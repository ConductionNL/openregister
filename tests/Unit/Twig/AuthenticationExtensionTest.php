<?php

namespace Unit\Twig;

use OCA\OpenRegister\Twig\AuthenticationExtension;
use OCA\OpenRegister\Twig\AuthenticationRuntime;
use PHPUnit\Framework\TestCase;
use Twig\TwigFunction;

class AuthenticationExtensionTest extends TestCase
{
    private AuthenticationExtension $extension;

    protected function setUp(): void
    {
        $this->extension = new AuthenticationExtension();
    }

    public function testGetFunctionsReturnsArray(): void
    {
        $functions = $this->extension->getFunctions();
        $this->assertIsArray($functions);
        $this->assertCount(3, $functions);
    }

    public function testGetFunctionsAreTwigFunctions(): void
    {
        foreach ($this->extension->getFunctions() as $fn) {
            $this->assertInstanceOf(TwigFunction::class, $fn);
        }
    }

    public function testGetFunctionsContainsOauthToken(): void
    {
        $names = array_map(fn(TwigFunction $f) => $f->getName(), $this->extension->getFunctions());
        $this->assertContains('oauthToken', $names);
    }

    public function testGetFunctionsContainsDecosToken(): void
    {
        $names = array_map(fn(TwigFunction $f) => $f->getName(), $this->extension->getFunctions());
        $this->assertContains('decosToken', $names);
    }

    public function testGetFunctionsContainsJwtToken(): void
    {
        $names = array_map(fn(TwigFunction $f) => $f->getName(), $this->extension->getFunctions());
        $this->assertContains('jwtToken', $names);
    }

    public function testFunctionsPointToAuthenticationRuntime(): void
    {
        $functions = $this->extension->getFunctions();
        foreach ($functions as $fn) {
            $callable = $fn->getCallable();
            $this->assertIsArray($callable);
            $this->assertSame(AuthenticationRuntime::class, $callable[0]);
        }
    }

    public function testOauthTokenCallable(): void
    {
        $functions = $this->extension->getFunctions();
        $oauthFn = $functions[0];
        $callable = $oauthFn->getCallable();
        $this->assertSame('oauthToken', $callable[1]);
    }

    public function testDecosTokenCallable(): void
    {
        $functions = $this->extension->getFunctions();
        $decosFn = $functions[1];
        $callable = $decosFn->getCallable();
        $this->assertSame('decosToken', $callable[1]);
    }

    public function testJwtTokenCallable(): void
    {
        $functions = $this->extension->getFunctions();
        $jwtFn = $functions[2];
        $callable = $jwtFn->getCallable();
        $this->assertSame('jwtToken', $callable[1]);
    }
}
