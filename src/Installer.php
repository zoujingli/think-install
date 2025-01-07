<?php

// +----------------------------------------------------------------------
// | Plugin Installer for ThinkAdmin
// +----------------------------------------------------------------------
// | 版权所有 2014~2023 Anyon <zoujingli@qq.com>
// +----------------------------------------------------------------------
// | 官方网站: https://thinkadmin.top
// +----------------------------------------------------------------------
// | 免费声明 ( https://thinkadmin.top/disclaimer )
// | 开源协议 ( http://www.apache.org/licenses/LICENSE-2.0 )
// +----------------------------------------------------------------------
// | gitee 代码仓库：https://gitee.com/zoujingli/think-install
// | github 代码仓库：https://github.com/zoujingli/think-install
// +----------------------------------------------------------------------

declare(strict_types=1);

namespace think\admin\install;

use Composer\Installer\LibraryInstaller;
use Composer\Package\PackageInterface;
use Composer\Repository\InstalledRepositoryInterface;

class Installer extends LibraryInstaller
{
    public function install(InstalledRepositoryInterface $repo, PackageInterface $package)
    {
        return parent::install($repo, $package)->then(function () use ($package) {
            $this->installPlugin($package);
            if (($extra = $package->getExtra()) && !empty($extra['plugin']['event'])) {
                $path = $this->getInstallPath($package);
                foreach ((array)$extra['plugin']['event'] as $file => $class) {
                    if (is_string($class) && is_string($file)) {
                        class_exists($class) || is_file($file = "{$path}/{$file}") && include_once($file);
                        if (class_exists($class) && method_exists($class, 'onInstall')) {
                            $this->io->write("\r  > Exec Install Event Done: <info>{$class}::onInstall() </info> \033[K");
                            $class::onInstall();
                        } else {
                            $this->io->write("\r  > Exec Install Event Fail: <info>{$class}::onInstall() </info> \033[K");
                        }
                    }
                }
            }
        });
    }

    public function uninstall(InstalledRepositoryInterface $repo, PackageInterface $package)
    {
        if (($extra = $package->getExtra()) && !empty($extra['plugin']['event'])) {
            $path = $this->getInstallPath($package);
            foreach ((array)$extra['plugin']['event'] as $file => $class) {
                if (is_string($class) && is_string($file)) {
                    class_exists($class) || is_file($file = "{$path}/{$file}") && include_once($file);
                    if (class_exists($class) && method_exists($class, 'onRemove')) {
                        $this->io->write("\r  > Exec Remove Event Done: <info>{$class}::onRemove() </info> \033[K");
                        $class::onRemove();
                    } else {
                        $this->io->write("\r  > Exec Remove Event Fail: <info>{$class}::onRemove() </info> \033[K");
                    }
                }
            }
        }
        return parent::uninstall($repo, $package);
    }

    public function update(InstalledRepositoryInterface $repo, PackageInterface $initial, PackageInterface $target)
    {
        if (is_dir($this->getInstallPath($target))) {
            return parent::update($repo, $initial, $target)->then(function () use ($target) {
                $this->installPlugin($target);
            });
        } else {
            return $this->install($repo, $target);
        }
    }

    public function isInstalled(InstalledRepositoryInterface $repo, PackageInterface $package)
    {
        $extra = $package->getExtra();
        if (empty($extra['plugin']['clear'])) {
            return parent::isInstalled($repo, $package);
        } else {
            return true;
        }
    }

    public function getInstallPath(PackageInterface $package)
    {
        if ($this->composer->getPackage()->getType() === 'project') {
            $extra = $package->getExtra();
            if (!empty($extra['plugin']['path'])) {
                return $extra['plugin']['path'];
            }
        }
        return parent::getInstallPath($package);
    }

    private function installPlugin(PackageInterface $package)
    {
        if ($this->composer->getPackage()->getType() !== 'project') {
            return;
        }

        $extra = $package->getExtra();
        $installPath = $this->getInstallPath($package);
        $this->io->write("\r  > Exec Plugin <info>{$package->getPrettyName()} </info> \033[K");

        // 初始化，若文件存在不进行操作
        if (!empty($extra['plugin']['init'])) {
            foreach ((array)$extra['plugin']['init'] as $source => $target) {
                if (!is_file($target) && is_file($sfile = $installPath . DIRECTORY_SEPARATOR . $source)) {
                    $this->io->write("\r  > Init Source <info>{$source} </info>to <info>{$target} </info>");
                    file_exists(dirname($target)) || mkdir(dirname($target), 0755, true);
                    $this->filesystem->copy($sfile, $target);
                }
            }
        }

        // 复制替换，无论是否存在都进行替换
        if (!empty($extra['plugin']['copy'])) {
            foreach ((array)$extra['plugin']['copy'] as $source => $target) {

                // 是否为绝对复制模式
                $force = $target[0] === '!' && file_exists($target = substr($target, 1));

                // 源文件位置，若不存在直接跳过
                if (!file_exists($sfile = $installPath . DIRECTORY_SEPARATOR . $source)) continue;

                // 检查目标文件，若已经存在直接跳过
                if (is_file($sfile) && is_file($target) && md5_file($sfile) === md5_file($target)) continue;

                // 如果目标目录或其上级目录下存在 ignore 文件则跳过复制
                if (file_exists(dirname($target) . '/ignore') || file_exists(rtrim($target, '\\/') . "/ignore")) {
                    $this->io->write("\r  > Skip Copy <info>{$source} </info>to <info>{$target} </info>");
                    continue;
                }

                // 绝对复制时需要先删再写入
                if (($action = $force && file_exists($target) ? 'Push' : 'Copy') === 'Push') {
                    is_file($target) ? $this->filesystem->unlink($target) : $this->filesystem->removeDirectoryPhp($target);
                }

                // 执行复制操作，将原文件或目录复制到目标位置
                $this->io->write("\r  > {$action} Source <info>{$source} </info>to <info>{$target} </info>");
                file_exists(dirname($target)) || mkdir(dirname($target), 0755, true);
                $this->filesystem->copy($sfile, $target);
            }
        }

        // 非 vendor 目录，不执行 clear 操作
        if (stripos(realpath($installPath), $this->vendorDir) !== 0) {
            $this->io->write(sprintf("\r  > Skip Clear <info>%s </info>", realpath($installPath)));
            return;
        }

        // 清理当前库的所有文件及信息
        if (!empty($extra['plugin']['clear'])) try {
            $rootPath = dirname($this->vendorDir);
            $showPath = substr($installPath, strlen($rootPath) + 1);
            $this->io->write("\r  > Clear Vendor <info>{$showPath} </info>");
            $this->filesystem->removeDirectoryPhp($installPath);
        } catch (\Exception|\RuntimeException $exception) {
            $this->io->error("\r  > {$exception->getMessage()}");
        }
    }

    /**
     * @inheritDoc
     */
    public function supports($packageType)
    {
        return 'think-admin-plugin' === $packageType;
    }
}