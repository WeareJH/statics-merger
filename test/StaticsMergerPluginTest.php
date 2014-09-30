<?php

namespace Jh\StaticsMergerTest;

use Composer\Package\Package;
use Composer\Package\PackageInterface;
use Composer\Package\RootPackage;
use Composer\Repository\ArrayRepository;
use Composer\Repository\RepositoryManager;
use Composer\Repository\WritableArrayRepository;
use Composer\Script\CommandEvent;
use Jh\StaticsMerger\StaticsMergerPlugin;
use VirtualFileSystem\FileSystem;
use Composer\Test\TestCase;
use Composer\Composer;
use Composer\Config;

/**
 * Class StaticsMergerPluginTest
 * @author Aydin Hassan <aydin@hotmail.co.uk>
 */
class StaticsMergerPluginTest extends \PHPUnit_Framework_TestCase
{

    protected $vfs;
    protected $plugin;
    protected $composer;
    protected $config;
    protected $io;
    protected $repoManager;
    protected $localRepository;
    protected $projectRoot;

    public function setUp()
    {
        $this->plugin = new StaticsMergerPlugin();
        $this->config = new Config();
        $this->composer = new Composer();
        $this->composer->setConfig($this->config);

        $root = $this->createFolderStructure();
        chdir($root);

        $this->config->merge([
            'config' => [
                'vendor-dir'    => $this->projectRoot . "/vendor",
                'bin-dir'       => $this->projectRoot . "/vendor/bin",
            ],
        ]);

        $this->io = $this->getMock('Composer\IO\IOInterface');
        $this->repoManager = new RepositoryManager($this->io, $this->config);
        $this->composer->setRepositoryManager($this->repoManager);
        $this->localRepository = new WritableArrayRepository();
        $this->repoManager->setLocalRepository($this->localRepository);
        $this->plugin->activate($this->composer, $this->io);
    }

    private function createFolderStructure()
    {
        $sysDir = realpath(sys_get_temp_dir());

        mkdir($sysDir . "/static-merge-test/htdocs", 0777, true);
        mkdir($sysDir . "/static-merge-test/vendor");
        mkdir($sysDir . "/static-merge-test/vendor/bin");
        $this->projectRoot = $sysDir. "/static-merge-test";
        return $this->projectRoot;
    }

    /**
     * @return RootPackage
     */
    protected function createRootPackageWithOneMap()
    {
        $package = $this->createRootPackage();

        $extra = [
            'magento-root-dir' => 'htdocs',
            'static-map' => [
                'some/static' => 'package/theme'
            ],
        ];

        $package->setExtra($extra);
        return $package;
    }

    public function createRootPackage()
    {
        $package = new RootPackage("root/package", "1.0.0", "root/package");
        return $package;
    }

    public function createStaticPackage(
        $name = 'some/static',
        $extra = [],
        $createAssets = true,
        $addGlob = false,
        $addFiles = false
    ) {
        $package = new Package($name, "1.0.0", $name);
        $package->setExtra($extra);
        $package->setType('static');

        if ($createAssets) {
            $this->createStaticPackageAssets($package);
        }

        if ($addGlob) {
            $this->addGlobFiles($package);
        }

        if ($addFiles) {
            $this->addStandardFiles($package);
        }

        return $package;
    }

    public function createStaticPackageAssets(PackageInterface $package)
    {
        $packageLocation = $this->projectRoot . "/vendor/" . $package->getName();
        mkdir($packageLocation . "/assets", 0777, true);
        touch($packageLocation . "/assets/asset1.jpg");
        touch($packageLocation . "/assets/asset2.jpg");
    }

    public function addGlobFiles(PackageInterface $package)
    {
        $packageLocation = $this->projectRoot . "/vendor/" . $package->getName();
        touch($packageLocation . "/favicon1");
        touch($packageLocation . "/favicon3");
        touch($packageLocation . "/favicon2");

        $extra = array_merge(
            $package->getExtra(),
            [
                'files' => [
                    [
                        'src'   => 'favicon*',
                        'dest'  => ''
                    ]
                ]
            ]
        );

        $package->setExtra($extra);
    }

