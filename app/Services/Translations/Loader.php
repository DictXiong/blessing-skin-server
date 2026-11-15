<?php

namespace App\Services\Translations;

use Spatie\TranslationLoader\TranslationLoaderManager;

class Loader extends TranslationLoaderManager
{
    protected function loadPaths(array $paths, $locale, $group)
    {
        $translations = parent::loadPaths($paths, $locale, $group);

        foreach ($paths as $path) {
            $full = "{$path}/{$locale}/{$group}.yml";
            if ($this->files->exists($full)) {
                $translations = array_replace_recursive(
                    $translations,
                    resolve(Yaml::class)->parse($full)
                );
            }
        }

        return $translations;
    }
}
