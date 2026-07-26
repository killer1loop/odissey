<?php

namespace App\Services\Media;

use App\Services\Media\Exceptions\TranscodeQuotaExceeded;

final class TranscodeReservation
{
    /** @var resource|null */
    private mixed $handle;

    private int $remainingBytes;

    /**
     * @param  resource  $handle
     */
    public function __construct(
        private readonly string $path,
        mixed $handle,
        private readonly int $capacityBytes,
    ) {
        $this->handle = $handle;
        $this->remainingBytes = $capacityBytes;
    }

    public function capacityBytes(): int
    {
        return $this->capacityBytes;
    }

    public function remainingBytes(): int
    {
        return $this->remainingBytes;
    }

    public function consume(int $bytes): void
    {
        $bytes = max(0, $bytes);
        if ($bytes > $this->remainingBytes || ! is_resource($this->handle)) {
            throw new TranscodeQuotaExceeded;
        }

        $this->remainingBytes -= $bytes;
        if (! ftruncate($this->handle, $this->remainingBytes)) {
            throw new TranscodeQuotaExceeded;
        }

        clearstatcache(true, $this->path);
    }

    public function release(): void
    {
        if (is_resource($this->handle)) {
            flock($this->handle, LOCK_UN);
            fclose($this->handle);
        }

        $this->handle = null;
        @unlink($this->path);
        clearstatcache(true, $this->path);
    }

    public function __destruct()
    {
        $this->release();
    }
}
