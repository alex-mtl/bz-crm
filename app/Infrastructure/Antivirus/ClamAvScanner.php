<?php

declare(strict_types=1);

namespace App\Infrastructure\Antivirus;

use App\Domain\Files\AttachmentScanner;
use App\Domain\Files\ScanVerdict;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * ClamAV over the clamd socket (ADR-012): the file is streamed with the INSTREAM command, so the daemon needs no
 * access to the application's disk. Any failure to talk to the daemon is "unavailable", never "clean".
 */
final readonly class ClamAvScanner implements AttachmentScanner
{
    private const int CHUNK = 8192;

    public function __construct(
        private string $host,
        private int $port,
        private float $timeout = 10.0,
    ) {}

    public function scan(string $absolutePath): ScanVerdict
    {
        try {
            $file = fopen($absolutePath, 'rb');
            $socket = @stream_socket_client("tcp://{$this->host}:{$this->port}", $errno, $error, $this->timeout);
            if ($file === false || $socket === false) {
                Log::warning('ClamAV is not reachable', ['host' => $this->host, 'port' => $this->port, 'error' => $error ?? null]);

                return ScanVerdict::Unavailable;
            }
            stream_set_timeout($socket, (int) ceil($this->timeout * 6));

            fwrite($socket, "zINSTREAM\0");
            while (! feof($file)) {
                $chunk = fread($file, self::CHUNK);
                if ($chunk === false || $chunk === '') {
                    break;
                }
                fwrite($socket, pack('N', strlen($chunk)).$chunk);
            }
            fwrite($socket, pack('N', 0));
            $answer = trim((string) stream_get_contents($socket), "\0\n\r ");
            fclose($socket);
            fclose($file);
        } catch (Throwable $e) {
            Log::warning('ClamAV scan failed', ['error' => $e->getMessage()]);

            return ScanVerdict::Unavailable;
        }

        return match (true) {
            str_ends_with($answer, 'OK') => ScanVerdict::Clean,
            str_ends_with($answer, 'FOUND') => ScanVerdict::Infected,
            default => ScanVerdict::Unavailable,
        };
    }
}
