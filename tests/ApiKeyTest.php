<?php
namespace Tests;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../includes/ApiKey.php';

final class ApiKeyTest extends TestCase
{
    public function testGenerateFormat(): void
    {
        $k = \ApiKey::generate();
        $this->assertStringStartsWith('uvt_', $k['plain']);
        $this->assertSame(44, strlen($k['plain']));
        $this->assertSame(hash('sha256', $k['plain']), $k['hash']);
        $this->assertSame(substr($k['plain'], 4, 8), $k['prefix']);
    }

    public function testScopes(): void
    {
        $read  = ['scope' => 'read'];
        $write = ['scope' => 'write'];
        $this->assertTrue(\ApiKey::hasScope($read, 'read'));
        $this->assertFalse(\ApiKey::hasScope($read, 'write'));
        $this->assertTrue(\ApiKey::hasScope($write, 'read'));
        $this->assertTrue(\ApiKey::hasScope($write, 'write'));
    }

    public function testFromRequestBearer(): void
    {
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer uvt_testkey123';
        $this->assertSame('uvt_testkey123', \ApiKey::fromRequest());
        unset($_SERVER['HTTP_AUTHORIZATION']);
    }
}