    public function addStandardFiles(PackageInterface $package)
    {
        $packageLocation = $this->projectRoot . "/vendor/" . $package->getName();
        mkdir($packageLocation . "/assets/images/catalog", 0777, true);
        touch($packageLocation . "/assets/images/catalog/image1.jpg");
        touch($packageLocation . "/assets/images/catalog/image2.jpg");

        $extra = array_merge(
            $package->getExtra(),
            [
                'files' => [
                    [
                        'src'   => 'assets/images/catalog',
                        'dest'  => 'images/catalog'
                    ]
                ]
            ]
        );

        $package->setExtra($extra);
    }

    public function addStandardFilesNoDest(PackageInterface $package)
    {
        $packageLocation = $this->projectRoot . "/vendor/" . $package->getName();
        mkdir($packageLocation . "/assets/images/catalog", 0777, true);
        touch($packageLocation . "/assets/images/catalog/image1.jpg");
        touch($packageLocation . "/assets/images/catalog/image2.jpg");
        touch($packageLocation . "/assets/image3.jpg");

        $extra = array_merge(
            $package->getExtra(),
            [
                'files' => [
                    [
                        'src'   => 'assets/images/catalog',
                        'dest'  => ''
                    ],
                    [
                        'src'   => 'assets/image3.jpg',
                        'dest'  => ''
                    ]
                ]
            ]
        );

        $package->setExtra($extra);
    }

    public function addGlobsWithDest(PackageInterface $package)
    {
        $packageLocation = $this->projectRoot . "/vendor/" . $package->getName();
        mkdir($packageLocation . "/assets/images/catalog", 0777, true);
        touch($packageLocation . "/assets/images/catalog/image1.jpg");
        touch($packageLocation . "/assets/images/catalog/image2.jpg");
        touch($packageLocation . "/assets/images/catalog/picture1.jpg");

        $extra = array_merge(
            $package->getExtra(),
            [
                'files' => [
                    [
                        'src'   => 'assets/images/catalog/image*',
                        'dest'  => 'images'
                    ]
                ]
            ]
        );

        $package->setExtra($extra);
    }

    public function testErrorIsPrintedIfNoStaticMaps()
    {
        $this->composer->setPackage($this->createRootPackage());

        $this->io
            ->expects($this->once())
            ->method('write')
            ->with('<info>No static maps defined</info>');

        $event = new CommandEvent('event', $this->composer, $this->io);
        $this->plugin->symlinkStatics($event);
    }

    public function testErrorIsPrintedIfMagentoRootNotSet()
    {
        $package = $this->createRootPackageWithOneMap();
        $extra = $package->getExtra();
        unset($extra['magento-root-dir']);
        $package->setExtra($extra);

        $this->composer->setPackage($package);

        $this->io
            ->expects($this->once())
            ->method('write')
            ->with('<info>Magento root dir not defined</info>');

        $event = new CommandEvent('event', $this->composer, $this->io);
        $this->plugin->symlinkStatics($event);
    }

    public function testSymLinkStaticsCorrectlySymLinksStaticFiles()
    {
        $this->composer->setPackage($this->createRootPackageWithOneMap());
        $event = new CommandEvent('event', $this->composer, $this->io);

        $this->localRepository->addPackage($this->createStaticPackage());
        $this->plugin->symlinkStatics($event);


        $this->assertFileExists("{$this->projectRoot}/htdocs/skin/frontend/package/theme");
        $this->assertTrue(is_link("{$this->projectRoot}/htdocs/skin/frontend/package/theme/assets"));
    }

