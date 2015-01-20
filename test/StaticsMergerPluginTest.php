<?php

namespace Jh\StaticsMergerTest {

    use Composer\Package\Package;
    use Composer\Package\PackageInterface;
    use Composer\Package\RootPackage;
    use Composer\Repository\RepositoryManager;
    use Composer\Repository\WritableArrayRepository;
    use Composer\Script\CommandEvent;
    use Jh\StaticsMerger\StaticsMergerPlugin;
    use Composer\Test\TestCase;
    use Composer\Composer;
    use Composer\Config;
    use Composer\Script\ScriptEvents;
    use ReflectionObject;

    /**
     * Class StaticsMergerPluginTest
     *
     * @author Aydin Hassan <aydin@hotmail.co.uk>
     */
    class StaticsMergerPluginTest extends \PHPUnit_Framework_TestCase
    {
        public static $throwSymlinkException = false;
        protected $plugin;
        protected $composer;
        protected $config;
        protected $io;
        protected $repoManager;
        protected $localRepository;
        protected $projectRoot;

        public function setUp()
        {
            $this->plugin   = new StaticsMergerPlugin();
            $this->config   = new Config();
            $this->composer = new Composer();
            $this->composer->setConfig($this->config);

            $root = $this->createFolderStructure();
            chdir($root);

            $this->config->merge(array(
                'config' => array(
                    'vendor-dir' => $this->projectRoot . "/vendor",
                    'bin-dir'    => $this->projectRoot . "/vendor/bin",
                ),
            ));

            $this->io               = $this->getMock('Composer\IO\IOInterface');
            $this->repoManager      = new RepositoryManager($this->io, $this->config);
            $this->localRepository  = new WritableArrayRepository();
            $this->composer->setRepositoryManager($this->repoManager);
            $this->repoManager->setLocalRepository($this->localRepository);
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

        /**
         * @return string
         */
        private function createFolderStructure()
        {
            $sysDir = realpath(sys_get_temp_dir());

            $htdocs = $sysDir . "/static-merge-test/htdocs";

            if (!is_dir($htdocs)) {
                mkdir($htdocs, 0777, true);
            }

            $vendor = $sysDir . "/static-merge-test/vendor";
            if (!is_dir($vendor)) {
                mkdir($vendor);
            }

            $bin = $sysDir . "/static-merge-test/vendor/bin";
            if (!is_dir($bin)) {
                mkdir($bin);
            }

            $this->projectRoot = $sysDir . "/static-merge-test";

            return $this->projectRoot;
        }

        /**
         * @param $includeMageDir
         * @return RootPackage
         */
        public function createRootPackage($includeMageDir = true)
        {
            $package = new RootPackage("root/package", "1.0.0", "root/package");

            if ($includeMageDir) {
                $extra = array(
                    'magento-root-dir' => 'htdocs'
                );

                $package->setExtra($extra);
            }

            return $package;
        }

        public function activatePlugin()
        {
            $this->plugin->activate($this->composer, $this->io);
        }

        protected function addSingleStaticMap()
        {
            $extra = array_merge_recursive($this->composer->getPackage()->getExtra(), array(
                'static-map'       => array(
                    'some/static' => 'package/theme'
                ),
            ));

            $this->composer->getPackage()->setExtra($extra);
        }

        public function createAndActivatePackage($includeMageDir = true, $addSingleMap = true)
        {
            $this->composer->setPackage($this->createRootPackage($includeMageDir));
            if ($addSingleMap) {
                $this->addSingleStaticMap();
            }
            $this->activatePlugin();
        }

        /**
         * @param string $name
         * @param array  $extra
         * @param bool   $createAssets
         * @param bool   $addGlob
         * @param bool   $addFiles
         * @return Package
         */
        public function createStaticPackage(
            $name = 'some/static',
            $extra = array(),
            $createAssets = true,
            $addGlob = false,
            $addFiles = false
        )
        {
            // Include assets path on all tests
            $extra = array_merge_recursive(
                $extra,
                array(
                    'files' => array(
                        array(
                            'src'  => 'assets',
                            'dest' => 'assets'
                        )
                    )
                )
            );

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

            $extra = array_merge_recursive(
                $package->getExtra(),
                array(
                    'files' => array(
                        array(
                            'src'  => 'favicon*',
                            'dest' => ''
                        )
                    )
                )
            );

            $package->setExtra($extra);
        }

        public function addStandardFiles(PackageInterface $package)
        {
            $packageLocation = $this->projectRoot . "/vendor/" . $package->getName();
            mkdir($packageLocation . "/assets/images/catalog", 0777, true);
            touch($packageLocation . "/assets/images/catalog/image1.jpg");
            touch($packageLocation . "/assets/images/catalog/image2.jpg");

            $extra = array_merge_recursive(
                $package->getExtra(),
                array(
                    'files' => array(
                        array(
                            'src'  => 'assets/images/catalog',
                            'dest' => 'images/catalog'
                        )
                    )
                )
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
                array(
                    'files' => array(
                        array(
                            'src'  => 'assets/images/catalog',
                            'dest' => ''
                        ),
                        array(
                            'src'  => 'assets/image3.jpg',
                            'dest' => ''
                        )
                    )
                )
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
                array(
                    'files' => array(
                        array(
                            'src'  => 'assets/images/catalog/image*',
                            'dest' => 'images'
                        )
                    )
                )
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

            $this->activatePlugin();
        }

        public function testErrorIsPrintedIfMagentoRootNotSet()
        {
            $this->composer->setPackage($this->createRootPackage(false));
            $this->addSingleStaticMap();

            $this->io
                ->expects($this->once())
                ->method('write')
                ->with('<info>Magento root dir not defined</info>');

            $this->activatePlugin();
        }

        public function testSymLinkStaticsCorrectlySymLinksStaticFiles()
        {
            $this->createAndActivatePackage();
            $event = new CommandEvent('event', $this->composer, $this->io);

            $this->localRepository->addPackage($this->createStaticPackage());
            $this->plugin->symlinkStatics($event);

            $this->assertFileExists("{$this->projectRoot}/htdocs/skin/frontend/package/theme");
            $this->assertTrue(is_link("{$this->projectRoot}/htdocs/skin/frontend/package/theme/assets"));
        }

        public function testFileGlobAreAllCorrectlySymLinkedToRoot()
        {
            $this->createAndActivatePackage();
            $this->localRepository->addPackage(
                $this->createStaticPackage('some/static', array(), true, true)
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
        }

        public function testFileGlobAreAllCorrectlySymLinkedWithSetDest()
        {
            $this->createAndActivatePackage();

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
            $this->createAndActivatePackage();
            $this->localRepository->addPackage(
                $this->createStaticPackage('some/static', array(), true, false, true)
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
            $this->createAndActivatePackage();
            $package = $this->createStaticPackage('some/static', array(), true, false, true);
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
            $this->createAndActivatePackage();

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
            $this->createAndActivatePackage();
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
            $this->createAndActivatePackage();
            $event = new CommandEvent('event', $this->composer, $this->io);

            $this->localRepository->addPackage($this->createStaticPackage('some/static', array(), false));

            $message = '<error>The static package does not contain directory: "assets" </error>';

            $this->io
                ->expects($this->once())
                ->method('write')
                ->with($message);

            $this->plugin->symlinkStatics($event);
        }

        public function testSkipsNonStaticPackages()
        {
            $this->createAndActivatePackage();
            $nonStaticPackage = $this->createStaticPackage();
            $nonStaticPackage->setType('not-a-static');
            $this->localRepository->addPackage($nonStaticPackage);

            // Mock the plugin to assert processSymlink is never called
            $staticPlugin = $this->getMockBuilder('Jh\StaticsMerger\StaticsMergerPlugin')
                ->setMethods(array('processSymlink'))
                ->getMock();

            $staticPlugin
                ->expects($this->never())
                ->method('processSymlink');

            $event = new CommandEvent('event', $this->composer, $this->io);
            $staticPlugin->activate($this->composer, $this->io);
            $staticPlugin->symlinkStatics($event);
        }

        public function testWritesErrorWithNoFilePaths()
        {
            $this->createAndActivatePackage();
            $emptyPathPackage = $this->createStaticPackage();
            $emptyPathPackage->setExtra(array());
            $this->localRepository->addPackage($emptyPathPackage);

            $message = sprintf(
                '<error>%s requires at least one file mapping, has none!<error>',
                $emptyPathPackage->getPrettyName()
            );

            $this->io
                ->expects($this->once())
                ->method('write')
                ->with($message);

            $event = new CommandEvent('event', $this->composer, $this->io);
            $this->plugin->symlinkStatics($event);
        }

        public function testSubscribedToExpectedComposerEvents()
        {
            $this->assertSame(array(
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
            ), $this->plugin->getSubscribedEvents());
        }

        public function testSymlinkFail()
        {
            $this->createAndActivatePackage();
            $this->localRepository->addPackage($this->createStaticPackage());

            // Set symlink exception flag for function override
            static::$throwSymlinkException = true;

            $message = 'Failed to symlink';

            $this->io
                ->expects($this->once())
                ->method('write')
                ->with($this->stringContains($message));

            $event = new CommandEvent('event', $this->composer, $this->io);
            $this->plugin->symlinkStatics($event);

            // Set the flag back to prevent further exceptions
            static::$throwSymlinkException = false;
        }

        public function testStaticsCleanupCorrectlyRemovesDirs()
        {
            $this->createAndActivatePackage();
            $event = new CommandEvent('event', $this->composer, $this->io);

            $this->localRepository->addPackage($this->createStaticPackage());
            $this->plugin->symlinkStatics($event);

            $this->assertFileExists("{$this->projectRoot}/htdocs/skin/frontend/package/theme");
            $this->assertTrue(is_link("{$this->projectRoot}/htdocs/skin/frontend/package/theme/assets"));

            $this->plugin->staticsCleanup($event);

            $this->assertFileNotExists("{$this->projectRoot}/htdocs/skin/frontend/package/theme");
            $this->assertFileNotExists("{$this->projectRoot}/htdocs/skin/frontend/package");
        }

        public function testStaticsCleanupOutputOnException()
        {
            $this->createAndActivatePackage();
            $event = new CommandEvent('event', $this->composer, $this->io);

            $this->localRepository->addPackage($this->createStaticPackage());
            $this->plugin->symlinkStatics($event);

            $this->assertFileExists("{$this->projectRoot}/htdocs/skin/frontend/package/theme");
            $this->assertTrue(is_link("{$this->projectRoot}/htdocs/skin/frontend/package/theme/assets"));

            $filesystem = $this->getMockBuilder('Composer\Util\Filesystem')
                ->setMethods(array('removeDirectory'))
                ->getMock();
            
            $filesystem
                ->expects($this->once())
                ->method('removeDirectory')
                ->will($this->throwException(new \RuntimeException()));

            $this->io
                ->expects($this->once())
                ->method('write')
                ->with(sprintf(
                    "<error>Failed to remove some/static from %s/htdocs/skin/frontend/package</error>",
                    realpath(sys_get_temp_dir()) . "/static-merge-test"
                ));

            $refObject   = new ReflectionObject($this->plugin);
            $refProperty = $refObject->getProperty('filesystem');
            $refProperty->setAccessible(true);
            $refProperty->setValue($this->plugin, $filesystem);

            $this->plugin->staticsCleanup($event);

            $this->assertFileExists("{$this->projectRoot}/htdocs/skin/frontend/package/theme");
            $this->assertTrue(is_link("{$this->projectRoot}/htdocs/skin/frontend/package/theme/assets"));
        }
    }
}

// Override the Symlink function
// within the StaticsMerger namespace
// Allows testing the exception handling
namespace Jh\StaticsMerger {

    use Jh\StaticsMergerTest\StaticsMergerPluginTest;

    function symlink($target, $link)
    {
        if (StaticsMergerPluginTest::$throwSymlinkException) {
            throw new \ErrorException('Fail');
        }

        return \symlink($target, $link);
    }
}