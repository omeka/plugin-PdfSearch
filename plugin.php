<?php
require_once 'PdfSearchPlugin.php';
$plugin = new PdfSearchPlugin;
$plugin->setUp();

$filter = array('Form', 'Item', PdfSearchPlugin::ELEMENT_SET_NAME, PdfSearchPlugin::ELEMENT_NAME);
add_filter($filter, 'PdfSearchPlugin::disableForm');

$filter = array('Display', 'Item', PdfSearchPlugin::ELEMENT_SET_NAME, PdfSearchPlugin::ELEMENT_NAME);
add_filter($filter, 'PdfSearchPlugin::disableDisplay');
