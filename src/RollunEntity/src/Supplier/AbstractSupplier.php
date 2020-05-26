<?php
declare(strict_types=1);

namespace rollun\Entity\Supplier;

use rollun\dic\InsideConstruct;
use rollun\Entity\Product\Dimensions\Rectangular;
use rollun\Entity\Product\Item\ItemInterface;
use rollun\Entity\Product\Item\Product;
use service\Entity\Api\DataStore\Shipping\AllCosts;
use service\Entity\Api\DataStore\Shipping\BestShipping;
use Xiag\Rql\Parser\Node\Query\LogicOperator\AndNode;
use Xiag\Rql\Parser\Node\Query\ScalarOperator\EqNode;
use Xiag\Rql\Parser\Node\Query\ScalarOperator\NeNode;
use Xiag\Rql\Parser\Node\SortNode;
use Xiag\Rql\Parser\Query;

/**
 * Class AbstractSupplier
 *
 * @author    r.ratsun <r.ratsun.rollun@gmail.com>
 *
 * @copyright Copyright © 2014 Rollun LC (http://rollun.com/)
 * @license   LICENSE.md New BSD License
 */
abstract class AbstractSupplier
{
    const TYPE_PU = 'PickUp';
    const TYPE_DS = 'DropShip';

    /**
     * Product title (for defining airAllowed)
     *
     * @var string
     */
    protected $productTitle = 'name';

    /**
     * @var AllCosts
     */
    protected $allCosts;

    /**
     * @var null|string
     */
    protected $zipOriginal = null;

    /**
     * @var null|array
     */
    protected $shippingMethods = null;

    /**
     * @var array
     */
    protected $inventory = [];

    /**
     * AbstractSupplier constructor.
     *
     * @param AllCosts|null $allCosts
     *
     * @throws \ReflectionException
     */
    public function __construct(AllCosts $allCosts = null)
    {
        InsideConstruct::init(
            [
                'allCosts' => AllCosts::class
            ]
        );
    }

    /**
     * @throws \ReflectionException
     */
    public function __wakeup()
    {
        InsideConstruct::initWakeup(
            [
                'allCosts' => AllCosts::class
            ]
        );
    }

    /**
     * @param string $rollunId
     *
     * @return bool
     */
    abstract public function isInStock(string $rollunId): bool;

    /**
     * @return string
     */
    abstract public function getName(): string;

    /**
     * @param string $rollunId
     *
     * @return ItemInterface
     */
    public function createItem(string $rollunId): ItemInterface
    {
        $dimensions = $this->getDimensions($rollunId);

        $product = new Product(new Rectangular($dimensions['length'], $dimensions['width'], $dimensions['height']), $dimensions['weight']);
        $product->setAttributes($this->inventory);

        return $product;
    }

    /**
     * @param ItemInterface $item
     * @param string        $zipDestination
     *
     * @return array|null
     * @throws \Exception
     */
    public function getBestShippingMethod(ItemInterface $item, string $zipDestination): ?array
    {
        // get all available shipping methods
        $shippingMethods = $this->allCosts->query($this->buildShippingQuery($item, $zipDestination));

        if (empty($shippingMethods) || !is_array($shippingMethods)) {
            return null;
        }

        foreach ($this->getShippingMethods() as $supplierShippingMethod) {
            foreach ($shippingMethods as $shippingMethod) {
                if ($shippingMethod['id'] === $supplierShippingMethod['id']
                    && $this->isValid($item, $zipDestination, $supplierShippingMethod['id'])
                    && $this->isUspsValid($item, $zipDestination, $supplierShippingMethod['id'])) {
                    return [
                        'id'             => $supplierShippingMethod['id'],
                        'supplier'       => $this->getName(),
                        'shippingType'   => empty($supplierShippingMethod['type']) ? null : $supplierShippingMethod['type'],
                        'shippingMethod' => empty($shippingMethod['name']) ? null : $shippingMethod['name'],
                        'courier'        => empty($supplierShippingMethod['courier']) ? null : $supplierShippingMethod['courier'],
                        'priority'       => $supplierShippingMethod['priority'],
                        'cost'           => $shippingMethod['cost']
                    ];
                }
            }
        }

        return null;
    }

