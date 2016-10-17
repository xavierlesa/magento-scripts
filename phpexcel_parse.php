<?php
/*
 *
 */

require_once('PHPExcel/Classes/PHPExcel.php');

public function parse_xlsx_as_array($file_data)
{
    // Carga un archivo .xsl[x] y devuelve su represetanción como un array


    $objPHPExcel = PHPExcel::load($file_data);
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

    return $rows;
}
