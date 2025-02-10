<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

return static function (ContainerConfigurator $container) {
    $container->services()
        ->set('cache.rate_limiter')
            ->parent('cache.app')
            ->tag('cache.pool')

        ->set('limiter', RateLimiterFactory::class)
            ->abstract()
            ->args([
                abstract_arg('config'),
                abstract_arg('storage'),
                null,
            ])
    ;

    if (interface_exists(RateLimiterFactoryInterface::class)) {
        $container->services()
            ->alias(RateLimiterFactoryInterface::class, 'limiter');
    }
};
