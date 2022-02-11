<?php

namespace Sebdesign\ArtisanCloudflare;

use JsonSerializable;

class Zone implements JsonSerializable
{
    private array $parameters;

    public function __construct(array $parameters = [])
    {
        $this->parameters = $parameters;
    }

    public function replace(array $parameters): void
    {
        $this->parameters = $parameters;
    }

    public function jsonSerialize()
    {
        if (empty($this->parameters)) {
            return ['purge_everything' => true];
        }

        return $this->parameters;
    }

    public function get($key, $default = null)
    {
        return $this->parameters[$key] ?? $default;
    }
}