    public function testFileGlobAreAllCorrectlySymLinkedToRoot()
    {
        $this->composer->setPackage($this->createRootPackageWithOneMap());
        $this->localRepository->addPackage(
            $this->createStaticPackage('some/static', [], true, true)
        );

        $event = new CommandEvent('event', $this->composer, $this->io);
        $this->plugin->symlinkStatics($event);

        $this->assertFileExists("{$this->projectRoot}/htdocs/skin/frontend/package/theme");
        $this->assertFileExists("{$this->projectRoot}/htdocs/skin/frontend/package/theme/favicon1");
        $this->assertFileExists("{$this->projectRoot}/htdocs/skin/frontend/package/theme/favicon2");
        $this->assertFileExists("{$this->projectRoot}/htdocs/skin/frontend/package/theme/favicon3");
        $this->assertTrue(is_link("{$this->projectRoot}/htdocs/skin/frontend/package/theme/assets"));
        $this->assertTrue(is_link("{$this->projectRoot}/htdocs/skin/frontend/package/theme/favicon1"));
        $this->assertTrue(is_link("{$this->projectRoot}/htdocs/skin/frontend/package/theme/favicon2"));
        $this->assertTrue(is_link("{$this->projectRoot}/htdocs/skin/frontend/package/theme/favicon2"));
    }

    public function testFileGlobAreAllCorrectlySymLinkedWithSetDest()
    {
        $this->composer->setPackage($this->createRootPackageWithOneMap());

        $package = $this->createStaticPackage();

        $this->addGlobsWithDest($package);
        $this->localRepository->addPackage($package);

        $event = new CommandEvent('event', $this->composer, $this->io);
        $this->plugin->symlinkStatics($event);

        $this->assertFileExists("{$this->projectRoot}/htdocs/skin/frontend/package/theme");
        $this->assertFileExists("{$this->projectRoot}/htdocs/skin/frontend/package/theme/images");
        $this->assertTrue(is_dir("{$this->projectRoot}/htdocs/skin/frontend/package/theme/images"));
        $this->assertFileExists("{$this->projectRoot}/htdocs/skin/frontend/package/theme/images/image1.jpg");
        $this->assertFileExists("{$this->projectRoot}/htdocs/skin/frontend/package/theme/images/image2.jpg");
        $this->assertTrue(is_link("{$this->projectRoot}/htdocs/skin/frontend/package/theme/images/image1.jpg"));
        $this->assertTrue(is_link("{$this->projectRoot}/htdocs/skin/frontend/package/theme/images/image2.jpg"));
        $this->assertFileNotExists("{$this->projectRoot}/htdocs/skin/frontend/package/theme/images/picture1.jpg");
    }

    public function testStandardFilesAreAllCorrectlySymLinked()
    {
        $this->composer->setPackage($this->createRootPackageWithOneMap());
        $this->localRepository->addPackage(
            $this->createStaticPackage('some/static', [], true, false, true)
        );

        $event = new CommandEvent('event', $this->composer, $this->io);
        $this->plugin->symlinkStatics($event);

        $this->assertFileExists("{$this->projectRoot}/htdocs/skin/frontend/package/theme");
        $this->assertFileExists("{$this->projectRoot}/htdocs/skin/frontend/package/theme/images/catalog");
        $this->assertTrue(is_link("{$this->projectRoot}/htdocs/skin/frontend/package/theme/images/catalog"));
        $this->assertTrue(file_exists("{$this->projectRoot}/htdocs/skin/frontend/package/theme/images/catalog/image1.jpg"));
        $this->assertTrue(file_exists("{$this->projectRoot}/htdocs/skin/frontend/package/theme/images/catalog/image2.jpg"));
    }

