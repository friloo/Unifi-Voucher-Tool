<?php
namespace Tests;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../includes/Totp.php';

final class TotpTest extends TestCase
{
    /** RFC 6238 Testvektoren (SHA1, 6 Stellen, Seed "12345678901234567890"). */
    public function testRfc6238Vectors(): void
    {
        $secret = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ'; // Base32 des RFC-Seeds
        $this->assertSame('287082', \Totp::code($secret, intdiv(59, 30)));
        $this->assertSame('081804', \Totp::code($secret, intdiv(1111111109, 30)));
        $this->assertSame('005924', \Totp::code($secret, intdiv(1234567890, 30)));
    }

    public function testVerifyAcceptsCurrentCode(): void
    {
        $secret = \Totp::generateSecret();
        $code = \Totp::code($secret);
        $this->assertTrue(\Totp::verify($secret, $code));
    }

    public function testVerifyRejectsWrongCode(): void
    {
        $secret = \Totp::generateSecret();
        $wrong = \Totp::code($secret) === '000000' ? '111111' : '000000';
        $this->assertFalse(\Totp::verify($secret, $wrong));
    }

    public function testVerifyRejectsMalformed(): void
    {
        $secret = \Totp::generateSecret();
        $this->assertFalse(\Totp::verify($secret, 'abcdef'));
        $this->assertFalse(\Totp::verify($secret, '12345'));
    }

    public function testProvisioningUri(): void
    {
        $uri = \Totp::provisioningUri('ABC', 'user@example.com', 'My App');
        $this->assertStringStartsWith('otpauth://totp/', $uri);
        $this->assertStringContainsString('secret=ABC', $uri);
    }
}
