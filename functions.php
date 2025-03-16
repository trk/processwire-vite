<?php

namespace ProcessWire;

function vite(array|string|null $entries = null): \Totoglu\Vite\Vite
{
    $vite = new \Totoglu\Vite\Vite();
    return !is_null($entries)
        ? $vite->withEntries((array) $entries)
        : $vite;
}