    public function testCurrentSymlinksAreUnlinked()
    {
        $this->composer->setPackage($this->createRootPackageWithOneMap());
        $package = $this->createStaticPackage('some/static', [], true, false, true);
        $this->localRepository->addPackage($package);

        $packageLocation = $this->projectRoot . "/vendor/" . $package->getName();
        mkdir($packageLocation . '/assets/testdir');
        mkdir("{$this->projectRoot}/htdocs/skin/frontend/package/theme/images/", 0777, true);

        symlink(
            $packageLocation . '/assets/testdir',
            "{$this->projectRoot}/htdocs/skin/frontend/package/theme/images/catalog"
        );

        $this->assertFileExists("{$this->projectRoot}/htdocs/skin/frontend/package/theme/images/catalog");
        $this->assertTrue(is_link("{$this->projectRoot}/htdocs/skin/frontend/package/theme/images/catalog"));
        $this->assertEquals(
            $packageLocation . '/assets/testdir',
            readLink("{$this->projectRoot}/htdocs/skin/frontend/package/theme/images/catalog")
        );

        $event = new CommandEvent('event', $this->composer, $this->io);
        $this->plugin->symlinkStatics($event);

        $this->assertFileExists("{$this->projectRoot}/htdocs/skin/frontend/package/theme/images/catalog");
        $this->assertTrue(is_link("{$this->projectRoot}/htdocs/skin/frontend/package/theme/images/catalog"));
        $this->assertEquals(
            $packageLocation . "/assets/images/catalog",
            readLink("{$this->projectRoot}/htdocs/skin/frontend/package/theme/images/catalog")
        );
    }

    public function testFilesAndFolderErrorWithoutDestinationSet()
    {
        $this->composer->setPackage($this->createRootPackageWithOneMap());

        $package = $this->createStaticPackage();

        $this->addStandardFilesNoDest($package);
        $this->localRepository->addPackage($package);

        $message = '<error>Full path is required for: "assets/images/catalog" </error>';

        $this->io
            ->expects($this->once())
            ->method('write')
            ->with($message);

        $event = new CommandEvent('event', $this->composer, $this->io);
        $this->plugin->symlinkStatics($event);

        $this->assertFileExists("{$this->projectRoot}/htdocs/skin/frontend/package/theme");
        $this->assertFileNotExists("{$this->projectRoot}/htdocs/skin/frontend/package/theme/image1.jpg");
        $this->assertFileNotExists("{$this->projectRoot}/htdocs/skin/frontend/package/theme/image2.jpg");
        $this->assertFileNotExists("{$this->projectRoot}/htdocs/skin/frontend/package/theme/image3.jpg");
    }

    public function testAssetSymLinkFailsIfAlreadyExistButNotSymLink()
    {
        $this->composer->setPackage($this->createRootPackageWithOneMap());
        $event = new CommandEvent('event', $this->composer, $this->io);

        $this->localRepository->addPackage($this->createStaticPackage());

        mkdir("{$this->projectRoot}/htdocs/skin/frontend/package/theme", 0777, true);
        touch("{$this->projectRoot}/htdocs/skin/frontend/package/theme/assets");

        $message = sprintf('<error>Your static path: "%s/htdocs/skin/frontend/package/theme/assets" is currently not a symlink, please remove first </error>', $this->projectRoot);

        $this->io
            ->expects($this->once())
            ->method('write')
            ->with($message);

        $this->plugin->symlinkStatics($event);
        $this->assertTrue(is_file("{$this->projectRoot}/htdocs/skin/frontend/package/theme/assets"));
    }

    public function testErrorIsReportedIfStaticPackageMissingSpecifiedSource()
    {
        $this->composer->setPackage($this->createRootPackageWithOneMap());
        $event = new CommandEvent('event', $this->composer, $this->io);

        $this->localRepository->addPackage($this->createStaticPackage('some/static', [], false));

        $message = '<error>The static package does not contain directory: "assets" </error>';

        $this->io
            ->expects($this->once())
            ->method('write')
            ->with($message);

        $this->plugin->symlinkStatics($event);
    }

    public function tearDown()
    {
        $dir = sys_get_temp_dir() . "/static-merge-test";

        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(
                $dir,
                \RecursiveDirectoryIterator::SKIP_DOTS
            ),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($files as $file) {

            if ($file->isLink() || !$file->isDir()) {
                unlink($file->getPathname());
            } else {
                rmdir($file->getPathname());
            }
        }

        rmdir($dir);
    }
}
