<?php

namespace Wallabag\Wallabin\Tests;

use PHPUnit\Framework\TestCase;
use Wallabag\Wallabin\Signature;

final class SignatureTest extends TestCase
{
    private string $file;
    private string $secretKey;
    private string $publicKey;
    private string $keyId;

    protected function setUp(): void
    {
        $pair = sodium_crypto_sign_keypair();
        $this->secretKey = sodium_crypto_sign_secretkey($pair);
        $this->keyId = random_bytes(8);
        $this->publicKey = base64_encode('Ed' . $this->keyId . sodium_crypto_sign_publickey($pair));
        $this->file = tempnam(sys_get_temp_dir(), 'wallabin');
        file_put_contents($this->file, random_bytes(3000000));
    }

    protected function tearDown(): void
    {
        unlink($this->file);
    }

    public function testValidSignatures(): void
    {
        Signature::verify($this->file, $this->sign('ED'), $this->publicKey);
        Signature::verify($this->file, $this->sign('Ed'), $this->publicKey);

        $this->addToAssertionCount(2);
    }

    public function testTamperedFile(): void
    {
        $signature = $this->sign('ED');
        file_put_contents($this->file, 'tampered', \FILE_APPEND);

        $this->expectExceptionMessage('may have been tampered with');

        Signature::verify($this->file, $signature, $this->publicKey);
    }

    public function testTamperedTrustedComment(): void
    {
        $signature = str_replace('trusted comment: wallabag 2.7.0', 'trusted comment: wallabag 2.7.9', $this->sign('ED'));

        $this->expectExceptionMessage('may have been tampered with');

        Signature::verify($this->file, $signature, $this->publicKey);
    }

    public function testAnotherKey(): void
    {
        $other = base64_encode('Ed' . random_bytes(8) . sodium_crypto_sign_publickey(sodium_crypto_sign_keypair()));

        $this->expectExceptionMessage('is not signed with the wallabag key');

        Signature::verify($this->file, $this->sign('ED'), $other);
    }

    public function testInvalidSignature(): void
    {
        $this->expectExceptionMessage('Invalid signature');

        Signature::verify($this->file, "untrusted comment: nothing\nnot base64", $this->publicKey);
    }

    public function testTheDefaultKeyIsValid(): void
    {
        $this->assertSame(42, \strlen((string) base64_decode(Signature::PUBLIC_KEY, true)));
    }

    private function sign(string $algorithm): string
    {
        $message = 'ED' === $algorithm ? sodium_crypto_generichash((string) file_get_contents($this->file), '', 64) : (string) file_get_contents($this->file);
        $signature = sodium_crypto_sign_detached($message, $this->secretKey);
        $global = sodium_crypto_sign_detached($signature . 'wallabag 2.7.0', $this->secretKey);

        return sprintf("untrusted comment: signature\n%s\ntrusted comment: wallabag 2.7.0\n%s\n", base64_encode($algorithm . $this->keyId . $signature), base64_encode($global));
    }
}
