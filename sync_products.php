<?php // vim: fdm=marker foldenable sw=4 ts=4 sts=4 
/*
    Syncroniza los productos, categorías, atributos e imagenes del catalogo completo.

    1- Busca atributos modificados y/o nuevos y ejecuta la sincronización. 
    Para atributos talle, color, precio.

    2- Busca categorías modificadas y/o nuevas y lo pasa al catalogo.

    3- Crea los productos simples, filtrando el excel por Talle = TU o sin Talle.

    4- Crea/Modifica los productos configurables por talle y/o color.

    5- Sincroniza las imagenes.
    desde --ftp-path o CONFIG_DEFAULT_FTP_PATH / $STORE_DATA['name'] / category / sub_category /
 */

// DEFINITIONS {{{

// STATICS {{{
define('CONFIG_DEFAULT_FTP_PATH', 'Ecommerce/linea_web');
//define('CONFIG_DEFAULT_EXCEL_NAME', 'catalogo-\d{2}\d{2}\d{4}.xls[x]');
//define('CONFIG_DEFAULT_SITE_NAME', 'urban');
//define('STORE_NAME', 'urban');
define('MEDIA_STORAGE_POINT', '../tmp_media/');
//}}}

// STORES {{{
$urban_store_id = 1;
$oneill_store_id = 2;
//}}}

// WEBSITES {{{
$urban_website_id = 1;
$oneill_website_id = 3;
//}}}

// ROOT CATEGORIES {{{
$urban_parent_id = 2;
$oneill_parent_id = 3;

define('STORE_ID', $urban_store_id); // ID del STORE VIEW NAME
define('WEBSITE_ID', $urban_website_id); // ID del STORE VIEW NAME
define('PARENT_ID', $urban_parent_id); // ID del root category
//}}}

// SHELL AND LOGS {{{
//Black        0;30     Dark Gray     1;30
//Red          0;31     Light Red     1;31
//Green        0;32     Light Green   1;32
//Brown/Orange 0;33     Yellow        1;33
//Blue         0;34     Light Blue    1;34
//Purple       0;35     Light Purple  1;35
//Cyan         0;36     Light Cyan    1;36
//Light Gray   0;37     White         1;37

function _BLACK($w){ return "\033[30m" . $w . "\033[0m"; }
function _RED($w){ return "\033[31m" . $w . "\033[0m"; }
function _GREEN($w){ return "\033[32m" . $w . "\033[0m"; }
function _BROWN($w){ return "\033[33m" . $w . "\033[0m"; }
function _BLUE($w){ return "\033[34m" . $w . "\033[0m"; }
function _PURPLE($w){ return "\033[35m" . $w . "\033[0m"; }
function _CYAN($w){ return "\033[36m" . $w . "\033[0m"; }
function _GRAY($w){ return "\033[37m" . $w . "\033[0m"; }
//}}}

// CATEGORY MAPPING {{{
//
// ROOT -> GENDER 
//
// BABY, JUNIOR -> NIÑO
// HOMBRE -> HOMBRE
// MUJER -> MUJER
// UNISEX -> HOMBRE, MUJER
// Si la LINEA es INDUMENTARIA o ACCESORIOS: usar FAMILIA como Subcategoria y GENERO como Categoria

// TREE
// 
// HOMBRE (GENERO) + LINEA INDUMENTARIA, ACCESORIOS
//     (FAMILIA)
//         (SUBFAMILIA)
// 
// MUJER (GENERO) + LINEA INDUMENTARIA, ACCESORIOS
//     (FAMILIA)
//         (SUBFAMILIA)
// 
// KIDS (GENERO) + LINEA INDUMENTARIA, ACCESORIOS
//     (FAMILIA)
//         (SUBFAMILIA)
// 
// CALZADO (LINEA)
//     (NONE)
// 
// HARD (LINEA)
//     (FAMILIA)
// 
// NEOPRENE (?)
//}}}

//}}}

function mapping_categories($genero, $linea, $familia, $subfamilia='')/*{{{*/
{
    // Genero   Familia Sub_Familia
    $_root = $genero;
    $_category = mb_strtoupper($familia);
    $_subcategory = mb_strtoupper($subfamilia);

    if (in_array(mb_strtoupper($linea), array('ACCESORIOS', 'INDUMENTARIA')))
    {
        $_root = $genero;

        if (in_array(mb_strtoupper($genero), array('BABY', 'JUNIOR', 'NIÑOS')))
        {
            $_root = 'KIDS';
        }
        elseif (mb_strtoupper($genero) == 'UNISEX')
        {
            $_root = array('HOMBRE', 'MUJER');
        }

    }
    elseif (in_array(mb_strtoupper($linea), array('CALZADO', 'HARD', 'NEOPRENE')))
    {
        $_root = $linea;

        if (mb_strtoupper($linea) != 'HARD')
        {
            return array($_root);
        }
    }

    return array($_root, $_category, $_subcategory);
}/*}}}*/

$array_images_files = array();

class ftp/*{{{*/
{
    public $conn;

    public function __construct($url)
    {
        if ($this->conn = ftp_connect($url))
        {
            _log(_GREEN("Conectado al FTP: " . $this->conn));
        }
        else
        {
            die(_RED("Error al conectar al FTP:\r\n" . $url));
        }

    }

    public function __call($func, $a)
    {
        if(strstr($func, 'ftp_') !== false && function_exists($func))
        {
            array_unshift($a, $this->conn);
            return call_user_func_array($func, $a);
        }
        else
        {
            // replace with your own error handler.
            die(_RED("$func is not a valid FTP function"));
        }
    }

    public function close() 
    {
        ftp_close($this->conn);
    }
}/*}}}*/

/**
 * Commands Utils for Magento
 **/
class CommandUtilMagento
{

    var $csv_array_header = [];/*{{{*/
    var $csv_array_data = [];
    var $csv_grouped_array_data = [];

    var $_cached_category = []; // "category/subcategory" => ID
    var $_cached_attribute = []; // "attricube_code" => "attribute" => ID

    var $mapped_colors = [];

    var $row_sku = 'sku';
    var $row_product_id = 'producto';
    var $row_name = 'descripcion'; 
    var $row_description = 'descripcion'; 

    var $row_attr_cod_color = 'cod_fam_col'; 
    var $row_attr_color = 'fam_color'; 
    var $row_attr_size = 'talle'; 
    var $row_attr_manufacture = 'marca';
    var $row_attr_source = 'origen'; 
    var $row_attr_season = 'temporada';
    var $row_attr_gender = 'genero'; 

    var $row_line = 'linea';
    var $row_category = 'familia';
    var $row_subcategory = 'sub_familia';
    var $row_price = 'precio_vtas';

    var $STORE_DATA = array(
            // 'store_id' => '1',
            // 'code' => 'default',
            // 'website_id' => '1',
            // 'group_id' => '1',
            // 'name' => 'urbanstore.com.ar',
            // 'sort_order' => '0',
            // 'is_active' => '1',
        );/*}}}*/


    function __construct()/*{{{*/
    {
        boostrap();
    }/*}}}*/


    public function init()/*{{{*/
    {
        /*
         *
         */ 

        $this->getMenu();
    }/*}}}*/


    public function syncAttributes()/*{{{*/
    {
        // Sincroniza los atributos.
        echo "syncAttributes";
    }/*}}}*/


    public function syncCategories()/*{{{*/
    {
        // Sincroniza las categorías.
        echo "syncCategories\r\n";

        $col_category = $this->row_category;
        $col_subcategory = $this->row_subcategory;

        if ( count($this->csv_array_header) === 2 
            or ( !array_key_exists($this->row_category, $this->csv_array_header) 
            and !array_key_exists($this->row_subcategory, $this->csv_array_header) ) )
        {

            $col_category = 0;
            $col_subcategory = 1;
        }

        _log(var_export($this->csv_array_header, true ));

        foreach ($this->csv_array_data as $row) 
        {
            $this->getOrCreateCategories(array($row[$col_category], $row[$col_subcategory]), null, $this->opt_commit);
        }

    }/*}}}*/


