<?php

declare(strict_types=1);

namespace Kommandhub\ChowdeckRelaySW\Tests;

$loader = (new TestBootstrapper())
    ->setPlatformEmbedded(true)
    ->addCallingPlugin()
    ->setForceInstallPlugins(true)
    ->addActivePlugins(
        'KmhChowdeckRelaySW',
    )
    ->bootstrap()
    ->getClassLoader();

$loader->addPsr4('Kommandhub\ChowdeckRelaySW\\Tests\\', __DIR__);
