<?php

declare(strict_types=1);

namespace Briefklar\PiiShield\Vault;

use Briefklar\PiiShield\Exception\VaultException;
use Briefklar\PiiShield\Vault\Storage\VaultStorageInterface;

/**
 * Encrypts mappings with XChaCha20-Poly1305 (libsodium) before they reach the storage.
 *
 * The vault id is bound as associated data, so a ciphertext copied to another id fails to decrypt.
 * This is the only supported way to persist mappings (docs/adr/0005).
 */
final class EncryptedVault implements VaultInterface
{
    private const int NONCE_BYTES = \SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES;

    /**
     * @param string $key 32 raw bytes, see generateKey()
     */
    public function __construct(
        private readonly VaultStorageInterface $storage,
        #[\SensitiveParameter] private readonly string $key,
    ) {
        if (\SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES !== \strlen($key)) {
            throw new \InvalidArgumentException(\sprintf('The key must be %d bytes long.', \SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES));
        }
    }

    /**
     * A new random key (32 raw bytes). Store it base64-encoded as a secret, e.g. a Symfony secret.
     */
    public static function generateKey(): string
    {
        return sodium_crypto_aead_xchacha20poly1305_ietf_keygen();
    }

    public function store(string $id, array $mapping, int $ttl): void
    {
        if ($ttl <= 0) {
            throw new \InvalidArgumentException('The TTL must be positive.');
        }

        $nonce = random_bytes(self::NONCE_BYTES);
        $plaintext = json_encode($mapping, \JSON_THROW_ON_ERROR);
        $ciphertext = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt($plaintext, $id, $nonce, $this->key);
        sodium_memzero($plaintext);

        $this->storage->put($id, $nonce.$ciphertext, $ttl);
    }

    public function fetch(string $id): ?array
    {
        $payload = $this->storage->get($id);
        if (null === $payload) {
            return null;
        }

        $plaintext = \strlen($payload) > self::NONCE_BYTES
            ? sodium_crypto_aead_xchacha20poly1305_ietf_decrypt(substr($payload, self::NONCE_BYTES), $id, substr($payload, 0, self::NONCE_BYTES), $this->key)
            : false;

        if (false === $plaintext) {
            throw new VaultException('Vault entry cannot be decrypted (wrong key or tampered data).');
        }

        try {
            $mapping = json_decode($plaintext, true, 2, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new VaultException('Vault entry is corrupt.', previous: $e);
        } finally {
            sodium_memzero($plaintext);
        }

        if (!\is_array($mapping)) {
            throw new VaultException('Vault entry is corrupt.');
        }

        $result = [];
        foreach ($mapping as $placeholder => $original) {
            if (!\is_string($placeholder) || !\is_string($original)) {
                throw new VaultException('Vault entry is corrupt.');
            }
            $result[$placeholder] = $original;
        }

        return $result;
    }

    public function delete(string $id): void
    {
        $this->storage->remove($id);
    }
}
