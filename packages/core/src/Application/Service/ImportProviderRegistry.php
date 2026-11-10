<?php

declare(strict_types=1);

namespace SWGallery\Core\Application\Service;

use SWGallery\Core\Contract\ImportProviderRegistryInterface;
use SWGallery\Core\Contract\MediaImportProviderInterface;

final class ImportProviderRegistry implements ImportProviderRegistryInterface
{
    /** @var array<string, MediaImportProviderInterface> */
    private array $providers = [];

    public function register(MediaImportProviderInterface $provider): void
    {
        $id = $provider->getProviderId();
        if ($id === '') {
            throw new \InvalidArgumentException('An import provider needs a non-empty id.');
        }
        if (isset($this->providers[$id])) {
            throw new \InvalidArgumentException('An import provider with this id is already registered.');
        }
        $this->providers[$id] = $provider;
    }

    public function has(string $providerId): bool
    {
        return isset($this->providers[$providerId]);
    }

    public function get(string $providerId): MediaImportProviderInterface
    {
        return $this->providers[$providerId] ?? throw new \OutOfBoundsException('Unknown import provider.');
    }

    public function all(): array
    {
        return array_values($this->providers);
    }
}