    public function syncProducts()/*{{{*/
    {
        // Ejecuta el proceso de mapeo para poductos
        // Checkea que todas las key requeridas existan. (ref: http://stackoverflow.com/questions/13169588/how-to-check-if-multiple-array-keys-exists)

        $required = array(
            $this->row_sku, 
            $this->row_product_id, 
            $this->row_description, 
            $this->row_line,
            $this->row_category,
            $this->row_subcategory,
            $this->row_price);

        if (count(array_intersect($required, $this->csv_array_header)) !== count($required)) 
        {
            _log("Error el archivo no corresponde al formato de columnas " . implode(', ', $required));
            _log("Requeridas: \r\n" . var_export($required, true));
            _log("Columnas del CSV: \r\n" . var_export($this->csv_array_header, true));
            exit(0);
        }

        $this->csv_grouped_array_data = $this->groupArray($this->csv_array_data, $this->row_product_id);

        $_total_config = 0;
        $_total_simple = 0;

        foreach ($this->csv_grouped_array_data as $key => $val) 
        {
            if(count($val) > 1) $_total_config++;
            else $_total_simple++;
        }

        _log("Hay " . count($this->csv_grouped_array_data) . " grupos de productos");
        _log("Hay " . $_total_simple . " productos simples");
        _log("Hay " . $_total_config . " productos configurables");

    }/*}}}*/


    public function syncSimpleProducts()/*{{{*/
    {
        // Sincroniza solo los productos simples.
        echo "syncSimpleProducts\r\n";

        foreach( $this->csv_grouped_array_data as $key => $products ) 
        {
            if(count($products) == 1) 
            {
                $row = $products[0];

                _log("Preparando producto {sku} {descripcion}", $row);

                $this->createProduct(
                    $row[$this->row_sku], 
                    $row[$this->row_product_id], 
                    ucfirst(mb_strtoupper($row[$this->row_name])), 
                    ucfirst(mb_strtoupper($row[$this->row_description])), 
                    $row[$this->row_attr_cod_color], 
                    $row[$this->row_attr_color], 
                    $row[$this->row_attr_size], 
                    $row[$this->row_attr_manufacture], 
                    $row[$this->row_attr_source], 
                    $row[$this->row_attr_season], 
                    $row[$this->row_attr_gender], 
                    $row[$this->row_line],
                    $row[$this->row_category],
                    $row[$this->row_subcategory], 
                    $row[$this->row_price],
                    ucfirst(mb_strtolower($row[$this->row_line]))
                );
            }
        }

    }/*}}}*/


    public function syncConfigurableProducts()/*{{{*/
    {
        // Sincroniza los productos configurables.
        echo "syncConfigurableProducts\r\n";


        // resuelve una sola vez los atributos posibles
        //$array_attr = array('color', 'size', 'size_letter');
        $array_attr = array('color', 'size');
        $array_attribues = [];
        foreach($array_attr as $code) 
        {
            $attr = Mage::getModel('catalog/resource_eav_attribute')->loadByCode('catalog_product', $code);
            $array_attribues[$code] = $attr;
            //_log("array_attribues[".$code."] = ".var_export($attr,1));
        }

        foreach( $this->csv_grouped_array_data as $key => $products )
        {
            if(count($products) > 1 or true) // Hack horrible para poner siempre productos configurables
            {

                $row = $products[0];

                // crea el primer producto como configurable
                _log("Crea el producto como configurable {producto} {talle} {color}", $row);

                $sku = "CONFIG-" . $row[$this->row_product_id];

                $configProduct = $this->createProduct(
                    $sku, // crea un SKU propio
                    $row[$this->row_product_id], 
                    ucfirst(mb_strtoupper($row[$this->row_name])), 
                    ucfirst(mb_strtoupper($row[$this->row_description])), 
                    $row[$this->row_attr_cod_color], 
                    $row[$this->row_attr_color], 
                    $row[$this->row_attr_size], 
                    $row[$this->row_attr_manufacture], 
                    $row[$this->row_attr_source], 
                    $row[$this->row_attr_season], 
                    $row[$this->row_attr_gender], 
                    $row[$this->row_line],
                    $row[$this->row_category],
                    $row[$this->row_subcategory], 
                    $row[$this->row_price],
                    ucfirst(mb_strtolower($row[$this->row_line])),
                    Mage_Catalog_Model_Product_Type::TYPE_CONFIGURABLE,
                    Mage_Catalog_Model_Product_Visibility::VISIBILITY_BOTH,
                    false); // NO COMMIT 

                // Configuracion de atributos
                // indumentaria -> set indumentaria (color, size)
                // calzado -> set calzado (color, number)

                if (mb_strtolower($row[$this->row_line]) == 'indumentaria')
                {
                    $_attributes = array(
                        $array_attribues['color']->getId() => $array_attribues['color'], 
                        //$array_attribues['size_letter']->getId() => $array_attribues['size_letter']
                        $array_attribues['size']->getId() => $array_attribues['size']
                    );
                } 
                elseif (mb_strtolower($row[$this->row_line]) == 'calzado')
                {
                    $_attributes = array(
                        $array_attribues['color']->getId() => $array_attribues['color'], 
                        $array_attribues['size']->getId() => $array_attribues['size']
                    );

                } 
                else 
                {
                    $_attributes = array(
                        $array_attribues['color']->getId() => $array_attribues['color']
                    );
                }

                $_attributes_ids = array_keys($_attributes);

                $configProduct->getTypeInstance()->setUsedProductAttributeIds($_attributes_ids); //attribute ID of attribute 'color' in my store
                $configurableAttributesData = $configProduct->getTypeInstance()->getConfigurableAttributesAsArray();
                $configProduct->setCanSaveConfigurableAttributes(true);
                $configProduct->setConfigurableAttributesData($configurableAttributesData);
                
                
                // ASOCIA LOS ATRIBUTOS y guarda la instancia
                foreach($_attributes as $attrCode)
                {
                    $super_attribute= Mage::getModel('eav/entity_attribute')->loadByCode('catalog_product', $attrCode->code);
                    $configurableAtt = Mage::getModel('catalog/product_type_configurable_attribute')->setProductAttribute($super_attribute);

                    $newAttributes[] = array(
                       'id'             => $configurableAtt->getId(),
                       'label'          => $configurableAtt->getLabel(),
                       'position'       => $super_attribute->getPosition(),
                       'values'         => $configurableAtt->getPrices() ? $configProduct->getPrices() : array(),
                       'attribute_id'   => $super_attribute->getId(),
                       'attribute_code' => $super_attribute->getAttributeCode(),
                       'frontend_label' => $super_attribute->getFrontend()->getLabel(),
                    );
                }

                $existingAtt = $configProduct->getTypeInstance()->getConfigurableAttributes();

                if(empty($existingAtt) && !empty($newAttributes))
                {
                    $configProduct->setCanSaveConfigurableAttributes(true);
                    $configProduct->setConfigurableAttributesData($newAttributes);
                    $configProduct->save();
                }



                $configurableProductsData = array();

                _log("Crea los " . (count($products) - 1) . " productos asociados al configurable\r\n".
                    "================================================================================\r\n");

                foreach(array_slice($products, 1) as $row)
                {
                    // Create product instances
                    $simpleProduct = $this->createProduct(
                        $row[$this->row_sku], 
                        $row[$this->row_product_id],
                        $row[$this->row_attr_size] . " - " . $row[$this->row_attr_color] . " - " . ucfirst(mb_strtolower($row[$this->row_name])), // crea un titulo propio para identificarlo
                        ucfirst(mb_strtolower($row[$this->row_description])), 
                        $row[$this->row_attr_cod_color], 
                        $row[$this->row_attr_color], 
                        $row[$this->row_attr_size], 
                        $row[$this->row_attr_manufacture], 
                        $row[$this->row_attr_source], 
                        $row[$this->row_attr_season], 
                        $row[$this->row_attr_gender], 
                        $row[$this->row_line],
                        $row[$this->row_category],
                        $row[$this->row_subcategory], 
                        $row[$this->row_price],
                        ucfirst(mb_strtolower($row[$this->row_line])),
                        Mage_Catalog_Model_Product_Type::TYPE_SIMPLE,
                        Mage_Catalog_Model_Product_Visibility::VISIBILITY_NOT_VISIBLE,
                        true); // COMMIT 

                    $configurableProductsData[$simpleProduct->getId()] = $simpleProduct;
                }

                _log("Asocia los " . count($configurableProductsData) . " productos simples al configurable COD: " . $row[$this->row_product_id]);
                $configProduct->setConfigurableProductsData($configurableProductsData); // asocia los productos simples al configurable

                try 
                {
                    $configProduct->save();
                } 
                catch(Exception $e) 
                {
                    try 
                    {
                        //_log("Try with getResource -> save");
                        $configProduct->getResource()->save($configProduct);
                    }
                    catch(Exception $e)
                    {
                        _log(_RED("ERROR al guarar el producto configurable desde el resocurce\n" . $e->getMessage() ));
                    }

                }

                _log(_GREEN("Producto configurable creado " . $configProduct->getId()));
                
                //$product = Mage::getModel('catalog/product')->load($_productID);
                $childProducts = Mage::getModel('catalog/product_type_configurable')->getUsedProducts(null, $configProduct);
                _log(_PURPLE("Productos comprobados asociados " . count($childProducts)));

            }
        }

    }/*}}}*/


