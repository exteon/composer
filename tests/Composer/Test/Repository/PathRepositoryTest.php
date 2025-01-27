<?php declare(strict_types=1);

/*
 * This file is part of Composer.
 *
 * (c) Nils Adermann <naderman@naderman.de>
 *     Jordi Boggiano <j.boggiano@seld.be>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Composer\Test\Repository;

use Composer\Repository\PathRepository;
use Composer\Test\TestCase;
use Composer\Util\HttpDownloader;
use Composer\Util\Loop;
use Composer\Util\Platform;
use Composer\Util\ProcessExecutor;

class PathRepositoryTest extends TestCase
{
    public function testLoadPackageFromFileSystemWithIncorrectPath(): void
    {
        self::expectException('RuntimeException');

        $repositoryUrl = implode(DIRECTORY_SEPARATOR, array(__DIR__, 'Fixtures', 'path', 'missing'));
        $repository = $this->createPathRepo(array('url' => $repositoryUrl));
        $repository->getPackages();
    }

    public function testLoadPackageFromFileSystemWithVersion(): void
    {
        $repositoryUrl = implode(DIRECTORY_SEPARATOR, array(__DIR__, 'Fixtures', 'path', 'with-version'));
        $repository = $this->createPathRepo(array('url' => $repositoryUrl));
        $repository->getPackages();

        $this->assertSame(1, $repository->count());
        $this->assertTrue($repository->hasPackage($this->getPackage('test/path-versioned', '0.0.2')));
    }

    public function testLoadPackageFromFileSystemWithoutVersion(): void
    {
        $repositoryUrl = implode(DIRECTORY_SEPARATOR, array(__DIR__, 'Fixtures', 'path', 'without-version'));
        $repository = $this->createPathRepo(array('url' => $repositoryUrl));
        $packages = $repository->getPackages();

        $this->assertGreaterThanOrEqual(1, $repository->count());

        $package = $packages[0];
        $this->assertSame('test/path-unversioned', $package->getName());

        $packageVersion = $package->getVersion();
        $this->assertNotEmpty($packageVersion);
    }

    public function testLoadPackageFromFileSystemWithWildcard(): void
    {
        $repositoryUrl = implode(DIRECTORY_SEPARATOR, array(__DIR__, 'Fixtures', 'path', '*'));
        $repository = $this->createPathRepo(array('url' => $repositoryUrl));
        $packages = $repository->getPackages();
        $names = array();

        $this->assertGreaterThanOrEqual(2, $repository->count());

        $package = $packages[0];
        $names[] = $package->getName();

        $package = $packages[1];
        $names[] = $package->getName();

        sort($names);
        $this->assertEquals(array('test/path-unversioned', 'test/path-versioned'), $names);
    }

    public function testLoadPackageWithExplicitVersions(): void
    {
        $options = array(
            'versions' => array(
                'test/path-unversioned' => '4.3.2.1',
                'test/path-versioned' => '3.2.1.0',
            ),
        );
        $repositoryUrl = implode(DIRECTORY_SEPARATOR, array(__DIR__, 'Fixtures', 'path', '*'));
        $repository = $this->createPathRepo(array('url' => $repositoryUrl, 'options' => $options));
        $packages = $repository->getPackages();

        $versions = array();

        $this->assertEquals(2, $repository->count());

        $package = $packages[0];
        $versions[$package->getName()] = $package->getVersion();

        $package = $packages[1];
        $versions[$package->getName()] = $package->getVersion();

        ksort($versions);
        $this->assertSame(array('test/path-unversioned' => '4.3.2.1', 'test/path-versioned' => '3.2.1.0'), $versions);
    }

    /**
     * Verify relative repository URLs remain relative, see #4439
     */
    public function testUrlRemainsRelative(): void
    {
        // realpath() does not fully expand the paths
        // PHP Bug https://bugs.php.net/bug.php?id=72642
        $repositoryUrl = implode(DIRECTORY_SEPARATOR, array(realpath(realpath(__DIR__)), 'Fixtures', 'path', 'with-version'));
        // getcwd() not necessarily match __DIR__
        // PHP Bug https://bugs.php.net/bug.php?id=73797
        $relativeUrl = ltrim(substr($repositoryUrl, strlen(realpath(realpath(Platform::getCwd())))), DIRECTORY_SEPARATOR);

        $repository = $this->createPathRepo(array('url' => $relativeUrl));
        $packages = $repository->getPackages();

        $this->assertSame(1, $repository->count());

        $package = $packages[0];
        $this->assertSame('test/path-versioned', $package->getName());

        // Convert platform specific separators back to generic URL slashes
        $relativeUrl = str_replace(DIRECTORY_SEPARATOR, '/', $relativeUrl);
        $this->assertSame($relativeUrl, $package->getDistUrl());
    }

    public function testReferenceNone(): void
    {
        $options = array(
            'reference' => 'none',
        );
        $repositoryUrl = implode(DIRECTORY_SEPARATOR, array(__DIR__, 'Fixtures', 'path', '*'));
        $repository = $this->createPathRepo(array('url' => $repositoryUrl, 'options' => $options));
        $packages = $repository->getPackages();

        $this->assertGreaterThanOrEqual(2, $repository->count());

        foreach ($packages as $package) {
            $this->assertEquals($package->getDistReference(), null);
        }
    }

    public function testReferenceConfig(): void
    {
        $options = array(
            'reference' => 'config',
            'relative' => true,
        );
        $repositoryUrl = implode(DIRECTORY_SEPARATOR, array(__DIR__, 'Fixtures', 'path', '*'));
        $repository = $this->createPathRepo(array('url' => $repositoryUrl, 'options' => $options));
        $packages = $repository->getPackages();

        $this->assertGreaterThanOrEqual(2, $repository->count());

        foreach ($packages as $package) {
            $this->assertEquals(
                $package->getDistReference(),
                sha1(file_get_contents($package->getDistUrl() . '/composer.json') . serialize($options))
            );
        }
    }

    /**
     * @param array<mixed> $options
     */
    private function createPathRepo(array $options): PathRepository
    {
        $io = $this->getMockBuilder('Composer\IO\IOInterface')->getMock();

        $config = new \Composer\Config();
        $proc = new ProcessExecutor();
        $loop = new Loop(new HttpDownloader($io, $config), $proc);


        return new PathRepository($options, $io, $config, null, null, $proc);
    }
}
