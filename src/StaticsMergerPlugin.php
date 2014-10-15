<?php

namespace Jh\StaticsMerger;

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
     * Package Type to install
     */
    const PACKAGE_TYPE = 'static';

    /**
     * @var Composer $composer
     */
    protected $composer;

    /**
     * @var IOInterface $io
     */
    protected $io;

    /**
     * @var Filesystem
     */
    protected $filesystem;

    /**
     * @var string
     */
    protected $vendorDir;

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

    /**
     * @param PackageInterface $package
     * @return string
     */
    public function getInstallPath(PackageInterface $package)
    {
        $targetDir = $package->getTargetDir();

        return $this->getPackageBasePath($package) . ($targetDir ? '/'.$targetDir : '');
    }

    /**
     * @param PackageInterface $package
     * @return string
     */
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

        if (!isset($extra['static-map'])) {
            $this->io->write("<info>No static maps defined</info>");
            return false;
        }


        $staticMaps = $extra['static-map'];
        if (!isset($extra['magento-root-dir'])) {
            $this->io->write("<info>Magento root dir not defined</info>");
            return false;
        }
        $magentoRootDir = rtrim($extra['magento-root-dir'], '/');
        foreach ($packages as $package) {
            if ($package->getType() !== static::PACKAGE_TYPE || !isset($staticMaps[$package->getName()])) {
                continue;
            }

            $packageMap         = $staticMaps[$package->getName()];
            $packageSource      = $this->getInstallPath($package);
            $destinationTheme   = sprintf('%s/%s/skin/frontend/%s', getcwd(), $magentoRootDir, $packageMap);

            // Add slash to paths
            $packageSource      = rtrim($packageSource, "/");
            $destinationTheme   = rtrim($destinationTheme, "/");

            // If theme doesn't exist - Create it
            $this->filesystem->ensureDirectoryExists($destinationTheme);

            // Process assets dir first
            $this->processSymlink($packageSource, 'assets', $destinationTheme, 'assets');

            // Process any globs from package
            $packageExtra = $package->getExtra();

            // Process any files from package
            if (isset($packageExtra['files'])) {
                $this->processExtraFiles($packageSource, $destinationTheme, $packageExtra['files']);
            }
        }
    }

    /**
     * @param string $packageSource
     * @param string $destinationTheme
     * @param array $files
     * @return bool|void
     */
    public function processExtraFiles($packageSource, $destinationTheme, $files = array())
    {
        foreach ($files as $file) {
            // Ensure we have correct json
            if (isset($file['src']) && isset($file['dest'])) {
                $src    = sprintf("%s/%s", $packageSource, $file['src']);
                $dest   = $file['dest'];

                // Check if it's a glob
                if (strpos($src, '*') !== false) {
                    $files = array_filter(glob($src), 'is_file');
                    foreach ($files as $globFile) {
                        //strip the full path
                        //and just get path relative to package
                        $fileSource = str_replace(sprintf("%s/", $packageSource), "", $globFile);

                        if ($dest) {
                            $dest = sprintf("%s/%s", rtrim($dest, "/"), basename($fileSource));
                        } else {
                            $dest = $fileSource;
                        }

                        $this->processSymlink($packageSource, $fileSource, $destinationTheme, $dest);
                        $dest = $file['dest'];
                    }
                } else {
                    if (!$dest) {
                        $this->io->write(
                            sprintf('<error>Full path is required for: "%s" </error>', $file['src'])
                        );
                        return false;
                    }

                    $this->processSymlink($packageSource, $file['src'], $destinationTheme, $dest);
                }
            }
        }
    }

    /**
     * Process symlink with checks given source and destination paths
     * @param string $packageSrc
     * @param string $relativeSourcePath
     * @param string $destinationTheme
     * @param string $relativeDestinationPath
     */
    public function processSymlink($packageSrc, $relativeSourcePath, $destinationTheme, $relativeDestinationPath)
    {
        $sourcePath         = sprintf("%s/%s", $packageSrc, $relativeSourcePath);
        $destinationPath    = sprintf("%s/%s", $destinationTheme, $relativeDestinationPath);

        if (!file_exists($sourcePath)) {
            $this->io->write(
                sprintf('<error>The static package does not contain directory: "%s" </error>', $relativeSourcePath)
            );
            return false;
        }

        if (file_exists($destinationPath) && !is_link($destinationPath)) {
            $this->io->write(
                sprintf(
                    '<error>Your static path: "%s" is currently not a symlink, please remove first </error>',
                    $destinationPath
                )
            );
            return false;
        }

        //if it's a link, remove it and recreate it
        //assume we are updating the static package
        if (is_link($destinationPath)) {
            unlink($destinationPath);
        } else {

            //file doesn't already exist
            //lets make sure the parent directory does
            $this->filesystem->ensureDirectoryExists(dirname($destinationPath));
        }

        try {
            symlink($sourcePath, $destinationPath);
        } catch (\ErrorException $ex) {
            $this->io->write(
                "<error>Failed to symlink $sourcePath to $destinationPath</error>"
            );
        }
    }
}
