<?php

namespace LePhare\PackagistDependents;

class Package
{
    public string $vendor;
    public string $name;
    public string $version;

    public function __construct(string $vendor, string $name, string $version)
    {
        $this->name = $name;
        $this->version = $version;
        $this->vendor = $vendor;
    }

    public function __toString()
    {
        return $this->vendor.'/'.$this->name.':'.$this->version;
    }
}
