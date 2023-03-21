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
            $this->copyStaticFiles($package);
            if (($extra = $package->getExtra()) && !empty($extra['think']['services'])) {
                foreach ((array)$extra['think']['services'] as $service) if (class_exists($service)) {
                    method_exists($service, 'onInstall') && $service::onInstall();
                }
            }
        });
    }

    public function uninstall(InstalledRepositoryInterface $repo, PackageInterface $package)
    {
        if (($extra = $package->getExtra()) && !empty($extra['think']['services'])) {
            foreach ((array)$extra['think']['services'] as $service) if (class_exists($service)) {
                method_exists($service, 'onRemove') && $service::onRemove();
                method_exists($service, 'onUninstall') && $service::onUninstall();
            }
        }
        return parent::uninstall($repo, $package);
    }

    public function update(InstalledRepositoryInterface $repo, PackageInterface $initial, PackageInterface $target)
    {
        if (is_dir($this->getInstallPath($target))) {
            return parent::update($repo, $initial, $target)->then(function () use ($target) {
                $this->copyStaticFiles($target);
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

    protected function copyStaticFiles(PackageInterface $package)
    {
        if ($this->composer->getPackage()->getType() === 'project') {
            $extra = $package->getExtra();
            $installPath = $this->getInstallPath($package);

            // 初始化，若文件存在不进行操作
            if (!empty($extra['plugin']['init'])) {
                foreach ((array)$extra['plugin']['init'] as $source => $target) {
                    if (!is_file($target) && is_file($sfile = $installPath . DIRECTORY_SEPARATOR . $source)) {
                        $this->io->write("  > Init <info>{$source} </info>to <info>{$target} </info>");
                        file_exists(dirname($target)) || mkdir(dirname($target), 0755, true);
                        $this->filesystem->copy($sfile, $target);
                    }
                }
            }

            // 复制替换，无论是否存在都进行替换
            if (!empty($extra['plugin']['copy'])) {
                foreach ((array)$extra['plugin']['copy'] as $source => $target) {

                    // 是否为绝对复制模式
                    $isforce = $target[0] === '!' && file_exists($target = substr($target, 1));

                    // 如果目标目录或其上级目录下存在 ignore 文件则跳过复制
                    if (file_exists(dirname($target) . '/ignore') || file_exists(rtrim($target, '\\/') . "/ignore")) {
                        $this->io->write("  > Ignore Copy <info>{$source} </info>to <info>{$target} </info>");
                        continue;
                    }

                    // 绝对复制时需要先删除目标文件或目录
                    if ($isforce && file_exists($target)) {
                        $this->io->write("  > Delete Target <info>{$target} </info>");
                        if (is_file($target)) {
                            $this->filesystem->unlink($target);
                        } else {
                            $this->filesystem->removeDirectoryPhp($target);
                        }
                    }

                    // 执行复制操作，将原文件或目录复制到目标位置
                    if (file_exists($sfile = $installPath . DIRECTORY_SEPARATOR . $source)) {
                        $type = is_file($sfile) ? "File" : "Directory";
                        $this->io->write("  > Copy {$type} <info>{$source} </info>to <info>{$target} </info>");
                        file_exists(dirname($target)) || mkdir(dirname($target), 0755, true);
                        $this->filesystem->copy($sfile, $target);
                    }
                }
            }

            // 清理当前库的所有文件及信息
            if (!empty($extra['plugin']['clear'])) {
                $rootPath = dirname($this->vendorDir);
                if (stripos($installPath, $rootPath) === 0) {
                    $showPath = substr($installPath, strlen($rootPath) + 1);
                } else {
                    $showPath = $installPath;
                }
                try {
                    $this->io->write("  > Clear Directory <info>{$showPath} </info>");
                    $this->filesystem->removeDirectoryPhp($installPath);
                } catch (\Exception|\RuntimeException $exception) {
                    $this->io->error("  > {$exception->getMessage()}");
                }
            }
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