<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class GenerateSbom extends Command
{
    protected $signature = 'odissey:sbom {path=build/sbom.cdx.json}';

    protected $description = 'Generate a CycloneDX-compatible inventory from locked PHP and JavaScript dependencies';

    public function handle(): int
    {
        $components = [];
        $composer = json_decode(File::get(base_path('composer.lock')), true);
        foreach (array_merge($composer['packages'] ?? [], $composer['packages-dev'] ?? []) as $package) {
            $components[] = ['type' => 'library', 'name' => $package['name'], 'version' => $package['version'], 'purl' => 'pkg:composer/'.$package['name'].'@'.rawurlencode($package['version']), 'licenses' => array_map(fn ($license) => ['license' => ['id' => $license]], $package['license'] ?? [])];
        }
        $npm = json_decode(File::get(base_path('package-lock.json')), true);
        foreach ($npm['packages'] ?? [] as $path => $package) {
            if ($path === '' || empty($package['version'])) {
                continue;
            }
            $name = preg_replace('#^node_modules/#', '', $path);
            $components[] = ['type' => 'library', 'name' => $name, 'version' => $package['version'], 'purl' => 'pkg:npm/'.rawurlencode($name).'@'.rawurlencode($package['version']), 'licenses' => isset($package['license']) ? [['license' => ['id' => $package['license']]]] : []];
        }
        $document = ['$schema' => 'http://cyclonedx.org/schema/bom-1.5.schema.json', 'bomFormat' => 'CycloneDX', 'specVersion' => '1.5', 'version' => 1, 'metadata' => ['timestamp' => now()->toIso8601String(), 'component' => ['type' => 'application', 'name' => 'Odissey']], 'components' => $components];
        $argument = $this->argument('path');
        $path = str_starts_with($argument, DIRECTORY_SEPARATOR)
            ? $argument
            : base_path($argument);
        File::ensureDirectoryExists(dirname($path));
        File::put($path, json_encode($document, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");
        $this->info('SBOM written to '.$path);

        return self::SUCCESS;
    }
}
