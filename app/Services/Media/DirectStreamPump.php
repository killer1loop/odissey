<?php

namespace App\Services\Media;

final class DirectStreamPump
{
    private const MAX_SELECT_WAIT_NANOSECONDS = 250_000_000;

    public function pump(
        mixed $body,
        DirectStreamLease $lease,
        int $maximumBytes,
    ): void {
        $maximumBytes = max(0, $maximumBytes);
        if (
            $maximumBytes === 0
            || $lease->expired()
            || connection_aborted()
        ) {
            return;
        }

        if (is_resource($body)) {
            $this->pumpHandle($body, $lease, $maximumBytes);

            return;
        }

        if (
            is_object($body)
            && method_exists($body, 'detach')
        ) {
            $handle = $body->detach();

            if (! is_resource($handle)) {
                return;
            }

            try {
                $this->pumpHandle($handle, $lease, $maximumBytes);
            } finally {
                fclose($handle);
            }

            return;
        }

        if (
            ! is_object($body)
            || ! method_exists($body, 'read')
            || ! method_exists($body, 'eof')
        ) {
            return;
        }

        $remaining = $maximumBytes;
        $emptyReads = 0;

        while (
            $remaining > 0
            && ! $body->eof()
            && ! connection_aborted()
            && ! $lease->expired()
        ) {
            $chunk = $body->read(min(64 * 1024, $remaining));
            if (! is_string($chunk) || $chunk === '') {
                if (++$emptyReads >= 3) {
                    break;
                }

                continue;
            }

            $emptyReads = 0;
            $remaining -= strlen($chunk);
            echo $chunk;
            flush();
        }
    }

    /**
     * @param  resource  $handle
     */
    private function pumpHandle(
        mixed $handle,
        DirectStreamLease $lease,
        int $maximumBytes,
    ): void {
        if (! @stream_set_blocking($handle, false)) {
            return;
        }

        $remaining = $maximumBytes;

        while (
            $remaining > 0
            && ! feof($handle)
            && ! connection_aborted()
            && ! $lease->expired()
        ) {
            $wait = min(
                self::MAX_SELECT_WAIT_NANOSECONDS,
                $lease->remainingNanoseconds(),
            );
            if ($wait <= 0) {
                break;
            }

            $read = [$handle];
            $write = [];
            $except = [];
            $ready = @stream_select(
                $read,
                $write,
                $except,
                intdiv($wait, 1_000_000_000),
                intdiv($wait % 1_000_000_000, 1_000),
            );

            if ($ready === false) {
                break;
            }

            if ($ready === 0) {
                continue;
            }

            $chunk = fread($handle, min(64 * 1024, $remaining));
            if ($chunk === false) {
                break;
            }

            if ($chunk === '') {
                continue;
            }

            $remaining -= strlen($chunk);
            echo $chunk;
            flush();
        }
    }
}
