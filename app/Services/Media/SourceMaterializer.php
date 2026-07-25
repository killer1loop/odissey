<?php

namespace App\Services\Media;

use App\Models\MediaItem;
use App\Services\Media\Sources\MediaSourceRegistry;
use Illuminate\Support\Facades\File;
use RuntimeException;

class SourceMaterializer
{
    public function __construct(private readonly MediaSourceRegistry $registry) {}

    /** @return array{path: string, temporary: bool} */
    public function materialize(MediaItem $item): array
    {
        if ($item->source === null) {
            return ['path' => $item->source_locator, 'temporary' => false];
        }
        $adapter = $this->registry->for($item->source);
        if ($path = $adapter->localPath($item->source, $item->source_locator)) {
            return ['path' => $path, 'temporary' => false];
        }
        if (($item->size_bytes ?? 0) > config('odissey.remote_transcode_max_source_bytes')) {
            throw new RuntimeException('remote_source_too_large');
        }
        $result = $adapter->open($item->source, $item->source_locator, null, null);
        $directory = rtrim(config('odissey.transcode_path'), '/').'/sources';
        File::ensureDirectoryExists($directory, 0700);
        $path = $directory.'/'.$item->id.'.'.preg_replace('/[^a-z0-9]/', '', strtolower($item->container ?: 'bin'));
        if (is_object($result->body) && method_exists($result->body, 'read')) {
            $output = fopen($path, 'wb');
            while (! $result->body->eof()) {
                fwrite($output, $result->body->read(1024 * 1024));
                clearstatcache(true, $path);
                if ((filesize($path) ?: 0) > config('odissey.remote_transcode_max_source_bytes')) {
                    fclose($output);
                    File::delete($path);
                    throw new RuntimeException('remote_source_too_large');
                }
            }
            fclose($output);
        } else {
            File::put($path, $result->body);
        }

        return ['path' => $path, 'temporary' => true];
    }
}
