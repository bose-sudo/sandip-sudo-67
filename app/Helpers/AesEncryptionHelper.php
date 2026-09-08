<?php

namespace App\Helpers;

class AesEncryptionHelper
{
    private $cipher = 'AES-256-CBC';
    private $key;
    private $ivLength;

    public function __construct()
    {
        $this->key = hash('sha256', env('AES_SECRET_KEY'), true); // 32-byte key
        $this->ivLength = openssl_cipher_iv_length($this->cipher);
    }

    public function encrypt($plainText)
    {
        $iv = openssl_random_pseudo_bytes($this->ivLength);
        $encrypted = openssl_encrypt($plainText, $this->cipher, $this->key, OPENSSL_RAW_DATA, $iv);
        return base64_encode($iv . $encrypted); // combine IV + encrypted text
    }

    public function decrypt($encryptedText)
    {
        $cipherText = base64_decode($encryptedText);
        $iv = substr($cipherText, 0, $this->ivLength);
        $cipherRaw = substr($cipherText, $this->ivLength);
        return openssl_decrypt($cipherRaw, $this->cipher, $this->key, OPENSSL_RAW_DATA, $iv);
    }
}
