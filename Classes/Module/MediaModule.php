<?php
namespace Fab\Media\Module;

/*
 * This file is part of the Fab/Media project under GPLv2 or later.
 *
 * For the full copyright and license information, please read the
 * LICENSE.md file that was distributed with this source code.
 */

use Fab\Media\FileUpload\UploadedFileInterface;
use Fab\Media\Utility\SessionUtility;
use Fab\Vidi\Service\DataService;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Resource\File;
use TYPO3\CMS\Core\Resource\Folder;
use TYPO3\CMS\Core\Resource\ResourceFactory;
use TYPO3\CMS\Core\Resource\ResourceStorage;
use TYPO3\CMS\Core\Resource\StorageRepository;
use TYPO3\CMS\Core\SingletonInterface;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Class for retrieving information about the Media module.
 */
class MediaModule implements SingletonInterface
{

    /**
     * @var string
     */
    const SIGNATURE = 'user_MediaM1';

    /**
     * @var string
     */
    const PARAMETER_PREFIX = 'tx_media_user_mediam1';

    /**
     * @var ResourceStorage
     */
    protected $currentStorage;

    /**
     * @return string
     */
    static public function getSignature()
    {
        return self::SIGNATURE;
    }

    /**
     * @return string
     */
    static public function getParameterPrefix()
    {
        return self::PARAMETER_PREFIX;
    }

    /**
     * Return all storage allowed for the Backend User.
     *
     * @throws \RuntimeException
     * @return ResourceStorage[]
     */
    public function getAllowedStorages()
    {

        $storages = $this->getBackendUser()->getFileStorages();
        if (empty($storages)) {
            throw new \RuntimeException('No storage is accessible for the current BE User. Forgotten to define a mount point for this BE User?', 1380801970);
        }
        return $storages;
    }

    /**
     * Returns the current file storage in use.
     *
     * @return ResourceStorage
     */
    public function getCurrentStorage()
    {
        if (is_null($this->currentStorage)) {

            $storageIdentifier = $this->getStorageIdentifierFromSessionOrArguments();

            if ($storageIdentifier > 0) {
                $currentStorage = GeneralUtility::makeInstance(ResourceFactory::class)->getStorageObject($storageIdentifier);
            } else {

                // We differentiate the cases whether the User is admin or not.
                if ($this->getBackendUser()->isAdmin()) {

                    $currentStorage = GeneralUtility::makeInstance(ResourceFactory::class)->getDefaultStorage();

                    // Not default storage has been flagged in "sys_file_storage".
                    // Fallback approach: take the first storage as the current.
                    if (!$currentStorage) {
                        /** @var $storageRepository StorageRepository */
                        $storageRepository = GeneralUtility::makeInstance(\TYPO3\CMS\Core\Resource\StorageRepository::class);

                        $storages = $storageRepository->findAll();
                        $currentStorage = current($storages);
                    }
                } else {
                    $fileMounts = $this->getBackendUser()->getFileMountRecords();
                    $firstFileMount = current($fileMounts);
                    $currentStorage = GeneralUtility::makeInstance(ResourceFactory::class)->getStorageObject($firstFileMount['base']);
                }
            }

            $this->currentStorage = $currentStorage;
        }
        return $this->currentStorage;
    }

    /**
     * Retrieve a possible storage identifier from the session or from the arguments.
     *
     * @return int
     */
    protected function getStorageIdentifierFromSessionOrArguments()
    {

        // Default value
        $storageIdentifier = 0;

        // Get last selected storage from User settings
        if (SessionUtility::getInstance()->get('lastSelectedStorage') > 0) {
            $storageIdentifier = SessionUtility::getInstance()->get('lastSelectedStorage');
        }

        $argumentPrefix = $this->getModuleLoader()->getParameterPrefix();
        $arguments = GeneralUtility::_GET($argumentPrefix);

        // Override selected storage from the session if GET argument "storage" is detected.
        if (!empty($arguments['storage']) && (int)$arguments['storage'] > 0) {
            $storageIdentifier = (int)$arguments['storage'];

            // Save state
            SessionUtility::getInstance()->set('lastSelectedStorage', $storageIdentifier);
        }

        return (int)$storageIdentifier;
    }

    /**
     * Return the combined parameter from the URL.
     *
     * @return string
     */
    public function getCombinedIdentifier()
    {

        // Fetch possible combined identifier.
        $combinedIdentifier = GeneralUtility::_GET('id');

        if ($combinedIdentifier) {

            // Fix a bug at the Core level: the "id" parameter is encoded again when translating file.
            // Add a loop to decode maximum 999 time!
            $semaphore = 0;
            $semaphoreLimit = 999;
            while (!$this->isWellDecoded($combinedIdentifier) && $semaphore < $semaphoreLimit) {
                $combinedIdentifier = urldecode($combinedIdentifier);
                $semaphore++;
            }
        }

        return $combinedIdentifier;
    }

    /**
     * @param $combinedIdentifier
     * @return bool
     */
    protected function isWellDecoded($combinedIdentifier)
    {
        return preg_match('/.*:.*/', $combinedIdentifier);
    }

    /**
     * @return Folder
     */
    public function getFirstAvailableFolder()
    {

        // Take the first object of the first storage.
        $storages = $this->getBackendUser()->getFileStorages();
        $storage = reset($storages);
        if ($storage) {
            $folder = $storage->getRootLevelFolder();
        } else {
            throw new \RuntimeException('Could not find any folder to be displayed.', 1444665954);
        }
        return $folder;
    }

