<?php


namespace ProcessAndInvoice\Loop;


use Propel\Runtime\ActiveQuery\Criteria;
use Propel\Runtime\ActiveQuery\ModelCriteria;
use Thelia\Core\Template\Loop\Argument\Argument;
use Thelia\Core\Template\Loop\Argument\ArgumentCollection;
use Thelia\Model\Map\CustomerTableMap;
use Thelia\Model\Map\OrderAddressTableMap;
use Thelia\Model\OrderQuery;
use Thelia\Type;
use Thelia\Type\TypeCollection;
use Thelia\Core\Template\Loop\Order;

/**
 * @package Thelia\Core\Template\Loop
 *
 * {@inheritdoc}
 * @method int[] getId()
 * @method bool getInvoiced()
 * @method string getCustomer()
 * @method string[] getStatus()
 * @method int[] getExcludeStatus()
 * @method string[] getStatusCode()
 * @method string[] getExcludeStatusCode()
 * @method string[] getOrder()
 * @method bool getWithPrevNextInfo()
 */
class InvoicePdfOrderLoop extends Order
{
    public function getArgDefinitions(): ArgumentCollection
    {
        return new ArgumentCollection(
            Argument::createIntListTypeArgument('id'),
            Argument::createBooleanTypeArgument('with_prev_next_info', false),
            Argument::createBooleanTypeArgument('invoiced'),
            new Argument(
                'customer',
                new TypeCollection(
                    new Type\IntType(),
                    new Type\EnumType(array('current', '*'))
                ),
                'current'
            ),
            new Argument(
                'status',
                new TypeCollection(
                    new Type\IntListType(),
                    new Type\EnumType(array('*'))
                )
            ),
            Argument::createIntListTypeArgument('exclude_status'),
            new Argument(
                'status_code',
                new TypeCollection(
                    new Type\AnyListType(),
                    new Type\EnumType(array('*'))
                )
            ),
            Argument::createAnyListTypeArgument('exclude_status_code'),
            new Argument(
                'order',
                new TypeCollection(
                    new Type\EnumListType(
                        array(
                            'id', 'id-reverse',
                            'reference', 'reference-reverse',
                            'create-date', 'create-date-reverse',
                            'invoice-date', 'invoice-date-reverse',
                            'company', 'company-reverse',
                            'customer-name', 'customer-name-reverse',
                            'status', 'status-reverse'
                        )
                    )
                ),
                'create-date-reverse'
            )
        );
    }

    public function buildModelCriteria(): OrderQuery|ModelCriteria|null
    {
        $search = OrderQuery::create();

        $id = $this->getId();

        if (null !== $id) {
            $search->filterById($id, Criteria::IN);
        }

        $invoiced = $this->getInvoiced();

        if (null !== $invoiced) {

            if ($invoiced === true) {
                $search
                    ->usePdfInvoiceQuery(null, Criteria::LEFT_JOIN)
                    ->filterByInvoiced('1')
                    ->endUse();
            } else {
                $search
                    ->usePdfInvoiceQuery(null, Criteria::LEFT_JOIN)
                    ->filterByInvoiced(null, Criteria::EQUAL)
                    ->_or()
                    ->filterByInvoiced('0', Criteria::LIKE)
                    ->endUse();
            }

        }

        $customer = $this->getCustomer();

        if ($customer === 'current') {
            $currentCustomer = $this->securityContext->getCustomerUser();
            if ($currentCustomer === null) {
                return null;
            } else {
                $search->filterByCustomerId($currentCustomer->getId(), Criteria::EQUAL);
            }
        } elseif ($customer !== '*') {
            $search->filterByCustomerId($customer, Criteria::EQUAL);
        }

        $status = $this->getStatus();

        if (null !== $status && $status != '*') {
            $search->filterByStatusId($status, Criteria::IN);
        }

        if (null !== $excludeStatus = $this->getExcludeStatus()) {
            $search->filterByStatusId($excludeStatus, Criteria::NOT_IN);
        }

        $statusCode = $this->getStatusCode();

        if (null !== $statusCode && $statusCode != '*') {
            $search
                ->useOrderStatusQuery()
                ->filterByCode($statusCode, Criteria::IN)
                ->endUse();
        }

        if (null !== $excludeStatusCode = $this->getExcludeStatusCode()) {
            $search
                ->useOrderStatusQuery()
                ->filterByCode($excludeStatusCode, Criteria::NOT_IN)
                ->endUse();
        }

        $orderers = $this->getOrder();

        foreach ($orderers as $orderer) {
            switch ($orderer) {
                case 'id':
                    $search->orderById();
                    break;
                case 'id-reverse':
                    $search->orderById(Criteria::DESC);
                    break;
                case 'reference':
                    $search->orderByRef();
                    break;
                case 'reference-reverse':
                    $search->orderByRef(Criteria::DESC);
                    break;
                case 'create-date':
                    $search->orderByCreatedAt();
                    break;
                case 'create-date-reverse':
                    $search->orderByCreatedAt(Criteria::DESC);
                    break;
                case 'invoice-date':
                    $search->orderByInvoiceDate();
                    break;
                case 'invoice-date-reverse':
                    $search->orderByInvoiceDate(Criteria::DESC);
                    break;
                case 'status':
                    $search->orderByStatusId();
                    break;
                case 'status-reverse':
                    $search->orderByStatusId(Criteria::DESC);
                    break;
                case 'company':
                    $search
                        ->joinOrderAddressRelatedByDeliveryOrderAddressId()
                        ->withColumn(OrderAddressTableMap::COL_COMPANY, 'company')
                        ->orderBy('company');
                    break;
                case 'company-reverse':
                    $search
                        ->joinOrderAddressRelatedByDeliveryOrderAddressId()
                        ->withColumn(OrderAddressTableMap::COL_COMPANY, 'company')
                        ->orderBy('company', Criteria::DESC);
                    break;
                case 'customer-name':
                    $search
                        ->joinCustomer()
                        ->withColumn(CustomerTableMap::COL_FIRSTNAME, 'firstname')
                        ->withColumn(CustomerTableMap::COL_LASTNAME, 'lastname')
                        ->orderBy('lastname')
                        ->orderBy('firstname');
                    break;
                case 'customer-name-reverse':
                    $search
                        ->joinCustomer()
                        ->withColumn(CustomerTableMap::COL_FIRSTNAME, 'firstname')
                        ->withColumn(CustomerTableMap::COL_LASTNAME, 'lastname')
                        ->orderBy('lastname', Criteria::DESC)
                        ->orderBy('firstname', Criteria::DESC);
                    break;
            }
        }

        return $search;
    }
}