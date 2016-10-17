<?php
/*
 *
 */

$module = dirname(__FILE__) . '/Classes/PHPExcel.php';
echo("Load module " . $module);
require_once($module);

function parse_xlsx_as_array($file_data)
{
    // Carga un archivo .xsl[x] y devuelve su represetanción como un array

    $objPHPExcel = new PHPExcel();
    $objReader = PHPExcel_IOFactory::createReader("XLS");            
    $objPHPExcel = $objReader->load(dirname(__FILE__)."/".$file_data);
    $objWorksheet = $objPHPExcel->getActiveSheet();

    $highestRow = $objWorksheet->getHighestRow(); 
    $highestColumn = $objWorksheet->getHighestColumn(); 

    $highestColumnIndex = PHPExcel_Cell::columnIndexFromString($highestColumn); 

    $rows = array();
    for ($row = 0; $row <= $highestRow; ++$row) {
        for ($col = 0; $col <= $highestColumnIndex; ++$col) {
            $rows[$col] = $objWorksheet->getCellByColumnAndRow($col, $row)->getValue();
        }
    }
}
