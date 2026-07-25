<?php

namespace App\Services\Media\Captions;

use App\Models\MediaItem;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Symfony\Component\Process\Process;
use ZipArchive;

class CaptionStorage
{
    public function store(MediaItem $item, CaptionCandidate $candidate, string $bytes): string
    {
        if (strlen($bytes) === 0 || strlen($bytes) > config('odissey.caption_max_bytes')) {
            throw new RuntimeException('caption_size_invalid');
        }
        $directory = rtrim(config('odissey.caption_path'), '/').'/'.$item->id;
        File::ensureDirectoryExists($directory, 0700);
        $stem = preg_replace('/[^a-z0-9-]/', '', strtolower($candidate->provider.'-'.$candidate->language.'-'.$candidate->externalId));
        $input = $directory.'/'.$stem.'.input';
        File::put($input, $bytes);
        $source = $input;
        if (str_starts_with($bytes, "PK\x03\x04")) {
            $zip = new ZipArchive;
            if ($zip->open($input) !== true) {
                throw new RuntimeException('caption_archive_invalid');
            }
            $source = '';
            for ($i = 0; $i < min($zip->numFiles, 100); $i++) {
                $name = $zip->getNameIndex($i);
                $stat = $zip->statIndex($i);
                if (($stat['size'] ?? PHP_INT_MAX) <= config('odissey.caption_max_bytes') && preg_match('/\.(srt|vtt|ass|ssa)$/i', basename($name)) && ! str_contains($name, '..')) {
                    $source = $directory.'/'.$stem.'.'.strtolower(pathinfo($name, PATHINFO_EXTENSION));
                    File::put($source, $zip->getFromIndex($i));
                    break;
                }
            }
            $zip->close();
            if ($source === '') {
                throw new RuntimeException('caption_archive_empty');
            }
        }
        $output = $directory.'/'.$stem.'.vtt';
        if (strtolower(pathinfo($source, PATHINFO_EXTENSION)) === 'vtt' || str_starts_with(ltrim(File::get($source)), 'WEBVTT')) {
            File::move($source, $output);
        } else {
            $process = new Process([config('odissey.ffmpeg_binary', 'ffmpeg'), '-hide_banner', '-loglevel', 'error', '-nostdin', '-y', '-i', $source, $output]);
            $process->setTimeout(30);
            $process->mustRun();
        }
        File::delete($input);
        if ($source !== $input && $source !== $output) {
            File::delete($source);
        }
        chmod($output, 0600);

        return $output;
    }
}
