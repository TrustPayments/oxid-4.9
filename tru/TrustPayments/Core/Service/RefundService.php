<?php
/**
 * TrustPayments OXID
 *
 * This OXID module enables to process payments with TrustPayments (https://www.trustpayments.com//).
 *
 * @package Whitelabelshortcut\TrustPayments
 * @author customweb GmbH (http://www.customweb.com/)
 * @license http://www.apache.org/licenses/LICENSE-2.0  Apache Software License (ASL 2.0)
 */


namespace Tru\TrustPayments\Core\Service;
require_once(OX_BASE_PATH . 'modules/tru/TrustPayments/autoload.php');

use Monolog\Logger;
use TrustPayments\Sdk\Model\CriteriaOperator;
use TrustPayments\Sdk\Model\EntityQuery;
use TrustPayments\Sdk\Model\EntityQueryFilter;
use TrustPayments\Sdk\Model\EntityQueryFilterType;
use TrustPayments\Sdk\Model\LineItemReductionCreate;
use TrustPayments\Sdk\Model\LineItemType;
use TrustPayments\Sdk\Model\Refund;
use TrustPayments\Sdk\Model\RefundCreate;
use TrustPayments\Sdk\Model\RefundState;
use TrustPayments\Sdk\Model\RefundType;
use TrustPayments\Sdk\Model\TransactionState;
use TrustPayments\Sdk\Service\RefundService as sdkRefundService;
use Tru\TrustPayments\Application\Model\AbstractJob;
use Tru\TrustPayments\Application\Model\RefundJob;
use Tru\TrustPayments\Application\Model\Transaction;
use Tru\TrustPayments\Core\TrustPaymentsModule;

/**
 * Class RefundService
 * Handles interactions regarding refunds.
 */
class RefundService extends JobService
{

    private $service;

    protected function getService()
    {
        if ($this->service === null) {
            $this->service = new sdkRefundService(TrustPaymentsModule::instance()->getApiClient());
        }
        return $this->service;
    }


    /**
     * Return list of line items, with all successful reductions removed.
     *
     * @param Transaction $transaction
     * @return array
     */
    public function getReducedItems(Transaction $transaction)
    {
        $refunds = $this->getService()->search($transaction->getSpaceId(), self::createSuccessfulRefundQuery($transaction->getTransactionId()));

        $items = array();
        foreach ($transaction->getSdkTransaction()->getLineItems() as $item) {
            $items[$item->getUniqueId()] = array(
                'id' => $item->getUniqueId(),
                'sku' => $item->getSku(),
                'name' => $item->getName(),
                'quantity' => $item->getQuantity(),
                'unit_price' => $item->getUnitPriceIncludingTax(),
                'total' => $item->getAmountIncludingTax()
            );
        }

        $remove = array();
        foreach ($refunds as $refund) {
            foreach ($refund->getReductions() as $reduction) {
                $items[$reduction->getLineItemUniqueId()]['quantity'] -= $reduction->getQuantityReduction();
                $items[$reduction->getLineItemUniqueId()]['unit_price'] -= $reduction->getUnitPriceReduction();
                if ($items[$reduction->getLineItemUniqueId()]['quantity'] == 0 || $items[$reduction->getLineItemUniqueId()]['unit_price'] == 0) {
                    $remove[] = $reduction->getLineItemUniqueId();
                }
            }
        }
        foreach ($remove as $toRemove) {
            unset($items[$toRemove]);
        }

        return $items;
    }

    protected function getJobType()
    {
        return RefundJob::class;
    }

    public function getSupportedTransactionStates()
    {
        return array(
            TransactionState::COMPLETED,
            TransactionState::FULFILL
        );
    }

    protected function processSend(AbstractJob $job)
    {
        if (!$job instanceof RefundJob) {
            throw new \Exception("Invalid job type supplied.");
        }
        if (empty($job->getFormReductions())) {
            throw new \Exception("No form reductions supplied");
        }
        $refund = $this->getService()->refund($job->getSpaceId(), $this->createRefund($job));
        if ($refund->getState() === RefundState::SUCCESSFUL) {
            $this->restock($refund);
        }
        return $refund;
    }

