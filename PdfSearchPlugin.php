<?php
/**
 * Make PDF texts searchable by Omeka.
 * 
 * Omeka's native search only searches on the item level, so any text extracted 
 * from PDF files must exist in an element text assigned to an item. This plugin 
 * does this by inserting PDF text into an item's PDF Search::Text element. 
 * Because there is no reliable way to relate a file to its PDF text, the plugin 
 * refreshes the text every time an item is saved or a file is deleted.
 */
class PdfSearchPlugin extends Omeka_Plugin_Abstract
{
    const ELEMENT_SET_NAME = 'PDF Search';
    const ELEMENT_NAME = 'Text';
    
    protected $_hooks = array('install', 'uninstall', 'after_save_item', 
                              'after_delete_file');
    
    protected $_pdfMimeTypes = array('application/pdf', 'application/x-pdf', 
                                     'application/acrobat', 'text/x-pdf', 
                                     'text/pdf', 'applications/vnd.pdf');
    
    /**
     * Install the plugin.
     */
    public function hookInstall()
    {
        // Don't install if a PDF Search element set already exists.
        if ($this->_db->getTable('ElementSet')->findByName(self::ELEMENT_SET_NAME)) {
            throw new Exception('An element set by the name "' . self::ELEMENT_SET_NAME . '" already exists. You must delete that element set to install this plugin.');
        }
        
        // Don't install if the pdftotext command doesn't exist.
        // See: http://stackoverflow.com/questions/592620/check-if-a-program-exists-from-a-bash-script
        $output = (int) shell_exec('hash pdftotext 2>&- || echo -1');
        if (-1 == $output) {
            throw new Exception('The pdftotext command-line utility is not installed. pdftotext must be installed to install this plugin.');
        }
        
        // Insert the element set.
        $elementSetMetadata = array('name' => self::ELEMENT_SET_NAME, 
                                    'description' => 'This element set enables searching on PDF files.');
        $elements = array(array('name' => self::ELEMENT_NAME, 
                                'description' => 'Text extracted from PDF files belonging to this item.'));
        insert_element_set($elementSetMetadata, $elements);
    }
    
    /**
     * Uninstall the plugin.
     */
    public function hookUninstall()
    {
        // Delete the PDF Search element set.
        $this->_db->getTable('ElementSet')->findByName(self::ELEMENT_SET_NAME)->delete();
    }
    
    /**
     * Refresh PDF texts to account for an item save.
     */
    public function hookAfterSaveItem($item)
    {
        $this->_saveText($item);
    }
    
    /**
     * Refresh PDF texts to account for a file delete.
     */
    public function hookAfterDeleteFile($file)
    {
        $item = $file->getItem();
        $this->_saveText($item);
    }
    
    /**
     * Extract texts from PDF files and save them to the parent item.
     */
    protected function _saveText(Item $item)
    {
        $element = $item->getElementByNameAndSetName(self::ELEMENT_NAME, self::ELEMENT_SET_NAME);
        $recordTypeId = $this->_db->getTable('RecordType')->findIdFromName('item');
        
        // Delete PDF Search element texts. This is not needed when saving an 
        // item because that process automatically deletes all element texts 
        // belonging to the item. Deleting a file does not automatically delete 
        // element texts.
        $item->deleteElementTextsByElementId(array($element->id));
        
        // Iterate the files.
        foreach ($item->Files as $file) {
            
            // Ignore all other file types.
            if (!in_array($file->mime_browser, $this->_pdfMimeTypes)) {
                continue;
            }
            
            // Extract the text.
            $path = escapeshellarg(FILES_DIR . '/' . $file->archive_filename);
            $cmd = "pdftotext $path -";
            $text = shell_exec($cmd);
            
            // Save the text.
            $textRecord = new ElementText;
            $textRecord->record_id = $item->id;
            $textRecord->element_id = $element->id;
            $textRecord->record_type_id = $recordTypeId;
            $textRecord->text = $text;
            $textRecord->html = false;
            $textRecord->save();
        }
    }
    
    /**
     * Disable the PDF Search Text form element.
     * 
     * There are no circumstances where editing PDF Search Text is needed since 
     * the plugin overwrites any form submitted data. Form elements with the 
     * disabled attribute will not be submitted.
     */
    public static function disablePdfSearchText($html, $inputNameStem, $value)
    {
        ob_start();
?>
<textarea name="<?php echo $inputNameStem; ?>[text]" class="textinput" rows="15" cols="50" disabled><?php echo $value; ?></textarea>
<?php
        return ob_get_clean();
    }
}
