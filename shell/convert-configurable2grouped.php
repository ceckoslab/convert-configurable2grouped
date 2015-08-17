<?php
/**
 * @author      Tsvetan Stoychev <ceckoslab@gmail.com>
 * @author      Attila Fabrik <fabrik85@gmail.com>
 * @website     http://www.ceckoslab.com
 * @license     http://opensource.org/licenses/osl-3.0.php Open Software Licence 3.0 (OSL-3.0)
 */

require_once 'abstract.php';

class CeckosLab_Convert_Configurable2Grouped
    extends Mage_Shell_Abstract
{

    /** @var array */
    protected $_configurableProductIds = array();

    /** @var int|null */
    protected $_positionLinkAttributeId;

    /** @var array */
    protected $_cleanConfigurableAttributes = array(
        'group_price',
        'minimal_price',
        'msrp',
        'msrp_display_actual_price_type',
        'msrp_enabled',
        'price',
        'special_from_date',
        'special_price',
        'special_to_date',
        'tax_class_id',
        'tier_price',
    );

    /** @var array */
    protected $_configurableAttributeModels = array();

    /** @var array */
    protected $_processedProducts = array();

    public function run()
    {
        if ($this->getArg('convert_all')) {
            $this->_init();

            foreach ($this->_configurableProductIds as $parentId) {
                $this->_convertConfigurable2Grouped($parentId);
            }

            $this->_renderInfoTable();
        } else {
            echo $this->usageHelp();
        }
    }

    /**
     *
     */
    protected function _init()
    {
        $this->_configurableProductIds  = $this->_getConfigurableProductIds();
        $this->_positionLinkAttributeId = $this->_getPositionLinkAttributeId();

        foreach ($this->_cleanConfigurableAttributes as $code) {
            $this->_configurableAttributeModels[] = $attribute = Mage::getSingleton('eav/config')->getAttribute('catalog_product', $code);
        }

    }

    /**
     * @param int $parentId
     */
    protected function _convertConfigurable2Grouped($parentId)
    {
        $childrenIds = $this->_getConfigurableChildrenIds($parentId);

        if (!empty($childrenIds)) {
            $this->_processedProducts[$parentId]['parent_id'] = $parentId;
            $this->_processedProducts[$parentId]['children_ids'] = $childrenIds;
            $this->_updateMainProductEntityFields($parentId);
            $this->_processedProducts[$parentId]['grouped_simple_links'] = $this->_buildGroupedSimpleProductLinks($parentId, $childrenIds);
            $this->_processedProducts[$parentId]['destroyed_super_links'] = $this->_destroySuperLinks($parentId);
            $this->_processedProducts[$parentId]['destroyed_super_attribute_relations'] = $this->_destroySuperAttributeRelations($parentId);
            $this->_processedProducts[$parentId]['destroyed_custom_options'] = $this->_destroyCustomOptions($parentId);
            $this->_processedProducts[$parentId]['clean_unused_attributes'] = $this->_cleanUnusedAttributeValuesForGroupedProductType($parentId);
            $this->_processedProducts[$parentId]['positions_linked'] = $this->_linkPositions($parentId);
        }
    }

    /**
     * @param int $parentId
     * @return array
     */
    protected function _getConfigurableChildrenIds($parentId)
    {
        $select = $this->_getReadConnection()->select()
            ->from(array('l' => $this->_getResource()->getTableName('catalog/product_super_link')), array('product_id', 'parent_id'))
            ->join(
                array('e' => $this->_getResource()->getTableName('catalog/product')),
                'e.entity_id = l.product_id',
                array()
            )
            ->where('parent_id = ?', $parentId);

        return $this->_getReadConnection()->fetchCol($select);
    }

    /**
     * @param int $parentId
     */
    protected function _updateMainProductEntityFields($parentId)
    {
        /**
         * Update product type and options settings
         */
        $updateCondition = $this->_getWriteConnection()->quoteInto('entity_id = ?', $parentId);

        $this->_getWriteConnection()->update(
            $this->_getResource()->getTableName('catalog/product'),
            array(
                'type_id'          => 'grouped',
                'has_options'      => 0,
                'required_options' => 0
            ),
            $updateCondition
        );
    }

    /**
     * @param int $parentId
     * @param array $childrenIds
     *
     * @return int
     */
    protected function _buildGroupedSimpleProductLinks($parentId, array $childrenIds)
    {
        /**
         * Creating catalog_product_link
         */
        $values = array();

        foreach ($childrenIds as $childId) {
            $values[] = array(
                'product_id'        => $parentId,
                'linked_product_id' => $childId,
                'link_type_id'      => Mage_Catalog_Model_Product_Link::LINK_TYPE_GROUPED
            );
        }

        return $this->_getWriteConnection()
            ->insertMultiple($this->_getResource()->getTableName('catalog/product_link'), $values);
    }

    /**
     * @param int $parentId
     *
     * @return int
     */
    protected function _destroySuperLinks($parentId)
    {
        /** Delete configurable super links */
        return $this->_getWriteConnection()
            ->delete(
                $this->_getResource()->getTableName('catalog/product_super_link'),
                $this->_getWriteConnection()->quoteInto('parent_id = ?', $parentId)
            );
    }

    /**
     * @param int $parentId
     *
     * @return int
     */
    protected function _destroySuperAttributeRelations($parentId)
    {
        /** Delete unused catalog_product_super_attribute */
        return $this->_getWriteConnection()
            ->delete(
                $this->_getResource()->getTableName('catalog/product_super_attribute'),
                $this->_getWriteConnection()->quoteInto('product_id = ?', $parentId)
            );
    }

    /**
     * @param int $parentId
     *
     * @return int
     */
    protected function _destroyCustomOptions($parentId)
    {
        /** Delete custom options and records in related tables */
        return $this->_getWriteConnection()
            ->delete(
                $this->_getResource()->getTableName('catalog/product_option'),
                $this->_getWriteConnection()->quoteInto('product_id = ?', $parentId)
            );

    }

    /**
     * @param int $parentId
     *
     * @return int
     */
    protected function _cleanUnusedAttributeValuesForGroupedProductType($parentId)
    {
        $affectedRows = 0;

        /**
         * Delete unused attribute values
         */
        foreach ($this->_configurableAttributeModels as $attr) {
            $attrClearCondition = array(
                'attribute_id =?'   => $attr->getId(),
                'entity_id =?'      => $parentId
            );

            $affectedRows += $this->_getWriteConnection()->delete(
                $attr->getBackendTable(),
                $attrClearCondition
            );
        }

        return $affectedRows;
    }

    /**
     * @return array
     */
    protected function _getConfigurableProductIds()
    {
        return Mage::getModel('catalog/product')
            ->getCollection()
            ->addAttributeToFilter('type_id', array('eq' => 'configurable'))
            ->getAllIds();
    }

    /**
     * @param int $parentId
     *
     * @return int
     */
    protected function _linkPositions($parentId)
    {

        $linkedProductData = $this->_getReadConnection()->fetchPairs($this->_getReadConnection()->select()
            ->from($this->_getResource()->getTableName('catalog/product_link'), array(
                'linked_product_id', 'link_id'
            ))
            ->where(
                $this->_getWriteConnection()->quoteInto('product_id IN (?) AND ', array($parentId)) .
                $this->_getWriteConnection()->quoteInto('link_type_id = ?', Mage_Catalog_Model_Product_Link::LINK_TYPE_GROUPED)
            ));

        $productPrices = $this->_fetchChildrenIdsSortedByPrice($linkedProductData);

        $pos = 0;

        $positionsData = array();

        foreach($productPrices as $key => $value) {
            $positionsData[] = array(
                'link_id'                   => $linkedProductData[$key],
                'product_link_attribute_id' => $this->_positionLinkAttributeId,
                'value'                     => $pos
            );

            $pos++;
        }

        return $this->_getWriteConnection()
            ->insertOnDuplicate($this->_getResource()->getTableName('catalog/product_link_attribute_int'), $positionsData, array('link_id', 'product_link_attribute_id'));
    }

    /**
     * @param array $linkedProductData
     * @return array
     */
    protected function _fetchChildrenIdsSortedByPrice(array $linkedProductData)
    {
        $productIdPricesPair = array();

        foreach($linkedProductData as $childProductId => $linkId) {
            $productIdPricesPair[$childProductId] = Mage::getModel('catalog/product')
                ->getCollection()
                ->addAttributeToSelect('price')
                ->addAttributeToFilter('entity_id', array('eq' => $childProductId))
                ->getFirstItem()
                ->getPrice();
        }

        asort($productIdPricesPair);

        return $productIdPricesPair;
    }

    /**
     * @return int
     */
    protected function _getPositionLinkAttributeId()
    {
        $select = $this->_getReadConnection()->select()
            ->from($this->_getResource()->getTableName('catalog/product_link_attribute'), array('product_link_attribute_id'))
            ->where('link_type_id = ? AND product_link_attribute_code = "position"', Mage_Catalog_Model_Product_Link::LINK_TYPE_GROUPED);

        return $this->_getReadConnection()->fetchOne($select);
    }

    /**
     * @return Mage_Core_Model_Resource $resource
     */
    protected function _getResource()
    {
        return Mage::getSingleton('core/resource');
    }

    /**
     * Getter of the write connection
     *
     * @return Varien_Db_Adapter_Interface
     */
    protected function _getWriteConnection()
    {
        return $this->_getResource()->getConnection(Mage_Core_Model_Resource::DEFAULT_WRITE_RESOURCE);
    }

    /**
     * Getter of the read connection
     *
     * @return Varien_Db_Adapter_Interface
     */
    protected function _getReadConnection()
    {
        return $this->_getResource()->getConnection(Mage_Core_Model_Resource::DEFAULT_READ_RESOURCE);
    }

    protected function _renderInfoTable()
    {
        $table = new Zend_Text_Table(array('columnWidths' => array(15, 15, 13, 15, 20, 18, 20, 13)));

        $headerRow = array('Parent Id', 'Children Ids', '[C] Grouped Simple Links', '[X] Super Links', '[X] Super Attribute', '[X] Custom Options', '[X] Attribute Values', '[C] Positions');

        $linesCounter = 0;

        foreach($this->_processedProducts as $data) {
            if(0 == $linesCounter % 20) {
                //Append Header on 0 and every 20 lines
                $table->appendRow($headerRow);
            }

            $row = new Zend_Text_Table_Row();

            foreach($data as $value) {
                $value = is_array($value) ? implode(',', $value) : (string) $value;
                $row->appendColumn(new Zend_Text_Table_Column($value));
            }

            $table->appendRow($row);
            $linesCounter++;
        }

        echo $table;
    }

    /**
     * Retrieve Usage Help Message
     *
     */
    public function usageHelp()
    {
        return <<<USAGE
Please make a DB backup before you use this script!

Usage:  php -f convert-configurable2grouped.php convert_all

  convert_all   Converts all configurable products to grouped products
  -h            Short alias for help
  help          This help


USAGE;
    }

}

$shell = new CeckosLab_Convert_Configurable2Grouped();
$shell->run();