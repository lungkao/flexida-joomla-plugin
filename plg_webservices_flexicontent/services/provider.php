<?php
\defined('_JEXEC') or die;

use Flexida\Plugin\WebServices\Flexicontent\Extension\Flexicontent;
use Joomla\CMS\Extension\PluginInterface;
use Joomla\CMS\Factory;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\DI\Container;
use Joomla\DI\ServiceProviderInterface;

return new class () implements ServiceProviderInterface {
    public function register(Container $container): void
    {
        $container->set(
            PluginInterface::class,
            $container->lazy(Flexicontent::class, function (Container $container) {
                $plugin = new Flexicontent((array) PluginHelper::getPlugin('webservices', 'flexicontent'));
                $plugin->setApplication(Factory::getApplication());

                return $plugin;
            })
        );
    }
};
