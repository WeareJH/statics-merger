<?php
declare(strict_types = 1);

namespace Jh\StaticsMerger;

use Composer\Composer;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\IO\IOInterface;
use Composer\Plugin\PluginInterface;
use Composer\Script\ScriptEvents;
use Composer\Util\Filesystem;
use Composer\Package\PackageInterface;
use Symfony\Component\Process\Exception\LogicException;
use Symfony\Component\Process\Exception\RuntimeException;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

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
    protected $packageExtra = [];

    /**
     * @var array
     */
    protected $staticMaps = [];

    /**
     * @var string
     */
    protected $mageDir = '';

    /**
     * @throws \RuntimeException On composer config failure
     */
    public function activate(Composer $composer, IOInterface $io) : bool
    {
        $this->composer     = $composer;
        $this->io           = $io;
        $this->vendorDir    = rtrim($composer->getConfig()->get('vendor-dir'), '/');
        $this->filesystem   = new Filesystem();
        $this->packageExtra = $this->composer->getPackage()->getExtra();

        if (!array_key_exists('static-map', $this->packageExtra)) {
            $this->io->write('<info>No static maps defined</info>');
            return false;
        }

        if (!array_key_exists('magento-root-dir', $this->packageExtra)) {
            $this->io->write('<info>Magento root dir not defined, assumed current working directory</info>');
        } else {
            $this->mageDir = rtrim($this->packageExtra['magento-root-dir'], '/');
        }

        $this->staticMaps  = $this->packageExtra['static-map'];
        return true;
    }

    public function getInstallPath(PackageInterface $package) : string
    {
        $targetDir = $package->getTargetDir();

        return $this->getPackageBasePath($package) . ($targetDir ? '/'.$targetDir : '');
    }

    protected function getPackageBasePath(PackageInterface $package) : string
    {
        $this->filesystem->ensureDirectoryExists($this->vendorDir);
        $this->vendorDir = realpath($this->vendorDir);

        return ($this->vendorDir ? $this->vendorDir.'/' : '') . $package->getPrettyName();
    }

    public static function getSubscribedEvents() : array
    {
        return [
            ScriptEvents::PRE_INSTALL_CMD => [
                ['verifyEnvironment', 1],
                ['staticsCleanup', 0]
            ],
            ScriptEvents::PRE_UPDATE_CMD => [
                ['staticsCleanup', 0]
            ],
            ScriptEvents::POST_INSTALL_CMD => [
                ['staticsCompile', 0],
                ['symlinkStatics', 0]
            ],
            ScriptEvents::POST_UPDATE_CMD => [
                ['symlinkStatics', 0]
            ]
        ];
    }

    /**
     * @throws \RuntimeException When Yarn install fails or crossbow fails
     * @throws LogicException From process
     * @throws RuntimeException From process
     */
    public function staticsCompile()
    {
        foreach ($this->getStaticPackages() as $package) {
            $cwd = getcwd();

            chdir($this->getInstallPath($package));

            $exitCode = (new Process($this->getYarnExecutablePath()))->run(function ($type, $buffer) {
                $this->io->write($buffer);
            });

            if (0 !== $exitCode) {
                throw new \RuntimeException(
                    sprintf('Failed to install dependencies for "%s"', $package->getPrettyName())
                );
            }

            $exitCode = (new Process('node_modules/.bin/cb release'))->run(function ($type, $buffer) {
                $this->io->write($buffer);
            });

            if (0 !== $exitCode) {
                throw new \RuntimeException(sprintf('Static package "%s" failed to build', $package->getPrettyName()));
            }

            chdir($cwd);
        }
    }

    public function symlinkStatics()
    {
        foreach ($this->getStaticPackages() as $package) {
            $packageSource = $this->getInstallPath($package);

            foreach ($this->getStaticMaps($package->getName()) as $mappingDir => $mappings) {
                $destinationTheme = $this->getRootThemeDir($mappingDir);

                // Add slash to paths
                $packageSource    = rtrim($packageSource, '/');
                $destinationTheme = rtrim($destinationTheme, '/');

                // If theme doesn't exist - Create it
                $this->filesystem->ensureDirectoryExists($destinationTheme);

                // Process files from package
                if ($mappings) {
                    $this->processFiles($packageSource, $destinationTheme, $mappings);
                } else {
                    $this->io->write(
                        sprintf(
                            '<error>%s requires at least one file mapping, has none!<error>',
                            $package->getPrettyName()
                        )
                    );
                }
            }
        }
    }

    /**
     * Processes defined file mappings and symlinks resulting files to destination theme
     */
    public function processFiles(string $packageSource, string $destinationTheme, array $files = [])
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
     * Process symlink, checks given source and destination paths
     */
    public function processSymlink(
        string $packageSrc,
        string $relativeSourcePath,
        string $destinationTheme,
        string $relativeDestinationPath
    ) {
        $sourcePath         = sprintf("%s/%s", $packageSrc, $relativeSourcePath);
        $destinationPath    = sprintf("%s/%s", $destinationTheme, $relativeDestinationPath);

        if (!file_exists($sourcePath)) {
            $this->io->write(
                sprintf('<error>The static package does not contain directory: "%s" </error>', $relativeSourcePath)
            );
            return;
        }

        if (file_exists($destinationPath) && !is_link($destinationPath)) {
            $this->io->write(
                sprintf(
                    '<error>Your static path: "%s" is currently not a symlink, please remove first </error>',
                    $destinationPath
                )
            );
            return;
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

        $relativeSourcePath = $this->getRelativePath($destinationPath, $sourcePath);
        if (!@\symlink($relativeSourcePath, $destinationPath)) {
            $this->io->write(sprintf('<error>Failed to symlink %s to %s</error>', $sourcePath, $destinationPath));
        }
    }

    /**
     * Get filtered packages array
     */
    public function getStaticPackages() : array
    {
        $packages = $this->composer->getRepositoryManager()->getLocalRepository()->getPackages();

        return array_filter($packages, function (PackageInterface $package) {
            return $package->getType() == static::PACKAGE_TYPE && $this->getStaticMaps($package->getName());
        });
    }

    /**
     * Get a single static package's maps or all static maps
     */
    public function getStaticMaps($packageName = null) : array
    {
        if ($packageName === null) {
            return $this->staticMaps;
        } elseif (array_key_exists($packageName, $this->staticMaps)) {
            return $this->staticMaps[$packageName];
        } else {
            $this->io->write(sprintf('<error>Mappings for %s are not defined</error>', $packageName));
            return [];
        }
    }

    /**
     * Isolated event that runs on PRE hooks to cleanup mapped packages
     */
    public function staticsCleanup()
    {
        foreach ($this->getStaticPackages() as $package) {
            foreach ($this->getStaticMaps($package->getName()) as $mappingDir => $mappings) {
                $themeRootDir = $this->getRootThemeDir($mappingDir);

                if (!is_dir($themeRootDir)) {
                    continue;
                }

                // Get contents and sort
                $contents   = $this->getFullDirectoryListing($themeRootDir);
                $strLengths = array_map('strlen', $contents);
                array_multisort($strLengths, SORT_DESC, $contents);

                // Exception error message
                $errorMsg = sprintf("<error>Failed to remove %s from %s</error>", $package->getName(), $themeRootDir);

                foreach ($contents as $content) {
                    // Remove packages symlinked files/dirs
                    if (is_link($content)) {
                        $this->tryCleanup($content, $errorMsg);
                        continue;
                    }

                    // Remove empty folders
                    if (is_dir($content) && $this->filesystem->isDirEmpty($content)) {
                        $this->tryCleanup($content, $errorMsg);
                    }
                }
            }
        }
    }

    public function verifyEnvironment() : bool
    {
        return is_executable((new ExecutableFinder)->find('yarn', false));
    }

    private function getYarnExecutablePath() : string
    {
        return (new ExecutableFinder)->find('yarn', false);
    }

    /**
     * Try to cleanup a file/dir, output on exception
     */
    private function tryCleanup(string $path, string $errorMsg)
    {
        try {
            $this->filesystem->remove($path);
        } catch (\RuntimeException $ex) {
            $this->io->write($errorMsg);
        }
    }

    /**
     * Get full directory listing without dots
     */
    private function getFullDirectoryListing(string $path) : array
    {
        $listings   = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path));
        $listingArr = array_keys(\iterator_to_array($listings));

        // Remove dots :)
        $listingArr = array_map(function ($listing) {
            return rtrim($listing, '\/\.');
        }, $listingArr);

        return array_unique($listingArr);
    }

    /**
     * This is utility method for symlink creation.
     * @see http://stackoverflow.com/a/2638272/485589
     */
    public function getRelativePath(string $from, string $to) : string
    {
        // some compatibility fixes for Windows paths
        $from = is_dir($from) ? rtrim($from, '\/') . '/' : $from;
        $to   = is_dir($to) ? rtrim($to, '\/') . '/' : $to;
        $from = str_replace('\\', '/', $from);
        $to   = str_replace('\\', '/', $to);

        $from     = explode('/', $from);
        $to       = explode('/', $to);
        $relPath  = $to;

        foreach ($from as $depth => $dir) {
            // find first non-matching dir
            if ($dir === $to[$depth]) {
                // ignore this directory
                array_shift($relPath);
            } else {
                // get number of remaining dirs to $from
                $remaining = count($from) - $depth;
                if ($remaining > 1) {
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

    private function getRootThemeDir(string $mappingDir) : string
    {
        return sprintf(
            '%s%s/app/design/frontend/%s/web',
            getcwd(),
            $this->mageDir ? '/' . $this->mageDir : '',
            ucwords($mappingDir)
        );
    }
}
