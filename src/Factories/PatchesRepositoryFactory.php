<?php
/**
 * Copyright © Vaimo Group. All rights reserved.
 * See LICENSE_VAIMO.txt for license details.
 */
namespace Vaimo\ComposerPatches\Factories;

use Vaimo\ComposerPatches\Config as PluginConfig;
use Vaimo\ComposerPatches\Patch\DefinitionList\LoaderComponents;
use Vaimo\ComposerPatches\Patch\Definition\ExploderComponents;
use Vaimo\ComposerPatches\Patch\SourceLoaders;
use Vaimo\ComposerPatches\Package\ConfigExtractors;
use Vaimo\ComposerPatches\Patch;

class PatchesRepositoryFactory
{
    /**
     * @var \Composer\IO\IOInterface
     */
    private $io;

    /**
     * @param \Composer\IO\IOInterface $io
     */
    public function __construct(
        \Composer\IO\IOInterface $io
    ) {
        $this->io = $io;
    }

    public function create(\Composer\Composer $composer, PluginConfig $pluginConfig, $devMode = false) 
    {
        $packagesRepository = $composer->getRepositoryManager()->getLocalRepository();
        $installationManager = $composer->getInstallationManager();
        $rootPackage = $composer->getPackage();
        $composerConfig = clone $composer->getConfig();

        $extra = $composer->getPackage()->getExtra();
        $patcherConfig = $pluginConfig->getPatcherConfig();

        $composerConfig->merge(array(
            'config' => array('secure-http' => $patcherConfig[PluginConfig::PATCHER_SECURE_HTTP])
        ));

        $downloader = new \Composer\Util\RemoteFilesystem($this->io, $composerConfig);
        
        $packageInfoResolver = new \Vaimo\ComposerPatches\Package\InfoResolver($installationManager);
        
        $loaders = array(
            PluginConfig::LIST => new SourceLoaders\PatchList(),
            PluginConfig::FILE => new SourceLoaders\PatchesFile($installationManager)
        );

        if ($devMode) {
            $loaders = array_replace($loaders, array(
                PluginConfig::DEV_LIST => $loaders[PluginConfig::LIST],
                PluginConfig::DEV_FILE => $loaders[PluginConfig::FILE]
            ));
        }
        
        if ($pluginConfig->shouldPreferOwnerPackageConfig()) {
            $infoExtractor = new ConfigExtractors\VendorConfigExtractor($packageInfoResolver);
        } else {
            $infoExtractor = new ConfigExtractors\InstalledConfigExtractor();
        }

        $exploderComponents = array(
            new ExploderComponents\VersionConfigComponent(),
            new ExploderComponents\ComplexItemComponent(),
            new ExploderComponents\SequenceVersionConfigComponent(),
            new ExploderComponents\SequenceItemComponent(),
            new ExploderComponents\GroupVersionConfigComponent()
        );

        $definitionExploder = new Patch\Definition\Exploder($exploderComponents);
        $definitionNormalizer = new Patch\Definition\Normalizer();
        
        $listNormalizer = new Patch\ListNormalizer(
            $definitionExploder,
            $definitionNormalizer
        );
        
        $patchesCollector = new Patch\Collector(
            $listNormalizer,
            $infoExtractor,
            $loaders
        );
        
        if (isset($extra['excluded-patches']) && !isset($extra[PluginConfig::EXCLUDED_PATCHES])) {
            $extra[PluginConfig::EXCLUDED_PATCHES] = $extra['excluded-patches'];
        }
            
        $excludes = isset($extra[PluginConfig::EXCLUDED_PATCHES]) 
            ? $extra[PluginConfig::EXCLUDED_PATCHES]
            : array();
        
        $loaderComponents = array(
            new LoaderComponents\BundleComponent($rootPackage),
            $excludes ? new LoaderComponents\GlobalExcludeComponent($excludes) : false,
            new LoaderComponents\LocalExcludeComponent(),
            new LoaderComponents\CustomExcludeComponent($pluginConfig->getSkippedPackages()),
            new LoaderComponents\PathNormalizerComponent($packageInfoResolver),
            new LoaderComponents\ConstraintsComponent(),
            new LoaderComponents\DownloaderComponent($rootPackage, $downloader),
            new LoaderComponents\ValidatorComponent(),
            new LoaderComponents\TargetsResolverComponent($packageInfoResolver),
            new LoaderComponents\MergerComponent(),
            new LoaderComponents\SorterComponent()
        );
        
        $sourceConfig = $patcherConfig[PluginConfig::PATCHER_SOURCES];

        if (isset($sourceConfig['packages']) && isset($sourceConfig['vendors'])) {
            if (is_array($sourceConfig['packages']) && !is_array($sourceConfig['vendors'])) {
                $sourceConfig['vendors'] = false;
            } else if (is_array($sourceConfig['vendors']) && !is_array($sourceConfig['packages'])) {
                $sourceConfig['packages'] = false;
            } else if ($sourceConfig['packages'] === false || $sourceConfig['vendors'] === false) {
                $sourceConfig['packages'] = false;
                $sourceConfig['vendors'] = false;
            }
        } 

        $listSources = array(
            'project' => new \Vaimo\ComposerPatches\Sources\ProjectSource($rootPackage),
            'vendors' => new \Vaimo\ComposerPatches\Sources\VendorSource(
                isset($sourceConfig['vendors']) && is_array($sourceConfig['vendors']) 
                    ? $sourceConfig['vendors'] 
                    : array() 
            ),
            'packages' => new \Vaimo\ComposerPatches\Sources\PackageSource(
                isset($sourceConfig['packages']) && is_array($sourceConfig['packages'])
                    ? $sourceConfig['packages']
                    : array()
            )
        );
        
        $packagesCollector = new \Vaimo\ComposerPatches\Package\Collector($rootPackage);

        $definitionListLoader = new Patch\DefinitionList\Loader(
            $packagesCollector,
            $patchesCollector,
            array_filter($loaderComponents),
            array_intersect_key($listSources, array_filter($sourceConfig))
        );
        
        return new \Vaimo\ComposerPatches\Repositories\PatchesRepository(
            $packagesRepository,
            $packagesCollector,
            $definitionListLoader
        );
    }
}