    public function resolveImageName($strfile)
    {
        // devuelve el producto, color y numero de imagen en base al path
        $regex = "#.*\/+(?P<producto>[^/_.\-]+)_?(?P<color>[a-zA-Z]+)?(?P<imgn>\d+)?#";
        preg_match($regex, $strfile, $campos); //producto_color, producto, color
        $producto = getattr($campos['producto'], '');
        $color = getattr($campos['color'], '');
        $imgn = getattr($campos['imgn'], 0);
        return $campos;
    }


    public function syncImages()/*{{{*/
    {
        global $array_images_files;
        // Sincroniza las imagenes que se asociarán a los productos.
        echo "syncImages";
        $ftp = new ftp($this->opt_ftp['server']);
        $ftp->ftp_login($this->opt_ftp['user'], $this->opt_ftp['pass']);
        echo $ftp->ftp_pasv(true);

        $path_parts = join(DS, array($this->opt_ftp['path'], $this->STORE_DATA['name'])); // category / sub_category / 

        _log("Busca imagenes en " . $this->STORE_DATA['name'] . " -> " . $path_parts);

        $ftp_list = $this->getFileTree($ftp, $path_parts);

        _log(var_export($array_images_files, 1));

        $fp = fopen(MEDIA_STORAGE_POINT . 'mapping_images-'. $this->STORE_DATA['name'] .'.csv', 'w');
        
        // HEADERS
        fputcsv($fp, array('product', 'color', 'path'));

        foreach($array_images_files as $pimg)
        {
            
            $campos = $this->resolveImageName($pimg);
            $producto = $campos['producto'];
            $color = $campos['color'];
            $imgn = $campos['imgn'];

            $local_file = MEDIA_STORAGE_POINT . $producto . '_' . $color . ($imgn=='' ? '' :  ('_' . $imgn)) . '.jpg';

            if ($ftp->ftp_get($local_file, $path_parts . $pimg, FTP_BINARY))
            {
                // codigo_producto, codigo_color, path
                $campos = array($producto, $color, $local_file);
                fputcsv($fp, $campos);
                _log(_GREEN("Imagen desde el server \"".$pimg."\" ha sido guardada a \"" . $local_file . "\""));
            }
            else
            {
                _log(_RED("Error al guardar la imagen \"".MEDIA_STORAGE_POINT.$producto."_".$color.".jpg\""));
            }
            
        }

        fclose($fp);
    }/*}}}*/


    public function getFileTree($ftp, $path)/*{{{*/
    {
        global $array_images_files;
        $path = str_replace(" ", "\ ", $path); 
        $path_parts = join(DS, array($this->opt_ftp['path'], $this->STORE_DATA['name'])); // category / sub_category / 

        _log(_GRAY("Explora el path $path en busca de imagenes"));

        if ($ftp_list = $ftp->ftp_nlist($path))
        {
            foreach($ftp_list as $dir)
            {
                if ($dir != '.' && $dir != '..') 
                {

                    if (preg_match('/\.(jpg|jpeg|png|gif)$/i', $dir))
                    {
                        $_path = str_replace($path_parts, "", $path . DS . $dir);
                        _log(_BLUE("Cargando en memoria " . $_path));
                        $array_images_files[] = $_path;
                    }
                    else $this->getFileTree($ftp, $path . DS . $dir);
                }
            }
        }
        else
        {
            _log(_BROWN("El listado del $path desde el FTP está vacio. \r\nADVERTENCIA: Si contiene espacios (incluso con \\ escape no lo explora)"));
        }
    }/*}}}*/

    public function associateImageAndColor($product_model, $row, $color='')/*{{{*/
    {
        
        $mapped_colors = $this->mapColors();
        $product_type = $product_model->getTypeId();
        $orig_campos = $this->resolveImageName($row[2]);
        $size = $product_model->getResource()->getAttribute('size')->getFrontend()->getValue($product_model);
        $color =  $product_model->getResource()->getAttribute('color')->getFrontend()->getValue($product_model);
        $label = null;


        // hay un hack que agregar un label en este metodo 
        // http://stackoverflow.com/questions/7215105/magento-set-product-image-label-during-import
        $_m_color = getattr($mapped_colors[$orig_campos['color']], '');
        //_log("Busca en mapped_colors el Codigo de Color: " . $orig_campos['color']);

        if (is_array($_m_color)) {
            $label = ucfirst(mb_strtolower($_m_color["color"]));
        }
        //else {
        //    _log("El color mappeado es" . $_m_color . " de " . ($orig_campos['color'] ? $orig_campos['color'] : 'SIN COLOR'));
        //}

        // Si es un producto simple, deberia tener color y size, entonces me aseguro
        // que asocie la imagen correspondiente al color.
        if ($product_type !== Mage_Catalog_Model_Product_Type::TYPE_CONFIGURABLE && $color != $label) {
            _log("[SKIP] Es un producto " . $product_type . " con color " . $color . " NO es " . $label);
            return $this;
        }

        // elimina las imagenes previas
        $mediaApi = Mage::getModel("catalog/product_attribute_media_api");
        $items = $mediaApi->items($product_model->getId());

        // Si es config y tiene asociados vuelve
        _log("Es un producto configurable? " . $product_type . ". Si es asi, salta. items " . count($items));
        //if ($product_type == Mage_Catalog_Model_Product_Type::TYPE_CONFIGURABLE && count($items)) {
        //    return $this;
        //}

        // Elimina las imagenes asociadas SOLO si NO es un producto consfigurables
        if ($product_type != Mage_Catalog_Model_Product_Type::TYPE_CONFIGURABLE && count($items)) {
            foreach ($items as $item) {
                $act_campos = $this->resolveImageName($item['file']);

                if ($act_campos['producto'] == $orig_campos['producto'] && 
                    $act_campos['color'] == $orig_campos['color'] && 
                    $act_campos['imgn'] == $orig_campos['imgn']) {

                    _log(_BROWN("Elimina la imagen actual SKU: " . $orig_campos['producto'] . " COLOR: " . $orig_campos['color'] . " FILE: " . $item['file']));
                    $mediaApi->remove($product_model->getId(), $item['file']);
                }
            }
        }

        // TODO #1: Hay dos caminos:
        // 1- cargar solo las imagenes a los productos simples y luego
        // cargar la última? imagen del producto simple asociado y cargarla al configurable.
        //
        // 2- bien cargar todas las imagenes al configurable validando que los colores existen.
        
        // TODO #2: Por alguna razón al asociar las iamgenes de algunos productos se borran los atributos configurables
        // y la asociación se elimina del configurable.
        // http://urbancshop.devlinkb.com.ar/calzado/zapatilla-ntx-9470.html
        // SKU: ZAAI0005

        $mediaAttr = null;
        if(count($items)<1) {
            $mediaAttr = array(
                    'image',
                    'thumbnail',
                    'small_image'
                );
        }

        $product_model
            ->setMediaGallery(
                array(
                    'images' => array(),
                    'values' => array()
                )
            )
            ->addImageToMediaGallery($row[2], $mediaAttr, false, false, $label)
            ->save();

        _log(_BLUE("Producto " . $product_type . " con sku:" . $row[0] . ", tiene una nueva imagen \"" . $row[2] . "\" con label/color: \"" . $label . "\" y orden: \"" . $orig_campos['imgn'] . "\""));
    }/*}}}*/


