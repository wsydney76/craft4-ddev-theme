<?php

use config\Env;
use craft\helpers\App;

// https://github.com/vaersaagod/seomate/blob/master/README.md

return [
    '*' => [
        'cacheEnabled' => false,
        'defaultProfile' => 'standard',

        'outputAlternate' => false,

        'defaultMeta' => [
            'description' => ['globalSeo.globalseofields:settings:description'],
            'image' => ['globalSeo.globalseofields:settings:image'],
        ],

        'fieldProfiles' => [
            'standard' => [
                'title' => ['seoFields.settings:alternativeTitle', 'title'],
                'description' => ['bodyContent.summary:text', 'seoFields.settings:description', 'teaser'],
                'image' => ['seoFields.settings:image', 'featuredImage'],
                'robots' => ['seoFields.settings:robots'],
            ],
        ],

        'sitemapEnabled' => true,
        'sitemapLimit' => 100,
        'sitemapConfig' => [
            'elements' => [
                'page' => ['changefreq' => 'daily', 'priority' => 1],
                'article' => ['changefreq' => 'daily', 'priority' => 1],
                'homepage' => ['changefreq' => 'daily', 'priority' => 1],
                'legal' => ['changefreq' => 'daily', 'priority' => 1],
            ],
        ],

        'siteName' => App::env('CRAFT_ENVIRONMENT') . ' ' . Craft::$app->getSystemName(),
        'sitenameSeparator' => ' - '
    ],

    'production' => [
        'cacheEnabled' => true,
        'siteName' => Craft::$app->getSystemName(),
    ]

];
