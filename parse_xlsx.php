<?php
/**
 * I had to parse an XLSX spreadsheet (which should damn well have been a CSV!)
 * but the usual tools were hitting the memory limit pretty quick. I found that
 * manually parsing the XML worked pretty well. Note that this, most likely,
 * won't work if cells contain anything more than text or a number (so formulas,
 * graphs, etc ..., I don't know what'd happen).
 */

function parse_xlsx_as_array($inputFile, $dir='/tmp')
{
    //$inputFile = '/path/to/spreadsheet.xlsx';
    //$dir = '/path/to/tmp/dir';

    // Unzip
    $zip = new ZipArchive;
    if ($zip->open($inputFile) === true) 
    {
        $zip->extractTo($dir);
        $zip->close();
    } 
    else 
    {
        _log("Error open Zip");
    }

    // Open up shared strings & the first worksheet
    $strings = simplexml_load_file($dir . '/xl/sharedStrings.xml');
    $sheet   = simplexml_load_file($dir . '/xl/worksheets/sheet1.xml');

    // Parse the rows
    $xlrows = $sheet->sheetData->row;

    $array_data = array();
    $header = array();

    foreach ($xlrows as $xlrow) {
        $arr = array();
        
        // In each row, grab it's value
        foreach ($xlrow->c as $cell) {
            $v = (string) $cell->v;
            
            // If it has a "t" (type?) of "s" (string?), use the value to look up string value
            if (isset($cell['t']) && $cell['t'] == 's') {
                $s  = array();
                $si = $strings->si[(int) $v];
                
                // Register & alias the default namespace or you'll get empty results in the xpath query
                $si->registerXPathNamespace('n', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');

                // Cat together all of the 't' (text?) node values
                foreach($si->xpath('.//n:t') as $t) {
                    $s[] = (string) $t;
                }

                $v = implode($s);
            }
            
            $arr[] = $v;
        }
        
        // Assuming the first row are headers, stick them in the headers array
        if (count($headers) == 0) {
            $headers = $arr;
        } else {
            // Combine the row with the headers - make sure we have the same column count
            $values = array_pad($arr, count($headers), '');
            $row    = array_combine($headers, $values);
            
            /**
             * Here, do whatever you like with the [header => value] assoc array in $row.
             * It might be useful just to run this script without any code here, to watch
             * memory usage simply iterating over your spreadsheet.
             */
        }

        $array_data[] = $row;
    }

    @unlink($dir);
    //@unlink($inputFile);

    return $array_data;
}