    /**
     * @return bool
     */
    public function isAirAllowed(): bool
    {
        // get stop words
        $stopWords = BestShipping::httpSend("api/datastore/AirStopWords");

        if (!empty($stopWords)) {
            foreach ($stopWords as $row) {
                if (strpos($this->productTitle, $row['stop_phrase']) !== false) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * @param ItemInterface $item
     * @param string        $zipDestination
     * @param string        $shippingMethod
     *
     * @return bool
     */
    abstract protected function isValid(ItemInterface $item, string $zipDestination, string $shippingMethod): bool;

    /**
     * @param ItemInterface $item
     * @param string        $zipDestination
     * @param string        $shippingMethod
     *
     * @return bool
     */
    protected function isUspsValid(ItemInterface $item, string $zipDestination, string $shippingMethod): bool
    {
        $parts = explode('-Usps-', $shippingMethod);
        if (isset($parts[1])) {
            if (empty($item->getAttribute('isAirAllowed'))) {
                return false;
            }

            if ($parts[1] === 'FtCls-Package' && $item->getWeight() > 0.9) {
                return false;
            }

            // get item dimensions
            $dimensions = $item->getDimensionsList()[0]['dimensions'];

            $weight = $item->getWeight();
            $lbs = $item->getVolume() / 166;
            if ($lbs > $item->getWeight()) {
                $weight = $lbs;
            }

            if ($parts[1] === 'PM-FR-Env') {
                if ($dimensions->max <= 0) {
                    return false;
                }

                if ($weight > 5) {
                    return false;
                }
            }

            if ($parts[1] === 'PM-FR-LegalEnv' || $parts[1] === 'PM-FR-Pad-Env') {
                if ($dimensions->max <= 0) {
                    return false;
                }

                if ($weight > 7) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * @return string
     * @throws \Exception
     */
    protected function getZipOriginal(): string
    {
        if (empty($this->zipOriginal) || !is_string($this->zipOriginal)) {
            throw new \Exception('No valid zipOriginal for Supplier class');
        }

        return $this->zipOriginal;
    }

    /**
     * @return array
     * @throws \Exception
     */
    protected function getShippingMethods(): array
    {
        if (empty($this->shippingMethods) || !is_array($this->shippingMethods)) {
            throw new \Exception('No valid shippingMethods for Supplier class');
        }

        return $this->shippingMethods;
    }

    /**
     * @param ItemInterface $item
     * @param string        $zipDestination
     *
     * @return Query
     * @throws \Exception
     */
    protected function buildShippingQuery(ItemInterface $item, string $zipDestination): Query
    {
        // get item dimensions
        $dimensions = $item->getDimensionsList()[0]['dimensions'];

        $query = new Query();
        $andNode = new AndNode(
            [
                new EqNode('ZipOrigination', $this->getZipOriginal()),
                new EqNode('ZipDestination', $zipDestination),
                new EqNode('Pounds', $item->getWeight()),
                new EqNode('Width', $dimensions->max),
                new EqNode('Length', $dimensions->mid),
                new EqNode('Height', $dimensions->min),
                new EqNode('Error', null),
                new NeNode('cost', null),
                new EqNode('Quantity', 1),
                new EqNode('attr_csn', $item->getAttribute('csn'))
            ]
        );

        $query->setQuery($andNode);
        $query->setSort(new SortNode(['cost' => SortNode::SORT_ASC]));

        return $query;
    }

    /**
     * @param string $rollunId
     *
     * @return array
     */
    protected function getDimensions(string $rollunId): array
    {
        $response = BestShipping::httpSend("api/datastore/DimensionStore?eq(id,$rollunId)&limit(20,0)");
        if (empty($response[0])) {
            return [
                'width'  => -1000,
                'height' => -1000,
                'length' => -1000,
                'weight' => -1000
            ];
        }

        return [
            'width'  => (float)$response[0]['width'],
            'height' => (float)$response[0]['height'],
            'length' => (float)$response[0]['length'],
            'weight' => (float)$response[0]['weight']
        ];
    }
}
