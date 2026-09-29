<?php

declare(strict_types=1);

namespace OCI\Admin\Service\BackupDestination;

use OCI\Admin\Service\ConfigSecretCipher;
use phpseclib3\Crypt\PublicKeyLoader;
use phpseclib3\Net\SFTP;
use Psr\Log\LoggerInterface;

/**
 * Ships archives to an SFTP host — a Hetzner Storage Box, typically, which
 * listens on port 23 rather than 22.
 *
 * phpseclib rather than shelling out to sftp/rsync, for three reasons that all
 * bite in production. The image carries no openssh-client. A shelled-out
 * transfer needs the private key materialised to a 0600 temp file on every run,
 * and reliably removed on every failure path. And host, path and key all arrive
 * from a web form, so building a shell command out of them is an injection
 * surface — here they are PHP method arguments and there is no shell involved.
 *
 * Settings live in oci_configuration; the password and private key are
 * encrypted at rest by ConfigSecretCipher.
 */
final class SftpDestination implements DestinationInterface
{
    private const TIMEOUT = 30;

    /**
     * @param array<string, string> $config host, port, username, password,
     *                                      private_key, passphrase, path
     */
    public function __construct(
        private readonly array $config,
        private readonly ConfigSecretCipher $cipher,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function name(): string
    {
        return 'sftp';
    }

    public function isConfigured(): bool
    {
        return ($this->config['enabled'] ?? '0') === '1'
            && ($this->config['host'] ?? '') !== ''
            && ($this->config['username'] ?? '') !== '';
    }

    public function put(string $localPath, string $remoteName): array
    {
        try {
            $sftp = $this->connect();
            $dir = $this->remoteDir();

            if ($dir !== '' && !$sftp->is_dir($dir) && !$sftp->mkdir($dir, -1, true)) {
                return $this->failure('Could not create the remote directory: ' . $dir);
            }

            $remote = ($dir === '' ? '' : rtrim($dir, '/') . '/') . $remoteName;

            if (!$sftp->put($remote, $localPath, SFTP::SOURCE_LOCAL_FILE)) {
                return $this->failure('Upload rejected: ' . $this->lastError($sftp));
            }

            // Trust the remote size, not the return value. A quota cut-off can
            // report success and leave a truncated file, which is precisely the
            // kind of backup that looks fine until the day it matters.
            $remoteSize = $sftp->filesize($remote);
            $localSize = (int) filesize($localPath);

            if ($remoteSize !== $localSize) {
                $sftp->delete($remote);

                return $this->failure(sprintf(
                    'Uploaded %d bytes but the remote file is %s — truncated, most likely a quota. Removed it.',
                    $localSize,
                    $remoteSize === false ? 'unreadable' : (string) $remoteSize,
                ));
            }

            return ['ok' => true, 'remote_path' => $remote, 'bytes' => $localSize, 'error' => null];
        } catch (\Throwable $e) {
            return $this->failure($e->getMessage());
        }
    }

    public function test(): array
    {
        try {
            $sftp = $this->connect();
            $dir = $this->remoteDir();

            if ($dir !== '' && !$sftp->is_dir($dir) && !$sftp->mkdir($dir, -1, true)) {
                return ['ok' => false, 'message' => 'Connected, but could not create ' . $dir];
            }

            // Write and delete a probe. Login alone proves nothing about
            // whether a backup can actually land — quota and permission
            // failures only show up on write.
            $probe = ($dir === '' ? '' : rtrim($dir, '/') . '/') . '.conzent-write-probe';
            if (!$sftp->put($probe, 'probe')) {
                return ['ok' => false, 'message' => 'Connected, but the directory is not writable.'];
            }
            $sftp->delete($probe);

            return ['ok' => true, 'message' => 'Connected and wrote a test file to ' . ($dir === '' ? '/' : $dir)];
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Throws rather than returning [] when the listing cannot be read.
     *
     * An empty array has to mean "this destination holds no archives". If it
     * also meant "the host was unreachable", the page would report a backup as
     * gone from storage that is merely offline — and telling somebody their
     * only offsite copy has vanished when it has not is the worst answer this
     * feature could give.
     */
    public function list(): array
    {
        $sftp = $this->connect();
        $dir = $this->remoteDir();
        $entries = $sftp->rawlist($dir === '' ? '.' : $dir);

        if ($entries === false) {
            throw new \RuntimeException('Could not list ' . ($dir === '' ? '.' : $dir) . ': ' . $this->lastError($sftp));
        }

        $out = [];
        foreach ($entries as $name => $meta) {
            $name = (string) $name;
            if (!str_starts_with($name, 'conzent-') || !str_ends_with($name, '.tar.gz')) {
                continue;
            }

            $out[] = [
                'name' => $name,
                'bytes' => isset($meta['size']) ? (int) $meta['size'] : null,
                'modified' => isset($meta['mtime']) ? gmdate('c', (int) $meta['mtime']) : null,
            ];
        }

        // Filenames carry a sortable timestamp, so this is chronological.
        usort($out, static fn (array $a, array $b): int => strcmp((string) $b['name'], (string) $a['name']));

        return $out;
    }

    public function fetch(string $remoteName, string $localPath): array
    {
        try {
            $sftp = $this->connect();
            $dir = $this->remoteDir();
            $remote = ($dir === '' ? '' : rtrim($dir, '/') . '/') . basename($remoteName);

            // phpseclib's get() opens the LOCAL file before it ever talks to
            // the remote, and returns false with no SFTP error if that fails.
            // Diagnosing that from "Download rejected: no detail returned" is
            // impossible, so establish which side is at fault ourselves.
            $targetDir = \dirname($localPath);

            if (!is_dir($targetDir) || !is_writable($targetDir)) {
                return ['ok' => false, 'bytes' => null, 'error' => sprintf(
                    'Cannot write to %s — the archive is on the server but there is nowhere to put it. '
                    . 'Check that the backup directory exists and is owned by the web user.',
                    $targetDir,
                )];
            }

            $remoteSize = $sftp->filesize($remote);

            if ($remoteSize === false) {
                return ['ok' => false, 'bytes' => null, 'error' => sprintf(
                    'Not on the server at %s: %s',
                    $remote,
                    $this->lastError($sftp),
                )];
            }

            // Straight to disk — an archive is far too big to hold in memory.
            if (!$sftp->get($remote, $localPath)) {
                // get() may have written part of the file before giving up.
                @unlink($localPath);

                return ['ok' => false, 'bytes' => null, 'error' => 'Download rejected: ' . $this->lastError($sftp)];
            }

            $local = (int) @filesize($localPath);

            // A truncated download restores a corrupt database, so refuse it
            // here rather than discovering it mid-import.
            if ($remoteSize !== $local) {
                @unlink($localPath);

                return ['ok' => false, 'bytes' => null, 'error' => sprintf(
                    'Download is %d bytes but the remote file is %d — incomplete, discarded.',
                    $local,
                    $remoteSize,
                )];
            }

            return ['ok' => true, 'bytes' => $local, 'error' => null];
        } catch (\Throwable $e) {
            return ['ok' => false, 'bytes' => null, 'error' => $e->getMessage()];
        }
    }

    public function prune(int $keep): int
    {
        try {
            $sftp = $this->connect();
            $dir = $this->remoteDir();
            $list = $sftp->nlist($dir === '' ? '.' : $dir) ?: [];

            $archives = [];
            foreach ($list as $entry) {
                if (str_starts_with((string) $entry, 'conzent-') && str_ends_with((string) $entry, '.tar.gz')) {
                    $archives[] = (string) $entry;
                }
            }

            if (\count($archives) <= $keep) {
                return 0;
            }

            // Filenames carry a sortable timestamp, so lexical order is
            // chronological — no extra stat call per file.
            sort($archives);
            $removed = 0;

            foreach (\array_slice($archives, 0, \count($archives) - $keep) as $old) {
                $path = ($dir === '' ? '' : rtrim($dir, '/') . '/') . $old;
                if ($sftp->delete($path)) {
                    ++$removed;
                }
            }

            return $removed;
        } catch (\Throwable $e) {
            $this->logger->warning('SFTP prune failed: ' . $e->getMessage());

            return 0;
        }
    }

    private function connect(): SFTP
    {
        $host = (string) ($this->config['host'] ?? '');
        $port = (int) ($this->config['port'] ?? 22);
        $user = (string) ($this->config['username'] ?? '');

        $sftp = new SFTP($host, $port > 0 ? $port : 22, self::TIMEOUT);

        $key = $this->secret('private_key');
        $password = $this->secret('password');

        if ($key !== '') {
            $passphrase = $this->secret('passphrase');
            $loaded = PublicKeyLoader::load($key, $passphrase === '' ? false : $passphrase);
            if (!$sftp->login($user, $loaded)) {
                throw new \RuntimeException('Key authentication was rejected by ' . $host . '.');
            }

            return $sftp;
        }

        if ($password === '') {
            throw new \RuntimeException('No password or private key is stored for this destination.');
        }

        if (!$sftp->login($user, $password)) {
            throw new \RuntimeException('Password authentication was rejected by ' . $host . '.');
        }

        return $sftp;
    }

    /**
     * Decrypting to null means this host holds a different APP_SECRET than the
     * one that stored the value — the normal state after restoring onto a new
     * server. Say that, rather than "authentication failed".
     */
    private function secret(string $key): string
    {
        $stored = (string) ($this->config[$key] ?? '');
        if ($stored === '') {
            return '';
        }

        $plain = $this->cipher->decrypt($stored);

        if ($plain === null) {
            throw new \RuntimeException(
                'The stored ' . str_replace('_', ' ', $key) . ' cannot be decrypted on this host. '
                . 'That is expected after restoring onto a new server, because the encryption key comes from '
                . 'APP_SECRET and archives do not carry it. Re-enter the credential to fix it.',
            );
        }

        return $plain;
    }

    private function remoteDir(): string
    {
        return trim((string) ($this->config['path'] ?? ''), '/');
    }

    private function lastError(SFTP $sftp): string
    {
        $errors = $sftp->getSFTPErrors();

        return $errors === [] ? 'no detail returned' : (string) end($errors);
    }

    /** @return array{ok: bool, remote_path: null, bytes: null, error: string} */
    private function failure(string $message): array
    {
        return ['ok' => false, 'remote_path' => null, 'bytes' => null, 'error' => $message];
    }
}
