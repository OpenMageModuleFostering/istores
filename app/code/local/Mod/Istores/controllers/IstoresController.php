<?php
class Mod_Istores_IstoresController extends Mage_Adminhtml_Controller_Action {

    public function exportAction() {
        $dir_name = './var/istores/';
        
        set_time_limit(0);
        ini_set('memory_limit', '512M');
        
        if(file_exists($dir_name)) {
            $dir = opendir($dir_name);
            while(false !== ($file = readdir($dir))) {
                if($file != '.' && $file != '..' && strpos($file, '.xml')) {
                    $name = $file;
                }
            }
        } else {
            mkdir($dir_name, 0777);
            $fp = fopen($dir_name.'/.htaccess', 'w');
            $data = "Order deny,allow\nAllow from all\nOptions -Indexes";
            fputs($fp, $data);
            fclose($fp);
        }
        
        if(!isset($name)) {
            $name = md5(time()).'.xml';
            $link = '';
        } else {
            $url = Mage::getBaseUrl(Mage_Core_Model_Store::URL_TYPE_WEB).'var/istores/';
            $link = '<p style="margin-top: 20px; font-size: 14px"><strong>Your XML URL: <a href="'.$url.$name.'">download link</a></strong></p>';
            $link .= '<input type="text" size="70" value="'.$url.$name.'" />';
        }
        
        $info = '<p style="margin-top: 3px; font-size: 11px;">It may take up to 5 minutes</p>';
        
        $this->loadLayout();
        $block = $this->getLayout()
            ->createBlock('core/text', 'blok-istores')
            ->setText('<form action = "'.$this->getUrl('/istores/export/get_file/xml').'" method = "get"><input type = "submit" value = "Generate xml export file" /></form>'.$info.$link);
        $this->_addContent($block);
        $this->_setActiveMenu('iStores');
        
        // *******************
        $read = Mage::getSingleton('core/resource')->getConnection('core_read');
        $select = $read->select()->from(array('a' => 'eav_attribute'), array('attribute_id', 'attribute_code', 'frontend_label'))
            ->join(array('ao' => 'eav_attribute_option'), 'a.attribute_id = ao.attribute_id', array())
            ->group('attribute_id');

        $temp = $read->fetchAll($select); // all configurable attributes
        foreach($temp as $v) {
            $attr[$v['attribute_code']] = array('attribute_id' => $v['attribute_id'], 'frontend_label' => $v['frontend_label']);
        }
        
        if($this->getRequest()->getParam('get_file')) {
            $products_collection = Mage::getModel('catalog/product')
                ->getCollection()
                ->addAttributeToSelect('*');
    
            $xml = new DOMDocument('1.0', 'UTF-8');
            $xml_catalog = $xml->createElement('catalog');

            $xml_products = $xml->createElement('products');
            $xml_catalog->appendChild($xml_products);

            foreach($products_collection as $product) {
                $xml_product = $xml->createElement('product');
                $item = $product->getData();
                
                $productId = $product->getId();
                $childProducts = Mage::getModel('catalog/product_type_configurable')->getChildrenIds($productId);
                $parents = Mage::getModel('catalog/product_type_configurable')->getParentIdsByChild($productId);

                // all images
                $images = Mage::getModel('catalog/product')->load($productId)->getMediaGallery();

                $xml_images = $xml->createElement('images');
                $xml_product->appendChild($xml_images);

                foreach($images['images'] as $image) {
                    $xml_image = $xml->createElement('image', Mage::getBaseUrl(Mage_Core_Model_Store::URL_TYPE_MEDIA).'catalog/product'.$image['file']);
                    $xml_images->appendChild($xml_image);

                }
                //

                if($childProducts[0]) {
                    $children = implode(',', $childProducts[0]);
                    $xml_children = $xml->createElement('children', $children);
                    $xml_product->appendChild($xml_children);
                }
                
                if($parents) {
                    $xml_parent = $xml->createElement('parent', $parents[0]);
                    $xml_product->appendChild($xml_parent);
                }
                
                $i = 0;
                $j = 0;
                $last_value = null;
                
                foreach($item as $key => $value) {
                    if($key == 'thumbnail' || $key == 'small_image' || $key == 'image') { // if image
                        continue;
                    /*
                        // thumbnail, small_image, image
                        if($value != 'no_selection' && $last_value != $value) {
                            if(!$i) {
                                $xml_images = $xml->createElement('images');
                                $xml_product->appendChild($xml_images);
                            }
                            $xml_image = $xml->createElement('image', Mage::getBaseUrl(Mage_Core_Model_Store::URL_TYPE_MEDIA).'catalog/product'.$value);
                            $xml_images->appendChild($xml_image);
                            $last_value = $value;
                            $i++;
                        }
                    */
                    } elseif(in_array($key, array_keys($attr)) && $key != 'description') { // if configurable attribute
                        if(!$j) {
                            $xml_attributes = $xml->createElement('attributes');
                            $xml_product->appendChild($xml_attributes);
                        }
                        $xml_attribute = $xml->createElement('attribute');
                        $xml_attributes->appendChild($xml_attribute);
                        
                        $xml_id = $xml->createElement('id', $attr[$key]['attribute_id']);
                        $xml_code = $xml->createElement('code', $key);
                        $xml_label = $xml->createElement('label', $attr[$key]['frontend_label']);
                        $xml_value = $xml->createElement('value', $product->getAttributeText($key));
                        $xml_key = $xml->createElement('key', $value);
                        
                        $xml_attribute->appendChild($xml_id);
                        $xml_attribute->appendChild($xml_code);
                        $xml_attribute->appendChild($xml_label);
                        $xml_attribute->appendChild($xml_value);
                        $xml_attribute->appendChild($xml_key);
                        $j++;
                    } else {
                        $value = htmlspecialchars($value);
                        $value = preg_replace('/[^\x{0009}\x{000a}\x{000d}\x{0020}-\x{D7FF}\x{E000}-\x{FFFD}]+/u', ' ', $value);
                        $xml_{$key} = $xml->createElement($key, $value);
                        $xml_product->appendChild($xml_{$key});
                    }
                }
                
                foreach($product->getCategoryIds() as $category_id) {
                    $xml_category = $xml->createElement('category', $category_id);
                    $xml_product->appendChild($xml_category);
                }

                $xml_products->appendChild($xml_product);
            }
            $xml->appendChild($xml_catalog);

            // all categories
            $xml_categories = $xml->createElement('categories');
            $xml_catalog->appendChild($xml_categories);
            
            $categories = Mage::getModel('catalog/category')
                ->getCollection()
                ->addAttributeToSelect('*')
                ->addAttributeToFilter('level', array('nin' => array(0,1)));
        
            foreach($categories as $category) {
                $xml_category = $xml->createElement('category');
                $xml_categories->appendChild($xml_category);
        
                $xml_category_id = $xml->createElement('category_id', $category->getId());
                $xml_category->appendChild($xml_category_id);
        
                $xml_parent_id = $xml->createElement('parent_id', $category->getParentId());
                $xml_category->appendChild($xml_parent_id);
                
                $xml_name = $xml->createElement('name', $category->getName());
                $xml_category->appendChild($xml_name);
                
                $xml_is_active = $xml->createElement('is_active', $category->getIsActive());
                $xml_category->appendChild($xml_is_active);
                
                $xml_position = $xml->createElement('position', $category->getPosition());
                $xml_category->appendChild($xml_position);
        
                $xml_level = $xml->createElement('level', $category->getLevel());
                $xml_category->appendChild($xml_level);
                
                $xml_children = $xml->createElement('children', $category->getChildren());
                $xml_category->appendChild($xml_children);
            }
            //

            $file = $dir_name.$name;
            $xml->save($file);
            Mage::app()->getResponse()->setRedirect(Mage::helper('adminhtml')->getUrl('/istores/export'));
        }
        $this->renderLayout();
    }

    public function dashboardAction() {
        $this->loadLayout();
        $block = $this->getLayout()
            ->createBlock('core/text', 'blok-istores')
            ->setText('Coming soon...');
        $this->_addContent($block);
        $this->_setActiveMenu('iStores');
        $this->renderLayout();
    }
}
