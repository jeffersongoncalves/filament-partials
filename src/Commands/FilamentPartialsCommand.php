<?php

namespace AndreFelipe\FilamentPartials\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class FilamentPartialsCommand extends Command
{
    public $signature = 'make:filament-partials {resource?} {--only=}';

    public $description = 'Create partials of a resource';

    public function handle(): int
    {
        $resource = $this->getResourcePath();

        $options = [
            'form',
            'table',
        ];

        if (empty($resource)) {
            return 1;
        }

        $this->createPartialsDirectory($resource['path']);

        $namespace = $this->getNamespace($resource['path'], $resource['name']);

        $only = $this->option('only');
        if ($only) {
            $selectedOptions = explode(',', $only);
            foreach ($selectedOptions as $option) {
                $option = trim($option);
                $option = strtolower($option);
                if (! in_array($option, $options)) {
                    $this->error("Invalid option: {$option}. Allowed options are: ".implode(', ', $options));

                    return 1;
                }
            }
            $options = $selectedOptions;
        }

        foreach ($options as $option) {
            $this->createPartial($option, $resource, $namespace);
        }

        $this->info('Partials created successfully.');

        return 0;
    }

    protected function getResourcePath(): array
    {
        $resource = $this->argument('resource');

        if (! $resource) {
            $resource = $this->ask('What is the resource name?');
        }

        $resourcePath = $this->findResourcePath($resource);

        if (empty($resourcePath)) {
            $this->error('Invalid resource path');
            $this->error('Please check if the resource exists in the base resources path or in any cluster');

            return [];
        }

        return [
            'path' => $resourcePath[0],
            'name' => $resource,
        ];
    }

    protected function findResourcePath(string $resource): array
    {
        $baseResourcesPath = config('filament-partials.base_resources_path');
        $baseClustersPath = config('filament-partials.base_clusters_path');

        $resourcePath = base_path($baseResourcesPath.'/'.$resource);
        if (is_dir($resourcePath)) {
            return [$resourcePath];
        }

        $clusters = glob(base_path($baseClustersPath.'/*'), GLOB_ONLYDIR);
        foreach ($clusters as $cluster) {
            $clusterResourcePath = $cluster.'/Resources/'.$resource;
            if (is_dir($clusterResourcePath)) {
                return [$clusterResourcePath];
            }
        }

        return [];
    }

    protected function createPartialsDirectory(string $resourcePath): void
    {
        $partialsPath = $resourcePath.'/Partials';

        if (! is_dir($partialsPath)) {
            File::makeDirectory($partialsPath);
        }
    }

    protected function getNamespace(string $resourcePath, string $resourceName): string
    {
        $resourceFile = glob($resourcePath.'.php')[0];

        $content = File::get($resourceFile);

        $baseNamespace = $this->getNamespaceFromContent($content);

        return "{$baseNamespace}\\{$resourceName}\\Partials";
    }

    protected function getNamespaceFromContent(string $content): string
    {
        $namespace = '';

        preg_match('/namespace (.*);/', $content, $matches);

        if (isset($matches[1])) {
            $namespace = $matches[1];
        }

        return $namespace;
    }

    protected function createPartial(string $partial, array $resource, string $namespace): void
    {
        $filename = $resource['name'].ucfirst($partial).'.php';
        $partialPath = $resource['path'].'/Partials/'.$filename;

        if (File::exists($partialPath)) {
            $this->info("Partial {$filename} already exists. Skipping creation.");

            return;
        }

        $stub = File::get(__DIR__.'/../stubs/'.$partial.'.stub');

        $modelName = str_replace('Resource', '', $resource['name']);

        $stub = str_replace('{{ namespace }}', $namespace, $stub);
        $stub = str_replace('{{ resource }}', $resource['name'], $stub);
        $stub = str_replace('{{ model }}', $modelName, $stub);

        File::put($partialPath, $stub);

        $this->addTraitToResource($resource['path'], $resource['name'], $namespace, $partial);
    }

    protected function addTraitToResource(string $resourcePath, string $resourceName, string $namespace, string $partial): void
    {
        $resourceFile = $resourcePath.'.php';
        $trait = "\\{$namespace}\\{$resourceName}".ucfirst($partial);

        $content = File::get($resourceFile);

        if (! str_contains($content, "use {$trait};")) {
            $content = preg_replace('/(class\s+'.$resourceName.'\s+extends\s+Resource\s*\{)/', "$1\n    use {$trait};", $content);
        }

        $method = match ($partial) {
            'form' => 'form',
            'table' => 'table',
        };

        $content = preg_replace('/public\s+static\s+function\s+'.$method.'\(.*?\}\s*\n/s', '', $content);

        File::put($resourceFile, $content);
    }
}
