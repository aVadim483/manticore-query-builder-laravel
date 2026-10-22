<?php

declare(strict_types=1);

namespace avadim\Manticore\Laravel\Database;

use UnexpectedValueException;

/** Advertised components are evidence of installation, not successful operation probes. */
final readonly class ServerCapabilities
{
    public string $version;
    public array $components;
    public bool $hasClusters;

    public function __construct(public array $status)
    {
        if (!preg_match('/^(\d+\.\d+\.\d+)/', $status['version'] ?? '', $match)) {
            throw new UnexpectedValueException('Unrecognized Manticore server version.');
        }
        $this->version = $match[1];
        preg_match_all('/\((columnar|secondary|knn|embeddings|buddy)\s+([^)]*)\)/', $status['version'], $matches, PREG_SET_ORDER);
        $components = [];
        foreach ($matches as $component) {
            $components[$component[1]] = $component[2];
        }
        $this->components = $components;
        $this->hasClusters = (bool)array_filter(array_keys($status), static fn ($key) => str_starts_with($key, 'cluster_'));
    }

    public function advertises(string $component): bool
    {
        return isset($this->components[$component]);
    }
}
