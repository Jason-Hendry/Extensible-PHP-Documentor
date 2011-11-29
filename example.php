<?php

require 'extensiblephpdocumentor.php';

class EDPExampleDocumentor extends ExtensiblePHPDocumentor {
  
}

$example = new EDPExampleDocumentor();
$example->saveToFile = true;
$example->outputFormat = OUTPUT_FORMAT_CREOLE;
$example->DocumentFile('extensiblephpdocumentor.php');
