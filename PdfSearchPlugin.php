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
    
    protected $_hooks = array('install', 'uninstall', 'config_form', 'config', 
                              'after_save_item', 'after_delete_file');
    
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
     * Display the plugin config form.
     */
    public function hookConfigForm()
    {
?>
<div class="field">
    <label for="save_pdf_texts">Process existing PDF files</label>
    <div class="inputs">
        <?php echo __v()->formCheckbox('extract_pdf_texts'); ?>
    </div>
    <p class="explanation">This plugin enables searching on PDF files by 
    extracting their texts and saving them to their parent items. This normally 
    happens automatically, but there are times when you'll want to extract text 
    from all PDF files that exist in your Omeka archive; for example, when first 
    installing this plugin and when items are being created by other plugins. 
    Check the above box and submit this form to run the text extraction process, 
    which may take some time to finish.</p>
</div>
<?php
    }
    
    /**
     * Process the plugin config form.
     */
    public function hookConfig()
    {
        // Run the text extraction process if directed to do so.
        if ($_POST['extract_pdf_texts']) {
            ProcessDispatcher::startProcess('PdfSearchProcess');
        }
    }
    
    /**
     * Refresh PDF texts to account for an item save.
     */
    public function hookAfterSaveItem($item)
    {
        $elementId = $this->getPdfSearchElementId();
        $recordTypeId = $this->getItemRecordTypeId();
        $this->saveItemPdfText($item, $elementId, $recordTypeId);
    }
    
    /**
     * Refresh PDF texts to account for a file delete.
     */
    public function hookAfterDeleteFile($file)
    {
        $item = $file->getItem();
        $elementId = $this->getPdfSearchElementId();
        $recordTypeId = $this->getItemRecordTypeId();
        $item->deleteElementTextsByElementId(array($elementId));
        $this->saveItemPdfText($item, $elementId, $recordTypeId);
    }
    
    /**
     * Extract texts from all PDF files belonging to an item.
     * 
     * @param Item $item
     * @param int $elementId The ID of the "PDF Search::Text" element.
     * @param int $recordTypeId The ID of the Item record type.
     */
    public function saveItemPdfText(Item $item, $elementId, $recordTypeId)
    {
        // Iterate all files belonging to this item.
        foreach ($item->Files as $file) {
            $this->saveFilePdfText($file, $elementId, $recordTypeId);
        }
    }
    
    /**
     * Extract text from a PDF file and save it to the parent item.
     * 
     * @param File $file
     * @param int $elementId The ID of the "PDF Search::Text" element.
     * @param int $recordTypeId The ID of the Item record type.
     */
    public function saveFilePdfText(File $file, $elementId, $recordTypeId)
    {
        // Ignore non-PDF files.
        if (!in_array($file->mime_browser, $this->_pdfMimeTypes)) {
            return;
        }
        
        // Save the PDF text.
        $textRecord = new ElementText;
        $textRecord->record_id = $file->item_id;
        $textRecord->element_id = $elementId;
        $textRecord->record_type_id = $recordTypeId;
        $textRecord->text = $this->extractPdfText($file);
        $textRecord->html = false;
        $textRecord->save();
    }
    
    /**
     * Extract text from a PDF file and return it.
     * 
     * @param File $file
     * @return string
     */
    public function extractPdfText($file)
    {
        $path = escapeshellarg(FILES_DIR . '/' . $file->archive_filename);
        $cmd = "pdftotext $path -";
        return shell_exec($cmd);
    }
    
    /**
     * Get the ID of the "PDF Search:Text" element.
     * 
     * @return int
     */
    public function getPdfSearchElementId()
    {
        // Must create an arbitrary record to access 
        // ActsAsElementText::getElementByNameAndSetName.
        $item = new Item;
        return $item->getElementByNameAndSetName(self::ELEMENT_NAME, 
                                                 self::ELEMENT_SET_NAME)->id;
    }
    
    /**
     * Get the ID of the Item record type.
     * 
     * @return int
     */
    public function getItemRecordTypeId()
    {
        return get_db()->getTable('RecordType')->findIdFromName('item');
    }
    
    /**
     * Disable the PDF Search Text form element.
     * 
     * There are no circumstances where editing PDF Search Text is needed since 
     * the plugin overwrites any form submitted data. Form elements with the 
     * disabled attribute will not be submitted.
     */
    public static function disableForm($html, $inputNameStem, $value)
    {
        return __v()->formTextarea($inputNameStem . '[text]', 
                                   $value, 
                                   array('disabled' => 'disabled', 
                                         'class' => 'textinput', 
                                         'rows' => 15, 
                                         'cols' => 50));
    }
    
    /**
     * Disable the PDF Search Text display on the public theme.
     * 
     * The text extracted from PDFs tends to be poorly formatted and unfit for 
     * display on item show pages. This removes the entire element set section 
     * if show_item_metadata() is configured show_empty_elements = false.
    */
    public static function disableDisplay($text, $record, $elementText)
    {
        if (!is_admin_theme()) {
            $text = '';
        }
        return $text;
    }
}