    public function associateImageAndColorForConfigurable($product_model, $row, $color='')
    {
        
        $mapped_colors = $this->mapColors();
        $product_type = $product_model->getTypeId();
        $orig_campos = $this->resolveImageName($row[2]);
        $size = $product_model->getResource()->getAttribute('size')->getFrontend()->getValue($product_model);
        $color =  $product_model->getResource()->getAttribute('color')->getFrontend()->getValue($product_model);
        $label = null;


        // hay un hack que agregar un label en este metodo 
        // http://stackoverflow.com/questions/7215105/magento-set-product-image-label-during-import
        $_m_color = getattr($mapped_colors[$orig_campos['color']], '');
        //_log("Busca en mapped_colors el Codigo de Color: " . $orig_campos['color']);

        if (is_array($_m_color)) {
            $label = ucfirst(mb_strtolower($_m_color["color"]));
        }
        //else {
        //    _log("El color mappeado es" . $_m_color . " de " . ($orig_campos['color'] ? $orig_campos['color'] : 'SIN COLOR'));
        //}

        // elimina las imagenes previas
        $mediaApi = Mage::getModel("catalog/product_attribute_media_api");
        $items = $mediaApi->items($product_model->getId());

        // Elimina las imagenes asociadas que ya existen, para no duplicarlas
        if (count($items)) {
            foreach ($items as $item) {
                $act_campos = $this->resolveImageName($item['file']);

                if ($act_campos['producto'] == $orig_campos['producto'] && 
                    $act_campos['color'] == $orig_campos['color'] && 
                    $act_campos['imgn'] == $orig_campos['imgn']) {

                    _log(_BROWN("Elimina la imagen actual SKU: " 
                        . $orig_campos['producto'] . " COLOR: " 
                        . $orig_campos['color'] . " FILE: " 
                        . $item['file']));

                    $mediaApi->remove($product_model->getId(), $item['file']);
                }
            }
        }


        $mediaAttr = array(
            'image',
            'thumbnail',
            'small_image'
        );

        $product_model
            ->setMediaGallery(
                array(
                    'images' => array(),
                    'values' => array()
                )
            )
            ->addImageToMediaGallery($row[2], $mediaAttr, false, false, $label)
            ->save();

        _log(_BLUE("Producto " . $product_type . " con sku:" . $row[0] . ", tiene una nueva imagen \"" . $row[2] . "\" con label/color: \"" . $label . "\" y orden: \"" . $orig_campos['imgn'] . "\""));
    }

    /**
     * Mappea los colores en un array para uso futuro
     */
    public function mapColors()
    {
        if(count($this->mapped_colors) < 1) {
            // GUARDA en un archivo el mappging de codigo_producto+codigo_color => /path/del/ftp/codigo_producto+codigo_color.jpg
            $fp_colors = fopen('mapping_colors.csv', 'r');
            $mapped_colors = array();
            while (($datos = fgetcsv($fp_colors, 1000, ",")) !== false) 
            {
                $mapped_colors[$datos[0]] = array(
                    "description" => $datos[1], 
                    "code" => $datos[2], 
                    "color" => $datos[3]
                );
                _log("Mapping color " . $datos[0] . " CODE: " . $datos[2] . " COLOR: " . $datos[3] );
            }
            fclose($fp_colors);

            $this->mapped_colors = $mapped_colors;
        }

        return $this->mapped_colors;
    }

    /**
     * Descarga las imágenes de los productos para asociar a los productos simples
     * 
     */
    public function attachLocalMedia()
    {   
        //array('product', 'color', 'path');
        $fp = fopen(MEDIA_STORAGE_POINT . 'mapping_images-'. $this->STORE_DATA['name'] .'.csv', 'r');

        $configurables = [];

        while (($row = fgetcsv($fp, 1000, ",")) !== false)
        {
            $product_model = Mage::getModel('catalog/product');

            // ATTACH All images to configurable.
            $attach_images_to_configurable = true;
            if($attach_images_to_configurable /*&& !in_array("CONFIG-".$row[0], $configurables)*/ ) {
                $_id = $product_model->getIdBySku("CONFIG-".$row[0]);
                if($_id && $product_model->load($_id)) {
                    $this->associateImageAndColorForConfigurable($product_model, $row);
                    $configurables[] = "CONFIG-".$row[0];
                }
            }

            //$orig_campos = $this->resolveImageName($row[2]);

            //$products = $product_model->getCollection()
            //    ->addAttributeToFilter('cod_product', 
            //    array(
            //        'eq' => $row[0] //eq, nep, like, nlike, in, nin, gt, lt, etc..
            //    ))
            //    //->addAttributeToFilter('color', 
            //    //array(
            //    //    'eq' => $orig_campos['color']
            //    //))
            //    ->load();

            //_log("Productos asociados : " . count($products));

            //foreach ($products as $product) {
            //    if ($product && $product_model->load($product->getId())) {
            //        $this->associateImageAndColor($product_model, $row);
            //    }
            //}
        }

        fclose($fp);

        //// Por ultimo busca todos los productos configurables y les asocia una imagen - TEST -
        //$configurable_products = $product_model
        //    ->getCollection()
        //    ->addAttributeToFilter('type_id', 
        //        array(
        //            'eq' =>  Mage_Catalog_Model_Product_Type::TYPE_CONFIGURABLE
        //        ))
        //    ->load();

        //$mediaApi = Mage::getModel("catalog/product_attribute_media_api");

        //_log("Iterando entre los prouctos configurables y sus imagenes");
        //$c = 0;
        //foreach ($configurable_products as $_c_product) {
        //    $items = $mediaApi->items($_c_product->getId());
        //    _log("Media items para " . $_c_product->getSku() . " >> " . count($items));
        //}

    }


    public function reindex()
    {
        // reindexa el catalogo
        _log("Reindexando catalogo de Productos...");

        /* @var $indexCollection Mage_Index_Model_Resource_Process_Collection */
        $indexCollection = Mage::getModel('index/process')->getCollection();
        foreach ($indexCollection as $index)
        {
            /* @var $index Mage_Index_Model_Process */
            $index->reindexAll();
        }

        _log(_GREEN("Reindexado completo"));
    }


    public function deleteAllProducts()
    {
        // borra todos los productos
        set_time_limit(3600);

        umask(0);
        Mage::app()->setCurrentStore(Mage_Core_Model_App::ADMIN_STORE_ID);

        $products = Mage :: getResourceModel('catalog/product_collection')
            ->setStoreId(STORE_ID)->getAllIds();

        if(is_array($products))
        {
            foreach ($products as $key => $productId)
            {
                try
                {
                    $product = Mage::getModel('catalog/product')->load($productId)->delete();

                } 
                catch (Exception $e) 
                {
                    _log("Unable to delete product with ID: ". $productId);
                }
            }
        }
    }


    public function sync()
    {
        // Sync all products from CSV

        //var_export(prompt("Cargar todos los productos?"));

        $result = prompt("Cargar todos los productos, categorías y atributos?", array(
            "1" => array("Productos Simples", "simples"),
            "2" => array("Productos Configurables", "configurables"),
            "3" => array("Descarga las Imágenes de Productos desde el FTP", "imagenes_server"),
            "4" => array("Actualizar Imágenes de Productos desde el local", "imagenes_local"),
            "5" => array("Solo las Categorías", "categorias"),
            "6" => array("Solo los Atributos", "atributos"),
            "7" => array("Todos los productos (precaución experimental)", "todos"),
            "9" => array("BORRAR TODOS LOS PRODUCTOS !!!", "delete_all"),
        ));

        switch ($result)
        {
        case '1':
            $this->syncProducts();
            $this->syncSimpleProducts();
            $this->reindex();
            break;

        case '2':
            $this->syncProducts();
            $this->syncConfigurableProducts();
            $this->reindex();
            break;

        case '3':
            $this->syncImages();
            break;

        case '4':
            $this->attachLocalMedia();
            break;

        case '5':
            $this->syncProducts();
            $this->syncCategories();
            break;

        case '6':
            $this->syncAttributes();
            break;

        case '7':
            $this->syncProducts();
            $this->syncSimpleProducts();
            echo "\r\n";
            $this->syncConfigurableProducts();
            $this->reindex();
            break;

        case '9':

            $result = prompt(_RED("SEGURO QUE QUERES BORRAR TODOS LOS PRODUCTOS?"));
            if ( $result === true ) $this->deleteAllProducts();
            else echo "\r\nCAGON!\r\n";
            break;
        }

        echo "\r\n";

    }


