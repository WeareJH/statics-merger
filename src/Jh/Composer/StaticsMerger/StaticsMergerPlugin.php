<?php

namespace Jh\Composer\StaticsMerger;

use Composer\Composer;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\IO\IOInterface;
use Composer\Plugin\PluginInterface;
use Composer\Scripts\ScriptEvents;
use Composer\Script\PackageEvent;

/**
 * Composer Plugin for merging static assets with the Jh Magento Skeleton
 * @author Michael Woodward <michael@wearejh.com>
 */
class StaticsMergerPlugin implements PluginInterface, EventSubscriberInterface
{
    protected $composer;
    protected $io;

    public function activate(Composer $composer, IOInterface $io)
    {
        $this->composer = $composer;
        $this->io = $io;
    }

    public static function getSubscribedEvents()
    {
        return array(
            ScriptEvents::POST_PACKAGE_INSTALL => array(
                array('symlinkStatics', 0)
            ),
            ScriptEvents::POST_PACKAGE_UPDATE => array(
                array('symlinkStatics', 0)
            )
        );
    }

    public function symlinkStatics(CommandEvent $event)
    {
        // Fuck knows if this will do anything.
    }
}