    /**
     * @return Folder
     */
    public function getCurrentFolder()
    {

        $combinedIdentifier = $this->getCombinedIdentifier();

        if ($combinedIdentifier) {
            $folder = $this->getFolderForCombinedIdentifier($combinedIdentifier);
        } else {
            $folder = $this->getFirstAvailableFolder();
        }

        return $folder;
    }

    /**
     * @param string $combinedIdentifier
     * @return Folder
     */
    public function getFolderForCombinedIdentifier($combinedIdentifier)
    {

        // Code taken from FileListController.php
        $storage = GeneralUtility::makeInstance(ResourceFactory::class)->getStorageObjectFromCombinedIdentifier($combinedIdentifier);
        $identifier = substr($combinedIdentifier, strpos($combinedIdentifier, ':') + 1);
        if (!$storage->hasFolder($identifier)) {
            $identifier = $storage->getFolderIdentifierFromFileIdentifier($identifier);
        }

        // Retrieve the folder object.
        $folder = GeneralUtility::makeInstance(ResourceFactory::class)->getFolderObjectFromCombinedIdentifier($storage->getUid() . ':' . $identifier);

        // Disallow the rendering of the processing folder (e.g. could be called manually)
        // and all folders without any defined storage
        if ($folder && ($folder->getStorage()->getUid() == 0 || trim($folder->getStorage()->getProcessingFolder()->getIdentifier(), '/') === trim($folder->getIdentifier(), '/'))) {
            $storage = GeneralUtility::makeInstance(ResourceFactory::class)->getStorageObjectFromCombinedIdentifier($combinedIdentifier);
            $folder = $storage->getRootLevelFolder();
        }

        return $folder;
    }

    /**
     * Tell whether the Folder Tree is display or not.
     *
     * @return bool
     */
    public function hasFolderTree()
    {
        $configuration = $this->getModuleConfiguration();
        return (bool)$configuration['has_folder_tree'];
    }

    /**
     * Tell whether the sub-folders must be included when browsing.
     *
     * @return bool
     */
    public function hasRecursiveSelection()
    {

        $parameterPrefix = $this->getModuleLoader()->getParameterPrefix();
        $parameters = GeneralUtility::_GET($parameterPrefix);

        $hasRecursiveSelection = true;
        if (isset($parameters['hasRecursiveSelection'])) {
            $hasRecursiveSelection = (bool)$parameters['hasRecursiveSelection'];
        }

        return $hasRecursiveSelection;
    }

    /**
     * Return the target folder for the uploaded file.
     *
     * @param UploadedFileInterface $uploadedFile
     * @param ResourceStorage $storage
     * @return \TYPO3\CMS\Core\Resource\Folder
     */
    public function getTargetFolderForUploadedFile(UploadedFileInterface $uploadedFile, ResourceStorage $storage)
    {

        // default is the root level
        $folder = $storage->getRootLevelFolder(); // get the root folder by default

        $userUploadFolderNotFound = true;
        // Get user upload folder only if configured in user TSconfig.
        $userUploadFolder = $this->getBackendUser()->getTSConfig()['options.']['defaultUploadFolder'];
        if ($userUploadFolder) {
            $folder = GeneralUtility::makeInstance(ResourceFactory::class)->getFolderObjectFromCombinedIdentifier($userUploadFolder);
            $userUploadFolderNotFound = false;
        }

        // Get a possible mount point coming from the storage record.
        $storageRecord = $storage->getStorageRecord();
        $mountPointIdentifier = $storageRecord['mount_point_file_type_' . $uploadedFile->getType()];
        // Only use the defined folder for the uploaded file type if the user has no configured upload folder.
        if ($userUploadFolderNotFound && $mountPointIdentifier > 0) {

            // We don't have a Mount Point repository in FAL, so query the database directly.
            $record = $this->getDataService()->getRecord('sys_filemounts', ['uid' => $mountPointIdentifier]);

            if (!empty($record['path'])) {
                $folder = $storage->getFolder($record['path']);
            }
        }
        return $folder;
    }

    /**
     * Return a new target folder when moving file from one storage to another.
     *
     * @param ResourceStorage $storage
     * @param File $file
     * @return \TYPO3\CMS\Core\Resource\Folder
     */
    public function getDefaultFolderInStorage(ResourceStorage $storage, File $file)
    {

        // default is the root level
        $folder = $storage->getRootLevelFolder();

        // Retrieve storage record and a possible configured mount point.
        $storageRecord = $storage->getStorageRecord();
        $mountPointIdentifier = $storageRecord['mount_point_file_type_' . $file->getType()];

        if ($mountPointIdentifier > 0) {

            // We don't have a Mount Point repository in FAL, so query the database directly.
            $record = $this->getDataService()->getRecord('sys_filemounts', ['uid' => $mountPointIdentifier]);
            if (!empty($record['path'])) {
                $folder = $storage->getFolder($record['path']);
            }
        }
        return $folder;
    }

    /**
     * @return array
     */
    protected function getModuleConfiguration()
    {
        return GeneralUtility::makeInstance(ExtensionConfiguration::class)->get('media');
    }

    /**
     * @return object|DataService
     */
    protected function getDataService(): DataService
    {
        return GeneralUtility::makeInstance(DataService::class);
    }

    /**
     * Returns an instance of the current Backend User.
     *
     * @return \TYPO3\CMS\Core\Authentication\BackendUserAuthentication
     */
    protected function getBackendUser()
    {
        return $GLOBALS['BE_USER'];
    }

    /**
     * Return the module loader.
     *
     * @return \Fab\Vidi\Module\ModuleLoader|object
     */
    protected function getModuleLoader()
    {
        return GeneralUtility::makeInstance(\Fab\Vidi\Module\ModuleLoader::class);
    }

}