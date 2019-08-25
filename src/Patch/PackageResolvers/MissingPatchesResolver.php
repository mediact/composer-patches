<?php
/**
 * Copyright © Vaimo Group. All rights reserved.
 * See LICENSE_VAIMO.txt for license details.
 */
namespace Vaimo\ComposerPatches\Patch\PackageResolvers;

class MissingPatchesResolver implements \Vaimo\ComposerPatches\Interfaces\PatchPackagesResolverInterface
{
    /**
     * @var \Vaimo\ComposerPatches\Utils\PackagePatchDataUtils
     */
    private $packagePatchDataUtils;

    /**
     * @var \Vaimo\ComposerPatches\Utils\PatchListUtils
     */
    private $patchListUtils;

    public function __construct()
    {
        $this->packagePatchDataUtils = new \Vaimo\ComposerPatches\Utils\PackagePatchDataUtils();
        $this->patchListUtils = new \Vaimo\ComposerPatches\Utils\PatchListUtils();
    }

    public function resolve(array $patches, array $repositoryState)
    {
        $patchDataUtils = $this->packagePatchDataUtils;

        return $this->patchListUtils->compareLists(
            $patches,
            $repositoryState,
            function ($packagePatches, $packageState) use ($patchDataUtils) {
                return $patchDataUtils->shouldReinstall(
                    $packageState,
                    $packagePatches
                );
            }
        );
    }
}
