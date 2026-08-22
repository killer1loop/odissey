<?php

namespace App\Services\Media;

/**
 * Bounded ring buffer for FFmpeg's stderr. FFmpeg runs with `-loglevel
 * error`, but pathological inputs can still emit continuously for hours;
 * this keeps only the most recent bytes so diagnostics never grow with
 * process lifetime.
 */
final class FfmpegErrorTail
{
    private string $buffer = '';

    public function __construct(
        private readonly int $maximumBytes,
    ) {}

    public function append(string $chunk): void
    {
        if ($chunk === '') {
            return;
        }

        $this->buffer .= $chunk;

        if (strlen($this->buffer) > $this->maximumBytes * 2) {
            $this->buffer = substr($this->buffer, -$this->maximumBytes);
        }
    }

    /**
     * Sanitized, size-bounded view of the captured stderr suitable for logs.
     */
    public function tail(): string
    {
        $tail = substr($this->buffer, -$this->maximumBytes);
        $tail = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $tail) ?? '';
        $tail = preg_replace(
            '/((?:password|passwd|pass|token|key|secret)[=:\s])\S+/i',
            '$1[redacted]',
            $tail,
        ) ?? '';

        // Redaction can inflate a chunk; re-apply the bound afterwards.
        if (strlen($tail) > $this->maximumBytes) {
            $tail = substr($tail, -$this->maximumBytes);
        }

        return trim($tail);
    }
}