    protected function restock(Refund $refund)
    {
        foreach ($refund->getReductions() as $reduction) {
            foreach ($refund->getReducedLineItems() as $reduced) {
                if ($reduced->getUniqueId() === $reduction->getLineItemUniqueId() && $reduced->getType() !== LineItemType::PRODUCT) {
                    break 1;
                }
            }
            if ($reduction->getQuantityReduction()) {
class_exists('oxarticle');                $oxArticle = oxNew('oxarticle');
                /* @var $oxArticle \oxarticle */
                if ($oxArticle->load($reduction->getLineItemUniqueId())) {
                    if (!$oxArticle->reduceStock(-$reduction->getQuantityReduction())) {
                        TrustPaymentsModule::log(Logger::ERROR, "Unable to increase stock for article {$reduction->getLineItemUniqueId()} by {$reduction->getQuantityReduction()}.");
                    }
                } else {
                    TrustPaymentsModule::log(Logger::ERROR, "Unable to load article {$reduction->getLineItemUniqueId()} to reduce stock by {$reduction->getQuantityReduction()}.");
                }
            }
        }
    }

    private function createRefund(RefundJob $job)
    {
        $refund = new RefundCreate();
        $refund->setType(RefundType::MERCHANT_INITIATED_ONLINE);
        $refund->setTransaction($job->getTransactionId());
        $refund->setExternalId($job->getId());
        $reductions = array();
        foreach ($job->getFormReductions() as $formReduction) {
            $reduction = new LineItemReductionCreate();
            $reduction->setLineItemUniqueId($formReduction['id']);
            $reduction->setQuantityReduction($formReduction['quantity']);
            $reduction->setUnitPriceReduction($formReduction['price']);
            $reductions[] = $reduction;
        }
        $refund->setReductions($reductions);
        return $refund;
    }

    /**
     * Creates an EntityQuery for the given transaction id which includes all successful refunds associated with the transaction.
     *
     * @noinspection PhpParamsInspection
     * suppress enum warnings
     * @param $transactionId
     * @return EntityQuery
     */
    private static function createSuccessfulRefundQuery($transactionId)
    {
        $query = new EntityQuery();

        $transactionFilter = new EntityQueryFilter();
        $transactionFilter->setType(EntityQueryFilterType::LEAF);
        $transactionFilter->setOperator(CriteriaOperator::EQUALS);
        $transactionFilter->setFieldName('transaction.id');
        $transactionFilter->setValue($transactionId);

        $stateFilter = new EntityQueryFilter();
        $stateFilter->setType(EntityQueryFilterType::LEAF);
        $stateFilter->setOperator(CriteriaOperator::EQUALS);
        $stateFilter->setFieldName('state');
        $stateFilter->setValue(RefundState::SUCCESSFUL); // only exclude successful, refunds are not possible if open pending / manual tasks.

        $filter = new EntityQueryFilter();
        $filter->setType(EntityQueryFilterType::_AND);
        $filter->setChildren(array($stateFilter, $transactionFilter));

        $query->setFilter($filter);

        return $query;
    }

    public function resendAll()
    {
        $errors = array();
class_exists(RefundJob::class);        $refund = oxNew(RefundJob::class);
        /* @var $refund \Tru\TrustPayments\Application\Model\RefundJob */
        $notSent = $refund->loadNotSentIds();
        foreach ($notSent as $job) {
            if ($refund->loadByJob($job['TRUJOBID'], $job['TRUSPACEID'])) {
                $this->send($refund);
                if($refund->getState() === self::getFailedState()) {
                    $errors[] = $refund->getFailureReason();
                }
            } else {
                TrustPaymentsModule::log(Logger::ERROR, "Unable to load pending job {$job['TRUJOBID']} / {$job['TRUSPACEID']}.");
            }
        }
        return $errors;
    }

    public static function getPendingStates() {
        return array(
            RefundState::PENDING,
            RefundState::MANUAL_CHECK
        );
    }
}