    public function loadFileData($file_data, $flat = 0)
    {
        // Carga el archivo en un array de rows con key -> val (columna -> datos)

        //$csv = array_map("str_getcsv", file($file_data, "r")); 
        //$header = array_shift($csv); 

        //$col = array_search("Value", $header); 

        //echo var_export($col, True);

        //foreach ($csv as $row)
        //{      
        //    $array[] = $row[$col]; 
        //}

        // ES un xlsx ?
        $extension = end(explode('.', $file_data));

        if ($extension == 'xls' || $extension == 'xlsx') 
        {
            //require_once('phpexcel_parse.php');
            require_once('parse_xlsx.php');
            $array_data = parse_xlsx_as_array($file_data);
            $this->csv_array_header = array_map("mb_strtolower", array_keys($array_data[0]));
            $this->csv_array_data = $array_data;

        }
        elseif ($extension == 'cvs' || $extension == 'csv') 
        {
            $fila = 0;

            if (($gestor = fopen($file_data, "r")) !== false)
            {
                while (($row = fgetcsv($gestor, 1000, $this->opt_csv)) !== false)
                {
                    // la primer fila tiene los encabezados, la salto
                    if ( $fila == 0 )
                    {
                        $this->csv_array_header = array_map("mb_strtolower", $row);
                        $fila++;
                        continue;
                    }
                    if ($flat)
                    {
                        $this->csv_array_data[] = $row;
                    } 
                    else 
                    {
                        $this->csv_array_data[] = array_combine($this->csv_array_header, $row);
                    }

                }

            }
            else 
            {
                _log(_RED("El archivo " . $file_data . " no se ha encontrado o no se puede acceder"));
            }

        }

        _log("header:\r\n" . var_export($this->csv_array_header, true));
        _log(count($this->csv_array_data) . " Artículos en CSV"); 

    }


    public function groupArray($array, $arg)
    {
        // Agrupa un array por un key y devuelve un nuevo array
        // $array: el array
        // $arg: el key o val a buscar
        // @return array

        $grouparr = array();

        foreach ($array as $key => $val)
        {
            //_log("groupArray: " . $key . " => " . $val[$arg]);

            if (array_key_exists($val[$arg], $grouparr))
            {
                // existe en el array ese key, asocia un nuevo item
                $grouparr[$val[$arg]][] = $val;
            } 
            else 
            {
                $grouparr[$val[$arg]] = array($val);
            }

        }

        return $grouparr;
    }


    // Methods
    public function createProduct($sku, $cod_product, $name, $description, 
        $cod_color, $color, $size, $manufacturer, $source, $season, $gender, 
        $line, $category, $subcategory, $price, $attribute_set=null, 
        $product_type=null, $product_visibility=null, $commit=true) 
    { 
        //
        // Create a new product
        //
        
        // if first argument is an array try to convert to Product Model Object
        //_log("PRODUCT TYPE: " . $product_type);
        
        $product_model = Mage::getModel('catalog/product');
        $_id = $product_model->getIdBySku($sku);

        if ( $_id && $product_model->load($_id) )
        {
            // solo actualiza el precio del producto, no lo vuelve a crear
            _log("Actualiza el precio del producto $sku -> $price");
            $product_model->setPrice($price);

            if ($commit) 
            {
                try 
                {
                    $product_model->save();
                } 
                catch(Exception $e)
                {
                    _log("ERROR product_model\n" . $e->getMessage());
                    try 
                    {
                        //_log("Try with getResource -> save");
                        $product_model->getResource()->save($product_model);
                    }
                    catch(Exception $e)
                    {
                        //_log("ERROR product_model resource\n" . $e->getMessage());
                    }
                }
            }

            return $product_model;
        }

        $cost = null;
        //$special_price = null;

        $product_type = $product_type ? $product_type : DEFAULT_PRODUCT_TYPE;

        // add category if does not exist
        //_log(_BROWN("Add category if does not exist"));
        //$array_categories = $this->getOrCreateCategories( array($category, $subcategory) );

        $mapped_categories = mapping_categories($gender, $line, $category, $subcategory);

        if (is_array($mapped_categories[0])) 
        {
            $array_categories = array();
            foreach ($mapped_categories[0] as $category)
            {
                $array_categories = $array_categories + $this->getOrCreateCategories(array($category, $mapped_categories[1]));
            }

        }
        else 
        {
            $array_categories = $this->getOrCreateCategories($mapped_categories);
        }

        $attr_color = '';
        $attr_size = '';
        //$attr_size_l = '';

        if ($product_type !== Mage_Catalog_Model_Product_Type::TYPE_CONFIGURABLE)
        {
            // add attributes
            if ($color)
            {
                //_log(_BROWN("Add attribute color: " . $color));
                $attr_color = $this->getOrCreateAttributes('color', $color, $color);
            }
            // set size attributes
            if ($size)
            {
                //_log(_BROWN("Add attribute size: " . $size));
                $attr_size = $this->getOrCreateAttributes('size', 'Size', $size);
            }
        }

        //_log(_BROWN("Add attribute cod_product: " .$cod_product));
        $attr_cod_product = $this->getOrCreateAttributes('cod_product', 'cod_product', $cod_product, array(
            'frontend_input' => 'text',
        ));

        if (!$attribute_set) $attribute_set = 'Default';
        if (! $attribute_set_id = $this->getAttributeSetByName($attribute_set) )
        {
            $attribute_set_id = DEFAULT_ATTRIBUTES;
        } 

        //_log(_BROWN("Add attribute manufacturer: " . $manufacturer));
        $attr_manufacturer = $this->getOrCreateAttributes('manufacturer', $manufacturer, $manufacturer);
        $product_visibility = $product_visibility === null ? DEFAULT_PRODUCT_VISIBILITY : $product_visibility;

        try 
        {

            _log("Try to create a new product.\n"
                ."STORE_ID: {store_id}\n"
                ."SKU: {sku}\n"
                ."Name: {name}\n"
                ."Product type: {type}\n"
                ."Attribute Set: {attribute_set}\n"
                ."Attribute Set ID: {attribute_set_id}\n"
                ."Color ID: {color}\n"
                ."Manufacturer ID: {manufacturer}\n"
                ."Size: {size}\n"
                //."Size Letter: {size_l}\n"
                ,
                array(
                    "store_id" => STORE_ID,
                    "sku" => $sku, 
                    "name" => $name,
                    "type" => $product_type,
                    "attribute_set" => $attribute_set,
                    "attribute_set_id" => $attribute_set_id,
                    "color" => $attr_color, 
                    "manufacturer" => $attr_manufacturer, 
                    "size" => $attr_size, 
                    //"size_l" => $attr_size_l, 
                )
            );

            //echo "color: " . $attr_color . "\n";
            //echo "manufacturer: " . $attr_manufacturer . "\n";
            //if (is_array($price)) {
            //    $price = $price[0];
            //    $special_price = $price[1];
            //}

            $product_model
                ->setStoreId(STORE_ID)                      // you can set data in store scope
                ->setWebsiteIds(array(WEBSITE_ID))          // website ID the product is assigned to, as an array
                ->setAttributeSetId($attribute_set_id)      // ID of a attribute set named 'default'
                ->setTypeId($product_type)                  // product type
                ->setCreatedAt(strtotime('now'))            // product creation time
                ->setUpdatedAt(strtotime('now'))            // product update time

                ->setName($name)                            // product name
                ->setDescription($description)              // Long product description
                ->setShortDescription($description)         // Short product description
                ->setSku($sku)                              // SKU
                ->setWeight(0.0000)                         // weight
                ->setStatus(DEFAULT_PRODUCT_STATUS)         // product status (1 - enabled, 2 - disabled)
                ->setVisibility($product_visibility)        

                ->setPrice($price)                          // Price 2 decimal
                ->setTaxClassId(4)                          // tax class (0 - none, 1 - default, 2 - taxable, 4 - shipping)

                ->setStockData(
                    array(
                        'use_config_manage_stock' => 1,     // 'Use config settings' checkbox
                        'manage_stock' => 0,                // Manage stock
                        'min_sale_qty' => 1,                // Minimum Qty Allowed in Shopping Cart
                        'max_sale_qty' => 9,                // Maximum Qty Allowed in Shopping Cart
                        'is_in_stock' => 1,                 // Stock Availability
                        'qty' => 999                        // qty
                    )
                )

                ->setCategoryIds($array_categories)         // Assign product to categories
                ->setManufacturer($attr_manufacturer)       // Manufacturer id
                ->setCodProduct($attr_cod_product)          // Cod Product internal reference
                ->setNewsFromDate(strtotime('now'))         // Product set as new from
                ->setNewsToDate()                           // Product set as new to
                ->setCountryOfManufacture('AF')             // Country of manufacture (2-letter country code)

                ->setCost($cost ? $cost : $price)           // Cost 2 decimal
                //->setSpecialPrice($special_price ? $special_price : $special_price)         // Special price in form 11.22
                //->setSpecialFromDate(strtotime('now'))    // Special price from (MM-DD-YYYY)
                //->setSpecialToDate()                      // Special price to (MM-DD-YYYY)

                // VALIDATE?
                ->setMsrpEnabled(1)                         // Enable MAP
                ->setMsrpDisplayActualPriceType(1)          // Display actual price (1 - on gesture, 2 - in cart, 3 - before order confirmation, 4 - use config)
                ->setMsrp($price)                           // Manufacturer's Suggested Retail Price

                // Meta SEO title, keywords and description.
                ->setMetaTitle($name)                       // SEO Title
                ->setMetaKeyword($description)              // SEO Keywords  
                ->setMetaDescription($description)          // SEO Desacription
                ; // close product


            if ($product_type !== Mage_Catalog_Model_Product_Type::TYPE_CONFIGURABLE)
            {

                $product_model->setColor($attr_color);            // Color
                if ($attr_cod_product)
                {
                    _log("try to add code " . $cod_product); 
                    $product_model->setCodProduct($cod_product);
                }
                if ($attr_size)
                {
                    _log("try to add size " . $attr_size); 
                    $product_model->setSize($attr_size);
                } 

            }

            if($commit)
            {
                try 
                {
                    $product_model->save();
                } 
                catch(Exception $e)
                {
                    _log("ERROR product_model\n" . $e->getMessage());
                    try 
                    {
                        _log("Try with getResource -> save");
                        $product_model->getResource()->save($product_model);
                    }
                    catch(Exception $e)
                    {
                        _log("ERROR product_model resource\n" . $e->getMessage());
                    }
                }
            }

            _log(_GREEN("Producto " . ($product_type == Mage_Catalog_Model_Product_Type::TYPE_CONFIGURABLE ? 'Configurable' : 'Simple') . " creado " . $product_model->getId()));

            return $product_model;
        } 
        catch(Exception $e)
        {
            _log("ERROR product_model\n" . $e->getMessage());
        }

    }


