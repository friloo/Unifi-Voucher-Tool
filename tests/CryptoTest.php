<?php
namespace Tests;

use PHPUnit\Framework\TestCase;

if (!defined('APP_KEY')) {
    define('APP_KEY', base64_encode(random_bytes(32)));
}
require_once __DIR__ . '/../includes/Crypto.php';

final class CryptoTest extends TestCase
{
    public function testRoundtrip(): void
    {
        $plain = 'geheim;mit"sonder@zeichen';
        $cipher = \Crypto::encrypt($plain);
        $this->assertNotSame($plain, $cipher);
        $this->assertTrue(\Crypto::isEncrypted($cipher));
        $this->assertSame($plain, \Crypto::decrypt($cipher));
    }

    public function testPlaintextPassthrough(): void
    {
        // Legacy-/Klartextwerte werden unverändert zurückgegeben.
        $this->assertSame('altesKlartextPW', \Crypto::decrypt('altesKlartextPW'));
    }

    public function testEmptyValues(): void
    {
        $this->assertSame('', \Crypto::encrypt(''));
        $this->assertNull(\Crypto::decrypt(null));
    }

    public function testGenerateKeyLength(): void
    {
        $key = \Crypto::generateKey();
        $this->assertSame(32, strlen(base64_decode($key)));
    }
}
