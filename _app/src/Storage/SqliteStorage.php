<?php
declare(strict_types=1);

namespace Eam\Storage;

use Eam\Support\Logger;
use RuntimeException;
use SQLite3;
use SQLite3Result;

final class SqliteStorage implements StorageInterface
{
    private SQLite3 $db;

    public function __construct(
        string $path,
        private readonly string $secret,
        private readonly Logger $logger
    ) {
        $dir = dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0770, true) && !is_dir($dir)) {
            throw new RuntimeException('Cannot create storage directory: ' . $dir);
        }
        $this->db = new SQLite3($path);
        $this->db->enableExceptions(true);
        $this->db->busyTimeout(5000);
        $this->db->exec('PRAGMA journal_mode = WAL');
        $this->db->exec('PRAGMA synchronous = NORMAL');
        $this->db->exec('CREATE TABLE IF NOT EXISTS tokens (
            kind TEXT NOT NULL,
            token_hash TEXT NOT NULL,
            payload TEXT NOT NULL,
            ip TEXT NULL,
            created_at INTEGER NOT NULL,
            expires_at INTEGER NOT NULL,
            used_at INTEGER NULL,
            PRIMARY KEY (kind, token_hash)
        )');
        $this->db->exec('CREATE INDEX IF NOT EXISTS idx_tokens_expires ON tokens(expires_at)');
        $this->db->exec('CREATE TABLE IF NOT EXISTS rate_limits (
            key TEXT PRIMARY KEY,
            count INTEGER NOT NULL,
            expires_at INTEGER NOT NULL
        )');
    }

    public function createToken(string $kind, array $payload, int $ttl, ?string $ip = null): string
    {
        $raw = bin2hex(random_bytes(32));
        $hash = $this->hashToken($raw);
        $now = time();
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($json)) {
            throw new RuntimeException('Cannot encode token payload.');
        }
        $stmt = $this->db->prepare('INSERT INTO tokens (kind, token_hash, payload, ip, created_at, expires_at, used_at)
            VALUES (:kind, :hash, :payload, :ip, :created, :expires, NULL)');
        $stmt->bindValue(':kind', $kind, SQLITE3_TEXT);
        $stmt->bindValue(':hash', $hash, SQLITE3_TEXT);
        $stmt->bindValue(':payload', $json, SQLITE3_TEXT);
        $stmt->bindValue(':ip', $ip, $ip === null ? SQLITE3_NULL : SQLITE3_TEXT);
        $stmt->bindValue(':created', $now, SQLITE3_INTEGER);
        $stmt->bindValue(':expires', $now + $ttl, SQLITE3_INTEGER);
        $stmt->execute();
        $stmt->close();
        return $raw;
    }

    public function peekToken(string $kind, string $token): ?array
    {
        $row = $this->fetchToken($kind, $token);
        if ($row === null) {
            return null;
        }
        $payload = json_decode($row['payload'], true);
        return is_array($payload) ? $payload : null;
    }

    public function consumeToken(string $kind, string $token): ?array
    {
        $this->db->exec('BEGIN IMMEDIATE');
        try {
            $row = $this->fetchToken($kind, $token);
            if ($row === null) {
                $this->db->exec('ROLLBACK');
                return null;
            }
            $stmt = $this->db->prepare('UPDATE tokens SET used_at = :used WHERE kind = :kind AND token_hash = :hash AND used_at IS NULL');
            $stmt->bindValue(':used', time(), SQLITE3_INTEGER);
            $stmt->bindValue(':kind', $kind, SQLITE3_TEXT);
            $stmt->bindValue(':hash', $this->hashToken($token), SQLITE3_TEXT);
            $stmt->execute();
            $changed = $this->db->changes();
            $stmt->close();
            if ($changed < 1) {
                $this->db->exec('ROLLBACK');
                return null;
            }
            $this->db->exec('COMMIT');
            $payload = json_decode($row['payload'], true);
            return is_array($payload) ? $payload : null;
        } catch (\Throwable $e) {
            $this->db->exec('ROLLBACK');
            throw $e;
        }
    }

    public function isRateLimited(string $key, int $limit): bool
    {
        $stmt = $this->db->prepare('SELECT count, expires_at FROM rate_limits WHERE key = :key');
        $stmt->bindValue(':key', $key, SQLITE3_TEXT);
        $result = $stmt->execute();
        $row = $result instanceof SQLite3Result ? $result->fetchArray(SQLITE3_ASSOC) : false;
        $stmt->close();
        if (!is_array($row)) {
            return false;
        }
        if ((int) $row['expires_at'] <= time()) {
            return false;
        }
        return (int) $row['count'] >= $limit;
    }

    public function hitRate(string $key, int $window): void
    {
        $now = time();
        $this->db->exec('BEGIN IMMEDIATE');
        try {
            $stmt = $this->db->prepare('SELECT count, expires_at FROM rate_limits WHERE key = :key');
            $stmt->bindValue(':key', $key, SQLITE3_TEXT);
            $result = $stmt->execute();
            $row = $result instanceof SQLite3Result ? $result->fetchArray(SQLITE3_ASSOC) : false;
            $stmt->close();
            if (is_array($row) && (int) $row['expires_at'] > $now) {
                $stmt = $this->db->prepare('UPDATE rate_limits SET count = count + 1 WHERE key = :key');
                $stmt->bindValue(':key', $key, SQLITE3_TEXT);
                $stmt->execute();
                $stmt->close();
            } else {
                $stmt = $this->db->prepare('INSERT INTO rate_limits (key, count, expires_at) VALUES (:key, 1, :expires)
                    ON CONFLICT(key) DO UPDATE SET count = 1, expires_at = excluded.expires_at');
                $stmt->bindValue(':key', $key, SQLITE3_TEXT);
                $stmt->bindValue(':expires', $now + $window, SQLITE3_INTEGER);
                $stmt->execute();
                $stmt->close();
            }
            $this->db->exec('COMMIT');
        } catch (\Throwable $e) {
            $this->db->exec('ROLLBACK');
            throw $e;
        }
    }

    public function clearRate(string $key): void
    {
        $stmt = $this->db->prepare('DELETE FROM rate_limits WHERE key = :key');
        $stmt->bindValue(':key', $key, SQLITE3_TEXT);
        $stmt->execute();
        $stmt->close();
    }

    public function cleanup(): void
    {
        $limit = time() - 86400;
        $stmt = $this->db->prepare('DELETE FROM tokens WHERE expires_at < :expires OR (used_at IS NOT NULL AND used_at < :used)');
        $stmt->bindValue(':expires', $limit, SQLITE3_INTEGER);
        $stmt->bindValue(':used', $limit, SQLITE3_INTEGER);
        $stmt->execute();
        $stmt->close();
        $stmt = $this->db->prepare('DELETE FROM rate_limits WHERE expires_at < :now');
        $stmt->bindValue(':now', time(), SQLITE3_INTEGER);
        $stmt->execute();
        $stmt->close();
    }

    private function fetchToken(string $kind, string $token): ?array
    {
        $stmt = $this->db->prepare('SELECT payload, expires_at, used_at FROM tokens WHERE kind = :kind AND token_hash = :hash');
        $stmt->bindValue(':kind', $kind, SQLITE3_TEXT);
        $stmt->bindValue(':hash', $this->hashToken($token), SQLITE3_TEXT);
        $result = $stmt->execute();
        $row = $result instanceof SQLite3Result ? $result->fetchArray(SQLITE3_ASSOC) : false;
        $stmt->close();
        if (!is_array($row)) {
            return null;
        }
        if ($row['used_at'] !== null || (int) $row['expires_at'] <= time()) {
            return null;
        }
        return $row;
    }

    private function hashToken(string $token): string
    {
        return hash_hmac('sha256', $token, $this->secret);
    }
}