    public function getOrCreateAttributes($attr_code, $attr_label, $attr_value = '', $attr_options = -1) 
    {
        //
        //  Get or create new attribute and options of attribute (if exists)
        //

        $attr_code = mb_strtolower(trim($attr_code));
        $attr_label = ucfirst(mb_strtolower(trim($attr_label)));

        if ( ! $attr_value == '' && ! is_array( $attr_value ) )
        {
            $attr_value = array(ucfirst(mb_strtolower(trim($attr_value))));
        }

        //$total_options = count($attr_value);
        $attr_model = Mage::getModel('catalog/resource_eav_attribute'); // load model

        // carga el attr

        // existe e cache?
        if ( array_key_exists($attr_code, $this->_cached_attribute) )
        {
            $attribute = $this->_cached_attribute[$attr_code];
            _log(_GRAY("Load cached attribute by code:") . " {code}", array('code' => $attr_code));
        } 
        elseif ( $attr = $attr_model->loadByCode('catalog_product', $attr_code) ) 
        {
            // Add new options for an exsiting attribute
            _log("El attributo con ese code {code} existe\n", array('code' => $attr_code));

            // Get all options of an attribute
            $attribute = Mage::getSingleton('eav/config')
                ->getAttribute(Mage_Catalog_Model_Product::ENTITY, $attr_code);

            $this->_cached_attribute[$attr_code] = $attribute;
        }
        else 
        {
            _log("!!\tEl attributo con code \"{code}\" no existe\n", array('code' => $attr_code));
            $id = $this->createAttribute($attr_code, $attr_label, $attr_options, -1, -1, $attr_value);
            return $id;
        }

        $_options = array();
        if ($attribute->usesSource()) 
        {
            $_options = $attribute->getSource()->getAllOptions(false);
        } 
        else 
        {
            _log("No tiene opciones, usesSource Code: {code}, ID: {id}\n", array('code'=>$attr_code, 'id'=> $attribute->getID()));
            // Crea si no existe el valor para el attr.
            $id = $this->createAttribute($attr_code, $attr_label, $attr_options, -1, -1, $attr_value);
        }

        $total_options = count($_options);

        _log(_GRAY("Itera sobre las opciones " . $total_options . " buscando para " . $attr_value[0]));

        if ( array_key_exists($attr_code . "-" . $attr_value[0], $this->_cached_attribute) )
        {
            $id = $this->_cached_attribute[$attr_code . "-" . $attr_value[0]];
        }
        elseif ($index_key = array_search($attr_value[0], array_column($_options, 'label')))
        {
            //_log("Attribute value exists, assign it to the product: " . $index_key . " -> " . var_export($_options[$index_key], true));
            $id = $_options[$index_key]['value'];
            $this->_cached_attribute[$attr_code . "-" . $attr_value[0]] = $id;
        } 
        else {
            $id = $this->createAttribute($attr_code, $attr_label, $attr_options, -1, -1, $attr_value);
            $this->_cached_attribute[$attr_code . "-" . $attr_value[0]] = $id;
        }

        return $id;

    }


    public function getAttributeSetByName($attributeSetName)
    {
        // 
        // Get an Attribute Set by name and return the ID
        //
        // @return int|false
        //

        $entityTypeId = Mage::getModel('eav/entity')->setType('catalog_product')->getTypeId();
        $attributeSetId = Mage::getModel('eav/entity_attribute_set')
            ->getCollection()
            ->setEntityTypeFilter($entityTypeId)
            ->addFieldToFilter('attribute_set_name', $attributeSetName)
            ->getFirstItem()
            ->getAttributeSetId();

        return $attributeSetId;

    }


    public function createAttribute($attributeCode, $labelText = '', $values = -1, $productTypes = -1, $setInfo = -1, $options = -1) /*{{{*/
    {
        //
        // Create an attribute.
        //
        // For reference, see Mage_Adminhtml_Catalog_Product_AttributeController::saveAction().
        //
        // @return int|false
        //  

        $attributeCode = trim($attributeCode);
        $labelText = trim($labelText);

        if($labelText == '' || $attributeCode == '')
        {
            _log("Can't import the attribute with an empty label or code.  LABEL=[$labelText]  CODE=[$attributeCode]");
            return false;
        }

        if($values === -1)
            $values = array();

        if($productTypes === -1)
            $productTypes = array();

        if($setInfo !== -1 && (isset($setInfo['SetID']) == false || isset($setInfo['GroupID']) == false))
        {
            _log("Please provide both the set-ID and the group-ID of the attribute-set if you'd like to subscribe to one.");
            return false;
        }

        //echo "Creating attribute [$labelText] with code [$attributeCode]."."\n";

        //>>>> Build the data structure that will define the attribute. See
        //     Mage_Adminhtml_Catalog_Product_AttributeController::saveAction().

        $data = array(
            'is_global'                     => '1',
            'frontend_input'                => 'select',
            'default_value_text'            => '',
            'default_value_yesno'           => '0',
            'default_value_date'            => '',
            'default_value_textarea'        => '',
            'is_unique'                     => '0',
            'is_required'                   => '0',
            'frontend_class'                => '',
            'is_searchable'                 => '1',
            'is_visible_in_advanced_search' => '1',
            'is_comparable'                 => '1',
            'is_used_for_promo_rules'       => '0',
            'is_html_allowed_on_front'      => '1',
            'is_visible_on_front'           => '0',
            'used_in_product_listing'       => '0',
            'used_for_sort_by'              => '0',
            'is_configurable'               => '0',
            'is_filterable'                 => '1',
            'is_filterable_in_search'       => '1',
            'backend_type'                  => 'varchar',
            'default_value'                 => '',
            'is_user_defined'               => '0',
            'is_visible'                    => '1',
            'is_used_for_price_rules'       => '0',
            'position'                      => '0',
            'is_wysiwyg_enabled'            => '0',
            'backend_model'                 => '',
            'attribute_model'               => '',
            'backend_table'                 => '',
            'frontend_model'                => '',
            'source_model'                  => '',
            'note'                          => '',
            'frontend_input_renderer'       => '',                      
        );

        // Now, overlay the incoming values on to the defaults.
        foreach($values as $key => $newValue)
            if(isset($data[$key]) == false)
            {
                _log("Attribute feature [$key] is not valid.");
                return false;
            }

            else
                $data[$key] = $newValue;

        // Valid product types: simple, grouped, configurable, virtual, bundle, downloadable, giftcard
        $data['apply_to']       = $productTypes;
        $data['attribute_code'] = $attributeCode;
        $data['frontend_label'] = $labelText;

        // Build the model.
        $model = Mage::getModel('catalog/resource_eav_attribute');

        $model->addData($data);

        if($setInfo !== -1)
        {
            $model->setAttributeSetId($setInfo['SetID']);
            $model->setAttributeGroupId($setInfo['GroupID']);
        }

        $entityTypeID = Mage::getModel('eav/entity')->setType('catalog_product')->getTypeId();
        $model->setEntityTypeId($entityTypeID);
        $model->setIsUserDefined(1);

        // Save
        $_value_id = false;
        try
        {
            $model->save();
        }
        catch(Exception $ex)
        {
            //_log($ex->getMessage());
            if($ex->getMessage() == "Attribute with the same code already exists.")
            {
                if(is_array($options))
                {
                    foreach($options as $_opt)
                    {
                        $_value_id = $this->addAttributeValue($attributeCode, $_opt);
                    }

                } 
                else {
                    _log("Attribute [$labelText] could not be saved: " . $ex->getMessage());
                    return false;
                }
            }
        }

        if(is_array($options))
        {
            foreach($options as $_opt)
            {
                $_value_id = $this->addAttributeValue($attributeCode, $_opt);
            }
        }


        $id = $model->getId();

        //echo "Attribute [$labelText] has been saved as ID ($id).\n";

        // Asssign to attribute set.
        $eav_model = Mage::getModel('eav/entity_setup','core_setup');
        $eav_model->addAttributeToSet(
            'catalog_product', 'Default', 'General', $attributeCode
        ); //Default = attribute set, General = attribute group

        $_id = $_value_id ? $_value_id : $id;

        //_log("Attr ID: {id}\n", array('id' => $_id)); 
        return $_id;
    }/*}}}*/


