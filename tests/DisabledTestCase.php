<?php

namespace Rizwan\IntegrationBuilder\Tests;

/**
 * Boots the package with the builder switched off, as in production.
 */
abstract class DisabledTestCase extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('integration-builder.enabled', false);
    }
}
