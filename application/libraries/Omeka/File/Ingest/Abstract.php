<?php
/**
 * @version $Id$
 * @copyright Center for History and New Media, 2009
 * @license http://www.gnu.org/licenses/gpl-3.0.txt
 * @package Omeka
 **/

/**
 * An abstract class that handles ingesting files into the Omeka archive and
 * database.  
 * 
 * Specific responsibilities handled by this class:
 *      * Parsing/validating arbitrary inputs that somehow identify the files to 
 * be ingested.
 *      * Iterating through the parsed file information, validating, and 
 * transferring each file to the Omeka archive.
 *      * Inserting a new record into the files table that corresponds to the
 * transferred file's metadata.
 *      * Returning a collection of the records associated with the ingested
 * files.
 *
 * Typical usage is via the factory() method:
 * 
 * $ingest = Omeka_File_Ingest_Abstract::factory('Url', $item);
 * $fileRecords = $ingest->ingest('http://www.example.com');
 *      
 * @see InsertItemHelper::addFiles()
 * @package Omeka
 * @copyright Center for History and New Media, 2009
 **/
abstract class Omeka_File_Ingest_Abstract
{
    /**
     * @var string Corresponds to the archive/ subdirectory where files are stored.  
     */
    protected static $_archiveDirectory = FILES_DIR;
    
    /**
     * @var Item
     */
    protected $_item;
    
    /**
     * @var array Set of arbitrary options to use when ingesting files.
     */
    protected $_options = array();
    
    /**
     * Set the item to use as a target when ingesting files.
     * 
     * @param Item $item
     * @return void
     **/        
    public function setItem(Item $item)
    {
        $this->_item = $item;
    }
    
    /**
     * Factory to retrieve Omeka_File_Ingest_* instances.
     * 
     * @param string
     * @return Omeka_File_Ingest_Abstract
     **/
    final public function factory($adapterName, $item, $options = array())
    {
        $className = 'Omeka_File_Ingest_' . $adapterName;
        if (class_exists($className, true)) {
            $instance = new $className;
            $instance->setItem($item);
            $instance->setOptions($options);
            return $instance;
        } else {
            throw new Exception('Could not load ' . $className);
        }
    }
    
    /**
     * Retrieve the original filename of the file.
     * 
     * @param array
     * @return string
     **/
    abstract protected function _getOriginalFilename($fileInfo);
    
    /**
     * Transfer the file to the archive.
     * 
     * @param array $fileInfo
     * @param string $originalFilename
     * @return string Real path to the transferred file.
     **/
    abstract protected function _transferFile($fileInfo, $originalFilename);
    
    /**
     * Determine whether or not the file is valid.
     * 
     * FIXME: Refactor.
     * @param array $fileInfo
     * @return boolean
     **/
    abstract protected function _fileIsValid($fileInfo);
    
    /**
     * Ingest classes receive arbitrary information.  This method needs to
     * parse that information into an iterable array so that multiple files
     * can be ingested from a single identifier.
     * 
     * Example use case is Omeka_File_Ingest_Upload.
     * 
     * @internal Formerly known as setFiles()
     * @param mixed $fileInfo
     * @return array
     **/
    abstract protected function _parseFileInfo($files);
    
    /**
     * Set options for ingesting files.
     * 
     * @param array $options Available options include:  
     *      'ignore_invalid_files' => boolean False by default.  Determine 
     * whether or not to throw exceptions when a file is not valid.  This can 
     * be based on a number of factors:  whether or not the original identifier
     * is valid (i.e. a valid URL), whether or not the file itself is valid
     * (i.e. invalid file extension), or whether the basic algorithm for 
     * ingesting the file fails (i.e., files cannot be transferred because the
     * archive/ directory is not writeable).  
     * 
     * This option is primarily useful for skipping known invalid files when 
     * ingesting large data sets.
     * @return void
     **/        
    public function setOptions($options)
    {
        $this->_options = $options;
        
         // Set the default options.
        if (!array_key_exists('ignore_invalid_files', $options)) {
            $this->_options['ignore_invalid_files'] = false;
        }
    }
    
