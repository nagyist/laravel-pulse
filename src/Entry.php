<?php

namespace Laravel\Pulse;

class Entry
{
    /**
     * Create a new Entry instance.
     */
    public function __construct(
        public int $timestamp,
        public string $type,
        public string $key,
        public int $value = null,
    ) {
        //
    }

    /**
     * Resolve the entry for ingest.
     */
    // public function resolve(): self
    // {
    //     return new self($this->table, array_map(value(...), $this->attributes));
    // }
}
