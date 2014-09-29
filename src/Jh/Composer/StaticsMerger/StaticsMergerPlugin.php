<?php

// TODO: Make package type a CONSTANT
// TODO: Clean up dynamic declarations

namespace Jh\Composer\StaticsMerger;

use Composer\Composer;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\IO\IOInterface;
use Composer\Plugin\PluginInterface;
use Composer\Script\ScriptEvents;
use Composer\Script\CommandEvent;
use Composer\Util\Filesystem;
use Composer\Package\PackageInterface;

/**
 * Composer Plugin for merging static assets with the Jh Magento Skeleton
 * @author Michael Woodward <michael@wearejh.com>
 */
class StaticsMergerPlugin implements PluginInterface, EventSubscriberInterface
{
    /**
     * @var Composer $composer
     */
    protected $composer;

    /**
     * @var IOInterface $io
     */
    protected $io;

    /**
     * @param Composer $composer
     * @param IOInterface $io
     */
    public function activate(Composer $composer, IOInterface $io)
    {
        $this->composer     = $composer;
        $this->io           = $io;
        $this->vendorDir    = rtrim($composer->getConfig()->get('vendor-dir'), '/');
        $this->filesystem   = new Filesystem();
    }


    public function getInstallPath(PackageInterface $package)
    {
        $targetDir = $package->getTargetDir();

        return $this->getPackageBasePath($package) . ($targetDir ? '/'.$targetDir : '');
    }

    protected function getPackageBasePath(PackageInterface $package)
    {
        $this->filesystem->ensureDirectoryExists($this->vendorDir);
        $this->vendorDir = realpath($this->vendorDir);

        return ($this->vendorDir ? $this->vendorDir.'/' : '') . $package->getPrettyName();
    }

    /**
     * Tell event dispatcher what events we want to subscribe to
     * @return array
     */
    public static function getSubscribedEvents()
    {
        return array(
            ScriptEvents::POST_INSTALL_CMD => array(
                array('symlinkStatics', 0)
            ),
            ScriptEvents::POST_UPDATE_CMD => array(
                array('symlinkStatics', 0)
            )
        );
    }

    /**
     * Symlink the static repositories
     * @param CommandEvent $event
     */
    public function symlinkStatics(CommandEvent $event)
    {
        $rootPackage    = $this->composer->getPackage();
        $packages       = $this->composer->getRepositoryManager()->getLocalRepository()->getPackages();
        $extra          = $rootPackage->getExtra();

        if (isset($extra) && isset($extra['static-maps'])) {
            $staticMaps = $extra['static-maps'];
            if (isset($extra['magento-root-dir'])) {
                $magentoRootDir = $extra['magento-root-dir'];
                foreach ($packages as $package) {
                    if ($package->getType() == "static" && isset($staticMaps[$package->getName()])) {

                        $packageMap         = $staticMaps[$package->getName()];
                        $installedPath      = $this->getInstallPath($package);
                        $destinationPath    = sprintf('%s/%sskin/frontend/%s', getcwd(), $magentoRootDir, $packageMap);

                        // Add slash to paths
                        $installedPath      = rtrim($installedPath, "/") . "/";
                        $destinationPath    = rtrim($destinationPath, "/") . "/";

                        if (!file_exists($destinationPath)) {
                            // Create destination path
                            mkdir($destinationPath, 0775, true);
                        }

                        // Process assets dir first
                        $this->processSymlink($installedPath . 'assets', $destinationPath . 'assets');

                        // Process any globs from package
                        $packageExtra = $package->getExtra();

                        // Process any files from package
                        if (isset($packageExtra) && isset($packageExtra['files'])) {
                            $files = $packageExtra['files'];

                            foreach ($files as $file) {
                                // Ensure we have correct json
                                if (isset($file['src']) && isset($file['dest'])) {
                                    $src    = $file['src'];
                                    $dest   = $file['dest'];

                                    // Check if it's a glob
                                    if (strpos($src, '*') !== false) {
                                        foreach (glob($installedPath . $src) as $globFile) {
                                            $this->processSymlink(
                                                $globFile,
                                                sprintf('%s%s/%s', $destinationPath, $dest, basename($globFile))
                                            );
                                        }
                                    } else {
                                        $this->processSymlink(
                                            $installedPath . $src,
                                            sprintf('%s%s/%s', $destinationPath, $dest, basename($src))
                                        );
                                    }
                                }
                            }
                        }
                    }
                }
            } else {
                $this->io->write("<info>Magento root dir not defined</info>");
            }
        } else {
            $this->io->write("<info>No static maps defined</info>");
        }
    }

    /**
     * Process symlink with checks given source and destination paths
     * @param string $sourcePath
     * @param string $destinationPath
     */
    public function processSymlink($sourcePath, $destinationPath)
    {
        if (file_exists($sourcePath)) {
            if (!file_exists($destinationPath) || file_exists($destinationPath) && is_link($destinationPath)) {
                try {
                    $this->filesystem->ensureDirectoryExists($destinationPath);
                    symlink($sourcePath, $destinationPath);
                } catch (\ErrorException $ex) {
                    $this->io->write(
                        "<error>Failed to symlink $sourcePath to $destinationPath</error>"
                    );
                }
            } else if (!is_link($destinationPath)) {
                $this->io->write(
                    "<error>Your static path is currently not a symlink, please remove first </error>"
                );
            }
        }
    }
}