    public function addAttributeValue($arg_attribute, $arg_value) /*{{{*/
    {
        $attribute_model = Mage::getModel('eav/entity_attribute');
        $attribute_code = $attribute_model->getIdByCode('catalog_product', $arg_attribute);
        $attribute  = $attribute_model->load($attribute_code);

        if(!$this->attributeValueExists($arg_attribute, $arg_value))
        {
            $value['option'] = array($arg_value, $arg_value);
            $result = array('value' => $value);
            $attribute->setData('option',$result);
            $attribute->save();
        }

        $attribute_options_model= Mage::getModel('eav/entity_attribute_source_table') ;
        $attribute_table = $attribute_options_model->setAttribute($attribute);
        $options = $attribute_options_model->getAllOptions(false);

        foreach($options as $option)
        {
            if ($option['label'] == $arg_value)
            {
                return $option['value'];
            }
        }
        return false;
    }/*}}}*/


    public function attributeValueExists($arg_attribute, $arg_value) /*{{{*/
    {
        $attribute_model = Mage::getModel('eav/entity_attribute');
        $attribute_options_model = Mage::getModel('eav/entity_attribute_source_table') ;

        $attribute_code = $attribute_model->getIdByCode('catalog_product', $arg_attribute);
        $attribute = $attribute_model->load($attribute_code);

        $attribute_table = $attribute_options_model->setAttribute($attribute);
        $options = $attribute_options_model->getAllOptions(false);

        foreach($options as $option)
        {
            if ($option['label'] == $arg_value)
            {
                return $option['value'];
            }
        }

        return false;
    }/*}}}*/


    public function getOrCreateCategories($stringId, $parentId = null, $commit = true) /*{{{*/
    {
        //
        //  Resolve categories from a string based categories splited by slash "/"
        //  if category does not exists try to create it.
        //

        //global PARENT_ID;

        $parentId = $parentId ? $parentId : PARENT_ID;

        if( ! is_array($stringId) )
        {
            $_stringIds = split("/", $stringId);
        } 
        else 
        {
            $_stringIds = $stringId;
        }

        $_arrayIds = array();

        for($i = 0; $i < count($_stringIds); $i++)
        {
            if($i == 0 || !$commit)
            {
                $_parentId = $parentId;
            }
            else 
            {
                $_parentId = $_arrayIds[$i-1];
            }

            $_str_category = ucfirst(mb_strtolower($_stringIds[$i]));

            // chequea en cache si no existe así no hace hit en la DB
            if ( ! array_key_exists($_parentId . "-" . $_str_category, $this->_cached_category) )
            {

                if ( ! ( $_category = $this->_categoryExists($_str_category, $_parentId) ) )
                {

                    _log("Category \"" . $_str_category . "\" does not exists, try to ceate it");

                    if ($commit) $_category = $this->_createCategory($_str_category, slugify($_str_category), $_parentId);

                } 
                else 
                {
                    _log("Category \"" . $_str_category . "\" exists, SKIP");
                }

                if ($commit) $_arrayIds[$i] = $_category->getId();

                // guarda en cache
                $this->_cached_category[$_parentId . "-" . $_str_category] = $_category->getId();

            } 
            else 
            {
                $_arrayIds[$i] = $this->_cached_category[$_parentId . "-" . $_str_category]; 
            }
        }

        return $_arrayIds;
    }/*}}}*/


    public function _categoryExists($name, $parentId) /*{{{*/
    {
        //
        // Check if category exists
        //

        $parentCategory = Mage::getModel('catalog/category')->load($parentId);
        $childCategory = Mage::getModel('catalog/category')->getCollection()
            ->addAttributeToFilter('is_active', true)
            ->addIdFilter($parentCategory->getChildren())
            ->addAttributeToFilter('name', $name)
            ->getFirstItem()    // Assuming your category names are unique ??
            ;

        if (null !== $childCategory->getId())
        {
            _log("[SKIP] Category: " . $childCategory->getData('name') . " exists");
            return $childCategory;
        } 
        else 
        {
            _log("Category not found");
            return false;
        }

        return false;
    }/*}}}*/


    public function _createCategory($name, $url, $parentId) /*{{{*/
    {
        //
        //  Try to create a new Category
        //

        try 
        {
            $category = Mage::getModel('catalog/category');
            $category->setName($name);
            $category->setUrlKey($url);
            $category->setIsActive(1);
            $category->setDisplayMode('PRODUCTS');
            $category->setIsAnchor(1); //for active anchor
            $category->setStoreId(Mage::app()->getStore()->getId());
            $parentCategory = Mage::getModel('catalog/category')->load($parentId);
            $category->setPath($parentCategory->getPath());
            $category->save();
            return $category;
        } 
        catch(Exception $e)
        {
            print_r($e);
        }

        return false;
    }/*}}}*/


