<?php

declare(strict_types=1);

namespace App\Security;

use Hyperf\Contract\ConfigInterface;
use RuntimeException;

final class SecretCipher
{
    private string $key;

    public function __construct(ConfigInterface $config)
    {
        $this->key = hash('sha256', (string) $config->get('gateway.secret_encryption_key'), true);
    }

    public function encrypt(string $plaintext): string
    {
        $iv = random_bytes(12);
        $tag = '';
        $ciphertext = openssl_encrypt($plaintext, 'aes-256-gcm', $this->key, OPENSSL_RAW_DATA, $iv, $tag);
        if ($ciphertext === false) {
            throw new RuntimeException('认证密钥加密失败');
        }
        return base64_encode($iv . $tag . $ciphertext);
    }

    public function decrypt(string $encoded): string
    {
        $payload = base64_decode($encoded, true);
        if ($payload === false || strlen($payload) < 29) {
            throw new RuntimeException('认证密钥密文无效');
        }
        $plaintext = openssl_decrypt(substr($payload, 28), 'aes-256-gcm', $this->key, OPENSSL_RAW_DATA, substr($payload, 0, 12), substr($payload, 12, 16));
        if ($plaintext === false) {
            throw new RuntimeException('认证密钥解密失败');
        }
        return $plaintext;
    }
}
