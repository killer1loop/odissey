<?php

namespace App\Services\Iptv;

use Psr\Http\Message\StreamInterface;

class PlaybackStreamPump
{
    private const MAX_SELECT_WAIT_NANOSECONDS = 250_000_000;

    public function pump(
        StreamInterface $body,
        PlaybackConcurrencyLease $lease,
        string $prefix = '',
    ): void {
        if ($lease->expired()) {
            $body->close();

            return;
        }

        $handle = $body->detach();

        if (! is_resource($handle)) {
            $body->close();

            return;
        }

        try {
            if (! @stream_set_blocking($handle, false)) {
                return;
            }

            if ($prefix !== '') {
                echo $prefix;
                flush();
            }

            while (
                ! feof($handle)
                && ! connection_aborted()
                && ! $lease->expired()
            ) {
                $remaining = $lease->remainingNanoseconds();

                if ($remaining <= 0) {
                    break;
                }

                $wait = min(self::MAX_SELECT_WAIT_NANOSECONDS, $remaining);
                $seconds = intdiv($wait, 1_000_000_000);
                $microseconds = intdiv($wait % 1_000_000_000, 1_000);
                $read = [$handle];
                $write = [];
                $except = [];
                $ready = @stream_select(
                    $read,
                    $write,
                    $except,
                    $seconds,
                    $microseconds,
                );

                if ($ready === false) {
                    break;
                }

                if ($ready === 0) {
                    continue;
                }

                $chunk = fread($handle, 64 * 1024);

                if ($chunk === false) {
                    break;
                }

                if ($chunk === '') {
                    continue;
                }

                echo $chunk;
                flush();
            }
        } finally {
            fclose($handle);
        }
    }
}