    public function getMenu() /*{{{*/
    {
        // Options for command line
        $shortopts  = "";
        $shortopts .= "f:";     // path to csv file
        $shortopts .= "i::";    // path for images
        $shortopts .= "s::";    // path for images
        $shortopts .= "c";      // create product
        $shortopts .= "a";      // add category if not exists
        $shortopts .= "t";      // add attribute selector
        $shortopts .= "h";      // help

        $longopts  = array(
            "file:",            // path to csv file
            "images-path::",    // path for images
            "csv-split::",      // CSV split
            "use-ftp::",        // use ftp
            "ftp-server:",      // ftp server
            "ftp-user:",        // ftp user
            "ftp-pass:",        // ftp pass
            "ftp-path:",        // ftp path
            "file-date:",       // file date as ddmmYYYY
            "commit",           // create product
            "add-category",     // add category if not exists
            "add-attribute",    // add attribute selector
            "attribute-code",   // attribute code
            "attribute-label",  // attribute labels
            "attribute-values", // attribute values
            "store:",            // set Store ID
            "website:",          // set Website ID
            "category:",         // set Root Category ID
            "help",             // help
        );

        $options = getopt($shortopts, $longopts);

        if (!$options || array_key_exists("h", $options) || array_key_exists("help", $options))
        { 
            print(
                "Usage:\r\n\r\n".
                "php sync_products.php [options] -f file.csv\r\n".
                "\r\n".
                "-h, --help                              This help\r\n".
                "-c, --commit                            Commit make changes permanent.\r\n".
                "-s, --csv-split                         CSV split by , or ; (default \";\").\r\n".
                "-i, --images-path=path/to/images        Path for images\r\n".
                "\r\n".
                "--use-ftp,\r\n".
                "--ftp-server=server.com\r\n".
                "--ftp-user=user\r\n".
                "--ftp-pass=mypass\r\n".
                "--ftp-path=/route/to/path/\r\n".
                "--file-date=ddmmYYYY\r\n".
                "\r\n".
                "Store, Website and Root category\r\n".
                "\r\n".
                "--store=1\r\n".
                "--website=1\r\n".
                "--category=1\r\n"
            );
            //    -a, --add-category                      Create [sub]category if not exists.
            //    
            //    -t, --add-attribute                     Create attribute
            //        --attribute-code                    Code for new attribute (or update values)
            //        --attribute-label                   Label for attribute
            //        --attribute-options                 Options for attribute
            //

            exit(1);

        } 

        // Prevalidate options

        $this->opt_ftp = array();

        $this->opt_commit = ( 
            getattr($options['c'], null) == null ? 
            getattr($options['commit'], false) : getattr($options['c'], false) 
        ); 

        $this->opt_images_path = ( 
            getattr($options['i'], null) == null ? 
            getattr($options['images-path'], '') : getattr($options['i'], '') 
        ); 

        $this->opt_csv = ( 
            getattr($options['s'], null) == null ? 
            getattr($options['csv-split'], ';') : getattr($options['s'], ';') 
        ); 


        if ($file_data = getattr($options['f'], false)) 
        {
            _log($file_data);
            $this->loadFileData($file_data);
        }
        elseif ($file_data = getattr($options['file'], false)) 
        {
            _log($file_data);
            $this->loadFileData($file_data);
        }
        
        if (array_key_exists('use-ftp', $options))
        { 
            $this->opt_ftp = array(
                'server'    => getattr($options['ftp-server']),
                'user'      => getattr($options['ftp-user']),
                'pass'      => getattr($options['ftp-pass']),
                'path'      => getattr($options['ftp-path'], CONFIG_DEFAULT_FTP_PATH),
            );

            $date = getattr($options['file-date'], date("dmY"));

            $ftp = new ftp($this->opt_ftp['server']);
            $ftp->ftp_login($this->opt_ftp['user'], $this->opt_ftp['pass']);
            echo $ftp->ftp_pasv(true);
            
            $the_file = "catalogo-".$date.".xlsx";
            $remote_file = join(DS, array($this->opt_ftp['path'], $the_file)); // category / sub_category / 
            $local_file = MEDIA_STORAGE_POINT . $the_file;

            _log("Descargando el excel " . $the_file);
            try
            {
                if (!file_exists($local_file))
                {
                    if ($ftp->ftp_get($local_file, $remote_file, FTP_BINARY))
                    {
                        _log("Archivo guardado en el local: " . $local_file);
                        $ftp->close();
                    }
                    else
                    {
                        _log(_RED("ERROR descargando el excel " . $the_file));
                    }
                }
                else 
                {
                    _log(_GRAY("Carga el archivo desde el local: " . $local_file));
                }

                $this->loadFileData($local_file);

            }
            catch(Exception $e)
            {
                _log(_RED("ERROR Descargando el excel " . $the_file . " el archivo no existe o no es accesible."));
                _log(_RED($e->getMessage()));
            }

        }

        // store
        
        $_store_id = getattr($options['store'], STORE_ID);
        $_website_id = getattr($options['website'], WEBSITE_ID);
        $_category_id = getattr($options['category'], PARENT_ID);

        _log("Set STORE_ID con el ID: " . $_store_id);

        $this->STORE_DATA = Mage::app()->getStore($_store_id)->getData();

        _log(var_export($this->STORE_DATA, 1));

        $this->sync();

        exit(0);
    }/*}}}*/

}

// UTILS/*{{{*/
function getattr(&$var, $default=null)
{
    return isset($var) ? $var : $default;
}

function pprint($str, $args=array())
{
    $_str = $str;
    foreach($args as $key => $val)
    {
        $_str = preg_replace('[{'.$key.'}]', $val, $_str);
    }
    return $_str;
}

function old_slugify($text)
{
    // replace non letter or digits by -
    $text = preg_replace('~[^\pL\d]+~u', '-', $text);
    // transliterate
    $text = iconv('utf-8', 'us-ascii//TRANSLIT', $text);
    // remove unwanted characters
    $text = preg_replace('~[^-\wñáéíóú]+~', '', $text);
    // trim
    $text = trim($text, '-');
    // remove duplicate -
    $text = preg_replace('~-+~', '-', $text);
    // lowercase
    $text = mb_strtolower($text);
    if (empty($text))
    {
        return $text;
    }
    return $text;
}

function slugify($string)
{
    return strtolower(
        trim(
            preg_replace('~[^0-9a-z]+~i', '-', 
                preg_replace('~&([a-z]{1,2})(acute|cedil|circ|grave|lig|orn|ring|slash|th|tilde|uml);~i', '$1', 
                    htmlentities($string, ENT_QUOTES, 'UTF-8')
                )
            ), 
            ' '
        )
    );
}

function _log($message, $args=array(), $stdout = true)
{
    $message = pprint($message, $args);

    if ($stdout)
    {
        echo "[DEBUG] " . $message . "\r\n";
    }
    else 
    {
        Mage::log($message, null, 'sync_products.log');
    }
}

function prompt($message, $choices = null)
{
    $handle = fopen ("php://stdin","r");

    if (!$choices)
    {

        echo _BROWN($message .": ");
        $line = trim(fgets($handle));
        $choices = array('/^([Yy]|[Yy]es|[Ss]|[Ss]i)$/' => true, '/^([Nn]|[Nn]o)$/' => false);

        foreach($choices as $reg => $val)
        {
            if(preg_match($reg, trim($line))) return $val;
        }

        return null;

    } 
    else 
    {
        // array key -> (title, function)

        echo _BROWN($message .": \r\n\r\n");

        foreach($choices as $key => $opt)
        {
            echo "\t" . $key . ") " . $opt[0] . "\r\n";
        }

        echo "\r\nIngrese opción: ";
        $line = trim(fgets($handle));

        //_log("\r\n". $line);

        if (array_key_exists($line, $choices))
        {
            return $line; //$choices[$line];
        } 
        else return prompt($message, $choices);

    }

    fclose($handle);

}/*}}}*/

function boostrap()/*{{{*/
{
    // init requires
    try 
    {
        // script en base /
        if (file_exists('app/Mage.php' )) 
        {
            require_once("app/Mage.php");
        }
        // script en script/ 
        elseif (file_exists('../app/Mage.php' )) 
        {
            require_once("../app/Mage.php");
        } 
        elseif (file_exists('/var/www/magento/app/Mage.php' )) 
        {
            require_once('/var/www/magento/app/Mage.php');
        }
        else 
        {
            throw new Exception ('[/var/www/magento/]app/Mage.php does not exist');
        }
    }
    catch(Exception $e)
    {    
        echo "\r\nMessage : " . $e->getMessage();
        echo "\r\nCode : " . $e->getCode();
        echo "\r\n";
        exit(1);
    }

    //$mageFilename = 'app/Mage.php';
    //require_once $mageFilename;

    Mage::setIsDeveloperMode(true);
    ini_set('display_errors', 1);
    umask(0);
    Mage::app('admin');
    Mage::register('isSecureArea', 1);
    Mage::app()->setCurrentStore(Mage_Core_Model_App::ADMIN_STORE_ID);
    Mage::app()->getStore(STORE_ID)->getData();

    define('DEFAULT_ATTRIBUTES', 4); // default
    define('DEFAULT_PRODUCT_TYPE', Mage_Catalog_Model_Product_Type::TYPE_SIMPLE); // default product type
    define('DEFAULT_PRODUCT_STATUS', 1); // product status (1 - enabled, 2 - disabled)
    define('DEFAULT_PRODUCT_VISIBILITY', Mage_Catalog_Model_Product_Visibility::VISIBILITY_BOTH); // Catalog and Search visibility

}
/*}}}*/

// Start
$commands = new CommandUtilMagento;
$commands->init();
