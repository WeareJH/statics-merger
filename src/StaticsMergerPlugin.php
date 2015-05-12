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
     * @var array
     */
    protected $packageExtra = array();

    /**
     * @var array
     */
    protected $staticMaps = array();

    /**
     * @var string
     */
    protected $mageDir;

    /**
     * @param Composer $composer
     * @param IOInterface $io
     * @return bool|void
     */
    public function activate(Composer $composer, IOInterface $io)
    {
        $this->composer     = $composer;
        $this->io           = $io;
        $this->vendorDir    = rtrim($composer->getConfig()->get('vendor-dir'), '/');
        $this->filesystem   = new Filesystem();
        $this->packageExtra = $this->composer->getPackage()->getExtra();

        if (!isset($this->packageExtra['static-map'])) {
            $this->io->write("<info>No static maps defined</info>");
            return false;
        }

        if (!isset($this->packageExtra['magento-root-dir'])) {
            $this->io->write("<info>Magento root dir not defined</info>");
            return false;
        }

        $this->staticMaps   = $this->packageExtra['static-map'];
        $this->mageDir      = rtrim($this->packageExtra['magento-root-dir'], '/');
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
            ScriptEvents::PRE_INSTALL_CMD => array(
                array('staticsCleanup', 0)
            ),
            ScriptEvents::PRE_UPDATE_CMD => array(
                array('staticsCleanup', 0)
            ),
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
     * @return bool|void
     */
    public function symlinkStatics(CommandEvent $event)
    {
        foreach ($this->getStaticPackages() as $package) {
            foreach ($this->getStaticMaps($package->getName()) as $mappingDir => $mappings) {
                $packageSource      = $this->getInstallPath($package);
                $destinationTheme   = sprintf('%s/%s/skin/frontend/%s', getcwd(), $this->mageDir, $mappingDir);

                // Add slash to paths
                $packageSource      = rtrim($packageSource, "/");
                $destinationTheme   = rtrim($destinationTheme, "/");

                // If theme doesn't exist - Create it
                $this->filesystem->ensureDirectoryExists($destinationTheme);

                // Process files from package
                if ($mappings) {
                    $this->processFiles($packageSource, $destinationTheme, $mappings);
                } else {
                    $this->io->write(
                        sprintf('<error>%s requires at least one file mapping, has none!<error>', $package->getPrettyName())
                    );
                }
            }
        }
    }

    /**
     * @param string $packageSource
     * @param string $destinationTheme
     * @param array $files
     * @return bool|void
     */
    public function processFiles($packageSource, $destinationTheme, $files = array())
    {
        foreach ($files as $file) {
            // Ensure we have correct json
            if (isset($file['src']) && isset($file['dest'])) {
                $src    = sprintf("%s/%s", $packageSource, $file['src']);
                $dest   = rtrim($file['dest'], '/');

                // Check if it's a glob
                if (strpos($src, '*') !== false) {
                    $files = array_filter(glob($src), 'is_file');
                    foreach ($files as $globFile) {
                        //strip the full path
                        //and just get path relative to package
                        $fileSource = str_replace(sprintf("%s/", $packageSource), "", $globFile);

                        $dest = ltrim(sprintf("%s/%s", $dest, basename($fileSource)), '/');

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
     * @return bool|void
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
            $relativeSourcePath = $this->getRelativePath($destinationPath, $sourcePath);
            symlink($relativeSourcePath, $destinationPath);
        } catch (\ErrorException $ex) {
            $this->io->write(
                "<error>Failed to symlink $sourcePath to $destinationPath</error>"
            );
        }
    }

    /**
     * Get filtered packages array
     * @return array
     */
    public function getStaticPackages()
    {
        $packages = $this->composer->getRepositoryManager()->getLocalRepository()->getPackages();

        return array_filter($packages, function ($package) {
            return $package->getType() == static::PACKAGE_TYPE && $this->getStaticMaps($package->getName());
        });
    }

    /**
     * Get a single static package's maps or all static maps
     * @param null $packageName
     * @return array|bool
     */
    public function getStaticMaps($packageName = null)
    {
        if ($packageName === null) {
            return $this->staticMaps;
        } else if (array_key_exists($packageName, $this->staticMaps)) {
            return $this->staticMaps[$packageName];
        } else {
            $this->io->write(sprintf("<error>Mappings for %s are not defined</error>", $packageName));
            return array();
        }
    }

    /**
     * @param CommandEvent $event
     * @return bool|void
     */
    public function staticsCleanup(CommandEvent $event)
    {
        foreach ($this->getStaticPackages() as $package) {
            foreach ($this->getStaticMaps($package->getName()) as $mappingDir => $mappings) {
                $mappingDirs    = explode('/', $mappingDir);
                $packageRootDir = sprintf('%s/%s/skin/frontend/%s', getcwd(), $this->mageDir, $mappingDirs[0]);
                $themeRootDir   = sprintf('%s/%s/skin/frontend/%s', getcwd(), $this->mageDir, $mappingDir);

                try {
                    $this->filesystem->removeDirectory(rtrim($themeRootDir, "/"));
                } catch (\RuntimeException $ex) {
                    $this->io->write(
                        sprintf("<error>Failed to remove %s from %s</error>", $package->getName(), $themeRootDir)
                    );
                    return;
                }

                // Check if we need to remove package dir
                if (is_dir($packageRootDir) && $this->filesystem->isDirEmpty($packageRootDir)) {
                    try {
                        $this->filesystem->removeDirectory(rtrim($packageRootDir, "/"));
                    } catch (\RuntimeException $ex) {
                        $this->io->write(
                            sprintf("<error>Failed to remove %s from %s</error>", $package->getName(), $packageRootDir)
                        );
                    }
                }
            }
        }
    }

    /**
     * Returns the relative path from $from to $to
     *
     * This is utility method for symlink creation.
     * Orig Source: http://stackoverflow.com/a/2638272/485589
     *
     * @param string $from
     * @param string $to
     *
     * @return string
     */
    public function getRelativePath($from, $to)
    {
        // some compatibility fixes for Windows paths
        $from = is_dir($from) ? rtrim($from, '\/') . '/' : $from;
        $to   = is_dir($to)   ? rtrim($to, '\/') . '/'   : $to;
        $from = str_replace('\\', '/', $from);
        $to   = str_replace('\\', '/', $to);

        $from     = explode('/', $from);
        $to       = explode('/', $to);
        $relPath  = $to;

        foreach($from as $depth => $dir) {
            // find first non-matching dir
            if($dir === $to[$depth]) {
                // ignore this directory
                array_shift($relPath);
            } else {
                // get number of remaining dirs to $from
                $remaining = count($from) - $depth;
                if($remaining > 1) {
                    // add traversals up to first matching dir
                    $padLength = (count($relPath) + $remaining - 1) * -1;
                    $relPath = array_pad($relPath, $padLength, '..');
                    break;
                } else {
                    $relPath[0] = './' . $relPath[0];
                }
            }
        }
        return implode('/', $relPath);
    }
}