    /**
     * Ingest based on arbitrary file identifier info.
     * 
     * @param mixed $fileInfo An arbitrary input (array, string, object, etc.)
     * that corresponds to one or more files to be ingested into Omeka.  
     * 
     * If this is an array that has a 'metadata' key, that should be an array
     * representing element text metadata to assign to the file.  See 
     * ActsAsElementText::addElementTextsByArray() for more details.
     * @return array Ingested file records.
     **/
    final public function ingest($fileInfo)
    {
        $fileInfoArray = $this->_parseFileInfo($fileInfo);
        
        // Iterate the files.
        $fileObjs = array();
        foreach ($fileInfoArray as $file) {            
            
            // If the file is invalid, throw an error or continue to the next file.
            if (!$this->_isValid($file)) {
                continue;
            }

            // This becomes the file's identifier (stored in the 
            // 'original_filename' column and used to derive the archival filename).
            $originalFileName = $this->_getOriginalFilename($file);
                        
            $fileDestinationPath = $this->_transferFile($file, $originalFileName);
            
            // Create the file object.
            if ($fileDestinationPath) {
                $fileObjs[] = $this->_createFile($fileDestinationPath, $originalFileName, $file['metadata']);
            }
        }
        return $fileObjs;
    }
    
    /**
     * Check to see whether or not the file is valid.
     * 
     * @return boolean Return false if we are ignoring invalid files and an 
     * exception was thrown from one of the adapter classes.  
     **/
    private function _isValid($fileInfo)
    {
        $ignore = $this->_options['ignore_invalid_files'];
        
        // If we have set the ignore flag, suppress all exceptions that are 
        // thrown from the adapter classes.
        try {
            $this->_fileIsValid($fileInfo);
        } catch (Exception $e) {
            if (!$ignore) {
                throw $e;
            }
            return false;
        }
        
        return true;
    }
    
    /**
     * Insert a File record corresponding to an ingested file and its metadata.
     * 
     * @param string $newFilePath Path to the file within Omeka's archive.
     * @param string $oldFilename The original filename for the file.  This will
     * usually be displayed to the end user.
     * @param array|null $elementMetadata See ActsAsElementText::addElementTextsByArray()
     * for more information about the format of this array.
     * @uses ActsAsElementText::addElementTextsByArray()
     * @return File
     **/        
    private function _createFile($newFilePath, $oldFilename, $elementMetadata = array())
    {
        $file = new File;
        try {
            $file->original_filename = $oldFilename;
            $file->item_id = $this->_item->id;
            
            $file->setDefaults($newFilePath);
            
            if ($elementMetadata) {
                $file->addElementTextsByArray($elementMetadata);
            }
            
            $file->forceSave();
            
            fire_plugin_hook('after_upload_file', $file, $this->_item);
            
        } catch(Exception $e) {
            if (!$file->exists()) {
                $file->unlinkFile();
            }
            throw $e;
        }
        return $file;
    }
    
    /**
     * Retrieve the destination path for the file to be transferred.
     * 
     * This will generate an archival filename in order to prevent naming 
     * conflicts between ingested files.
     * 
     * This should be used as necessary by Omeka_File_Ingest_Abstract 
     * implementations in order to determine where to transfer any given file.
     * 
     * @param string $fromFilename The filename from which to derive the 
     * archival filename. 
     * @return string
     **/    
    protected function _getDestination($fromFilename)
    {
        $filter = new Omeka_Filter_Filename;
        $filename = $filter->renameFileForArchive($fromFilename);
        if (!is_writable(self::$_archiveDirectory)) {
            throw new Exception('Cannot write to the following directory: "'
                              . self::$_archiveDirectory . '"!');
        }
        return self::$_archiveDirectory . DIRECTORY_SEPARATOR . $filename;
    }
}
