<?php

namespace BigBridge\ProductImport\Model\Resource\Storage;

use BigBridge\ProductImport\Api\Data\Product;
use BigBridge\ProductImport\Model\Db\Magento2DbConnection;
use BigBridge\ProductImport\Model\Resource\MetaData;

/**
 * @author Patrick van Bergen
 */
class TierPriceStorage
{
    /** @var  Magento2DbConnection */
    protected $db;

    /** @var  MetaData */
    protected $metaData;

    public function __construct(
        Magento2DbConnection $db,
        MetaData $metaData)
    {
        $this->db = $db;
        $this->metaData = $metaData;
    }

    /**
     * @param Product[] $products
     */
    public function insertTierPrices(array $products)
    {
        $this->upsertTierPrices($products);
    }

    /**
     * @param Product[] $products
     */
    public function updateTierPrices(array $products)
    {
        // updating tier prices relies on the unique key (`entity_id`,`all_groups`,`customer_group_id`,`qty`,`website_id`)
        // this handles the inserts and updates

        $this->upsertTierPrices($products);

        // the deletes are handled by first collecting products that have more stored values than that should be currently active

        $productsWithOutdatedStoredTierPrices = $this->findProductsWithDeletableTierPrices($products);

        // for these products we check the individual tier prices to see if they are outdated, and remove them

        $this->removeOutdatedStoredTierPrices($productsWithOutdatedStoredTierPrices);
    }

    /**
     * @param Product[] $products
     * @return array
     */
    protected function findProductsWithDeletableTierPrices(array $products)
    {
        if (empty($products)) {
            return [];
        }

        $productIds = array_column($products, 'id');

        // count the number of stored tier prices per product
        $counts = $this->db->fetchMap("
            SELECT `entity_id`, COUNT(*) FROM `{$this->metaData->tierPriceTable}`
            WHERE `entity_id` IN (" . $this->db->getMarks($productIds) . ")
            GROUP BY `entity_id`
        ", $productIds);

        // the products whose outdated tier prices must be removed from the database
        $resultProducts = [];

        foreach ($products as $product) {

            $tierPrices = $product->getTierPrices();
            if ($tierPrices !== null) {

                if (array_key_exists($product->id, $counts)) {
                    $storedTierPriceCount = $counts[$product->id];
                    if ($storedTierPriceCount != count($tierPrices)) {
                        $resultProducts[] = $product;
                    }
                }
            }
        }

        return $resultProducts;
    }

    /**
     * @param Product[] $products
     */
    protected function upsertTierPrices(array $products)
    {
        $values = [];

        foreach ($products as $product) {

            $tierPrices = $product->getTierPrices();
            if ($tierPrices !== null) {

                $entityId = $product->id;

                foreach ($tierPrices as $tierPrice) {
                    $values[] = $entityId;
                    $values[] = (int)($tierPrice->getCustomerGroupId() === null);
                    $values[] = (int)$tierPrice->getCustomerGroupId();
                    $values[] = $tierPrice->getQuantity();
                    $values[] = $tierPrice->getValue();
                    $values[] = $tierPrice->getWebsiteId();
                }
            }
        }

        $this->db->insertMultipleWithUpdate($this->metaData->tierPriceTable, ['entity_id', 'all_groups', 'customer_group_id', 'qty', 'value', 'website_id'], $values, "
            `value` = VALUES(`value`)");
    }

    /**
     * @param Product[] $products
     */
    protected function removeOutdatedStoredTierPrices(array $products)
    {
        if (empty($products)) {
            return;
        }

        $productIds = array_column($products, 'id');

        // collect tier prices that are stored in the database
        $storedTierPriceData = $this->db->fetchAllAssoc("
            SELECT `value_id`, `entity_id`, `all_groups`, `customer_group_id`, `qty`, `value`, `website_id`,
                CONCAT_WS(' ', `entity_id`, `all_groups`, `customer_group_id`, `qty`, `value`, `website_id`) as serialized
            FROM `{$this->metaData->tierPriceTable}`
            WHERE `entity_id` IN (" . $this->db->getMarks($productIds) . ")
        ", $productIds);

        // serialize current tier prices
        $activeTierPriceData = [];
        foreach ($products as $product) {
            foreach ($product->getTierPrices() as $tierPrice) {
                $serialized = sprintf("%s %s %s %s %s %s",
                    $product->id,
                    (int)($tierPrice->getCustomerGroupId() === null),
                    (int)$tierPrice->getCustomerGroupId(),
                    sprintf("%.4f", $tierPrice->getQuantity()),
                    sprintf("%.4f", $tierPrice->getValue()),
                    $tierPrice->getWebsiteId()
                );
                $activeTierPriceData[$serialized] = true;
            }
        }

        // check stored tier prices with current prices
        // if it is not present, remove it from the database
        $removableValueIds = [];

        foreach ($storedTierPriceData as $datum) {
            $serialized = $datum['serialized'];
            if (!array_key_exists($serialized, $activeTierPriceData)) {
                $removableValueIds[] = $datum['value_id'];
            }
        }

        // remove the outdated tier prices
        $this->db->deleteMultiple($this->metaData->tierPriceTable, 'value_id', $removableValueIds);
    }
}