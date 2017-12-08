<?php
/**
 *                       ######
 *                       ######
 * ############    ####( ######  #####. ######  ############   ############
 * #############  #####( ######  #####. ######  #############  #############
 *        ######  #####( ######  #####. ######  #####  ######  #####  ######
 * ###### ######  #####( ######  #####. ######  #####  #####   #####  ######
 * ###### ######  #####( ######  #####. ######  #####          #####  ######
 * #############  #############  #############  #############  #####  ######
 *  ############   ############  #############   ############  #####  ######
 *                                      ######
 *                               #############
 *                               ############
 *
 * Adyen Subscription module (https://www.adyen.com/)
 *
 * Copyright (c) 2015 H&O E-commerce specialists B.V. (http://www.h-o.nl/)
 * See LICENSE.txt for license details.
 *
 * Author: Adyen <magento@adyen.com>, H&O E-commerce specialists B.V. <info@h-o.nl>
 */

/**
 * Class Adyen_Subscription_Model_Subscription
 *
 * @method Adyen_Subscription_Model_Subscription setIncrementId(string $value)
 * @method string getErrorMessage()
 * @method Adyen_Subscription_Model_Subscription setErrorMessage(string $value)
 * @method string getStoreId()
 * @method Adyen_Subscription_Model_Subscription setStoreId(string $value)
 * @method string getScheduledAt()
 * @method Adyen_Subscription_Model_Subscription setScheduledAt(string $value)
 * @method string getCreatedAt()
 * @method Adyen_Subscription_Model_Subscription setCreatedAt(string $value)
 * @method string getUpdatedAt()
 * @method Adyen_Subscription_Model_Subscription setUpdatedAt(string $value)
 * @method int getEntityId()
 * @method Adyen_Subscription_Model_Subscription setEntityId(int $value)
 * @method string getStockId()
 * @method Adyen_Subscription_Model_Subscription setStockId(string $value)
 * @method int getBillingAgreementId()
 * @method Adyen_Subscription_Model_Subscription setBillingAgreementId(int $value)
 * @method string getShippingMethod()
 * @method Adyen_Subscription_Model_Subscription setShippingMethod(string $value)
 * @method int getTerm()
 * @method Adyen_Subscription_Model_Subscription setTerm(int $value)
 * @method string getCustomerName()
 * @method Adyen_Subscription_Model_Subscription setCustomerName(string $value)
 * @method Adyen_Subscription_Model_Subscription setEndsAt(string $value)
 * @method int getCustomerId()
 * @method Adyen_Subscription_Model_Subscription setCustomerId(int $value)
 * @method int getOrderId()
 * @method Adyen_Subscription_Model_Subscription setOrderId(int $value)
 * @method string getTermType()
 * @method Adyen_Subscription_Model_Subscription setTermType(string $value)
 * @method string getStatus()
 * @method Adyen_Subscription_Model_Subscription setStatus(string $value)
 * @method string getCancelCode()
 * @method Adyen_Subscription_Model_Subscription setCancelCode(string $value)
 * @method Adyen_Subscription_Model_Resource_Subscription _getResource()
 */
class Adyen_Subscription_Model_Subscription extends Mage_Core_Model_Abstract
{
    protected $_eventPrefix = 'adyen_subscription';
    protected $_eventObject = 'subscription';

    const STATUS_ACTIVE             = 'active';
    const STATUS_QUOTE_ERROR        = 'quote_error';
    const STATUS_ORDER_ERROR        = 'order_error';
    const STATUS_SUBSCRIPTION_ERROR = 'subscription_error';
    const STATUS_CANCELED           = 'canceled';
    const STATUS_EXPIRED            = 'expired';
    const STATUS_AWAITING_PAYMENT   = 'awaiting_payment';
    const STATUS_PAYMENT_ERROR      = 'payment_error';
    const STATUS_PAUSED             = 'paused';

    protected function _construct()
    {
        $this->_init('adyen_subscription/subscription');
    }


    public function getIncrementId()
    {
        return $this->getData('increment_id') ?: $this->getId();
    }


    /**
     * @return Adyen_Subscription_Model_Subscription_Quote
     */
    protected function _getActiveQuoteAdditional()
    {
        if (!$this->hasData('_active_quote_additional')) {
            $quoteAdd = Mage::getResourceModel('adyen_subscription/subscription_quote_collection')
                ->addFieldToFilter('main_table.subscription_id', $this->getId())
                ->addFieldToFilter('order_id', ['null' => true])
                ->getFirstItem();
            $this->setData('_active_quote_additional', $quoteAdd);
        }
        return $this->getData('_active_quote_additional');
    }


    /**
     * @param $postData
     * @return $this
     */
    public function importPostData($postData)
    {
        if (is_array($postData)) {
            $data = $postData['adyen_subscription'];
            if (isset($postData['adyen_subscription']['scheduled_at'])) {
                if (Mage::app()->getLocale()->getLocaleCode() != 'en_US') {
                    /**
                     * Bugfix: Replace scheduled_at date slashes with dashes when locale is US
                     * This is done because dates with slashes (US) are handled differently,
                     * as noted in the documentation (see below), but UK dates also have slashes in their format (d/m/y).
                     * @see http://php.net/manual/en/function.strtotime.php
                     * Dates in the m/d/y or d-m-y formats are disambiguated by looking at the separator between the various components:
                     * if the separator is a slash (/), then the American m/d/y is assumed; whereas if the separator is a dash (-) or a dot (.),
                     * then the European d-m-y format is assumed.
                     * If, however, the year is given in a two digit format and the separator is a dash (-), the date string is parsed as y-m-d.
                     */
                    $postData['adyen_subscription']['scheduled_at'] = str_replace('/', '-', $postData['adyen_subscription']['scheduled_at']);
                }
                $data['scheduled_at'] = Mage::getModel('core/date')->gmtDate(null, $postData['adyen_subscription']['scheduled_at']);
            }

            $data['billing_customer_address_id'] = $postData['order']['billing_address']['customer_address_id'];
            $data['shipping_customer_address_id'] = isset($postData['order']['shipping_address']['customer_address_id'])
                ? $postData['order']['shipping_address']['customer_address_id']
                : $postData['order']['billing_address']['customer_address_id'];
            $data['billing_address_save_in_address_book'] = isset($postData['order']['billing_address']['save_in_address_book'])
                ? $postData['order']['billing_address']['save_in_address_book']
                : null;
            $data['shipping_address_save_in_address_book'] = isset($postData['order']['shipping_address']['save_in_address_book'])
                ? $postData['order']['shipping_address']['save_in_address_book']
                : null;
            $data['shipping_as_billing'] = isset($postData['shipping_as_billing']) ? $postData['shipping_as_billing'] : null;

            // add payment data
            $data['payment'] = isset($postData['payment']) ? $postData['payment'] : null;

            $this->addData($data);
        }
        return $this;
    }


    /**
     * Only one quote of each subscription can be saved
     * @param bool $instantiateNew
     * @return Adyen_Subscription_Model_Subscription_Quote
     */
    public function getActiveQuoteAdditional($instantiateNew = false)
    {
        $quoteAdditional = $this->_getActiveQuoteAdditional();

        if (! $quoteAdditional || ! $quoteAdditional->getId()) {
            if (! $instantiateNew) {
                return null;
            }
            $quoteAdditional = Mage::getModel('adyen_subscription/subscription_quote');
        }

        $quoteAdditional
            ->setSubscription($this)
            ->setQuote($this->getActiveQuote());

        return $quoteAdditional;
    }

    /**
     * @return Adyen_Subscription_Model_Resource_Subscription_Quote_Collection
     */
    public function getQuoteAdditionalCollection()
    {
        return Mage::getResourceModel('adyen_subscription/subscription_quote_collection')
            ->addFieldToFilter('main_table.subscription_id', $this->getId());
    }


    /**
     * @param Mage_Sales_Model_Order $order
     * @param bool                   $instantiateNew
     *
     * @return Adyen_Subscription_Model_Subscription_Order|null|Varien_Object
     */
    public function getOrderAdditional(Mage_Sales_Model_Order $order, $instantiateNew = false)
    {
        $orderAdditional = Mage::getModel('adyen_subscription/subscription_order')
            ->getCollection()
            ->addFieldToFilter('main_table.subscription_id', $this->getId())
            ->addFieldToFilter('order_id', $order->getId())
            ->getFirstItem();

        if (!$orderAdditional->getId()) {
            if (! $instantiateNew) {
                return null;
            }
            $orderAdditional = Mage::getModel('adyen_subscription/subscription_order');
        }

        $orderAdditional->setOrder($order)->setSubscription($this);
        return $orderAdditional;
    }


    /**
     * @return Mage_Customer_Model_Customer
     */
    public function getCustomer()
    {
        if (! $this->hasData('_customer')) {
            $customer = Mage::getModel('customer/customer')->load($this->getCustomerId());
            $this->setData('_customer', $customer);
        }

        return $this->_getData('_customer');
    }

    /**
     * @param bool $active
     * @return Adyen_Subscription_Model_Resource_Subscription_Item_Collection
     */
    public function getItemCollection($active = true)
    {
        $items = Mage::getResourceModel('adyen_subscription/subscription_item_collection')
            ->addFieldToFilter('main_table.subscription_id', $this->getId());

        if ($active) {
            $items->addFieldToFilter('status', Adyen_Subscription_Model_Subscription_Item::STATUS_ACTIVE);
        }
        $items->setDataToAll('_subscription', $this);

        return $items;
    }

    /**
     * @deprecated Please use getItemCollection
     * @param bool $active
     * @return Adyen_Subscription_Model_Resource_Subscription_Item_Collection
     */
    public function getItems($active = true)
    {
        return $this->getItemCollection($active);
    }

    /**
     * @return Mage_Sales_Model_Resource_Order_Collection
     */
    public function getOrders()
    {
        return Mage::getModel('sales/order')
            ->getCollection()
            ->addFieldToFilter('entity_id', array('in' => $this->getOrderIds()));
    }


     public function getUpcomingOrders($count = 0)
    {
        $nextFormatted = $this->getScheduledAt();
        $date = $this->getScheduledAt();

        $timezone = new DateTimeZone(Mage::getStoreConfig(
            Mage_Core_Model_Locale::XML_PATH_DEFAULT_TIMEZONE
        ));

        $result = [$nextFormatted];

        for($i = 0; $i < (int)$count; $i++) {
            $date = $this->calculateNextUpcomingOrderDate($date, $timezone);
            $result[] = $date;
        }

        return $result;
    }

    public function calculateNextUpcomingOrderDate($date, $timezone) {

        $schedule = new DateTime($date, $timezone);

        $dateInterval = null;
        switch ($this->getTermType()) {
            case Adyen_Subscription_Model_Product_Subscription::TERM_TYPE_DAY:
                $dateInterval = new DateInterval(sprintf('P%sD',$this->getTerm()));
                break;
            case Adyen_Subscription_Model_Product_Subscription::TERM_TYPE_WEEK:
                $dateInterval = new DateInterval(sprintf('P%sW',$this->getTerm()));
                break;
            case Adyen_Subscription_Model_Product_Subscription::TERM_TYPE_MONTH:
                $dateInterval = new DateInterval(sprintf('P%sM',$this->getTerm()));
                break;
            case Adyen_Subscription_Model_Product_Subscription::TERM_TYPE_YEAR:
                $dateInterval = new DateInterval(sprintf('P%sY',$this->getTerm()));
                break;
        }
        if (! isset($dateInterval)) {
            Adyen_Subscription_Exception::throwException('Could not calculate a correct date interval');
        }

        $schedule->add($dateInterval);

        Mage::dispatchEvent('adyen_subscription_calculatenextupcomingorderdate', array('subscription' => $this, 'schedule' => $schedule));

        return  $schedule->format('Y-m-d H:i:s');
    }


    /**
     * @return array
     */
    public function getOrderIds()
    {
        $subscriptionOrders = Mage::getModel('adyen_subscription/subscription_order')
            ->getCollection()
            ->addFieldToFilter('subscription_id', $this->getId());

        $orderIds = array();
        foreach ($subscriptionOrders as $subscriptionOrder) {
            $orderIds[] = $subscriptionOrder->getOrderId();
        }

        return $orderIds;
    }

    public function setActiveQuote(Mage_Sales_Model_Quote $quote)
    {
        $this->setData('_active_quote', $quote);
        return $this;
    }

    /**
     * @return Mage_Sales_Model_Quote|null
     */
    public function getActiveQuote()
    {
        if (! $this->hasData('_active_quote')) {
            /** @var Adyen_Subscription_Model_Subscription_Quote $quoteAdditional */
            $quoteAdditional = $this->_getActiveQuoteAdditional();

            if (! $quoteAdditional || ! $quoteAdditional->getId()) {
                $this->setData('_active_quote', null);
                return null;
            }

            // Note: The quote won't load if we don't set the store ID
            $quote = Mage::getModel('sales/quote')
                ->setStoreId($this->getStoreId())
                ->load($quoteAdditional->getQuoteId());

            $this->setData('_active_quote', $quote);
        }

        return $this->getData('_active_quote');
    }

    /**
     * @param bool $asObject
     * @return DateTime|string
     */
    public function calculateNextScheduleDate($asObject = false)
    {
        /** @var Adyen_Subscription_Model_Subscription_Quote $quoteAddCollection */
        $latestQuoteSchedule = $this->getQuoteAdditionalCollection()
            ->addFieldToFilter('order_id', ['notnull' => true]);

        $resource = $latestQuoteSchedule->getResource();

        $latestQuoteSchedule->getSelect()->joinLeft(
            array('order' => $resource->getTable('sales/order')),
            'main_table.order_id = order.entity_id',
            'created_at'
        );
        $latestQuoteSchedule = $latestQuoteSchedule->getLastItem();

        $lastScheduleDate = $this->getCreatedAt();
        if ($latestQuoteSchedule->getId()) {
            $lastScheduleDate = $latestQuoteSchedule->getCreatedAt();
        }

        $schedule = new DateTime($lastScheduleDate);

        $dateInterval = null;
        switch ($this->getTermType()) {
            case Adyen_Subscription_Model_Product_Subscription::TERM_TYPE_DAY:
                $dateInterval = new DateInterval(sprintf('P%sD',$this->getTerm()));
                break;
            case Adyen_Subscription_Model_Product_Subscription::TERM_TYPE_WEEK:
                $dateInterval = new DateInterval(sprintf('P%sW',$this->getTerm()));
                break;
            case Adyen_Subscription_Model_Product_Subscription::TERM_TYPE_MONTH:
                $dateInterval = new DateInterval(sprintf('P%sM',$this->getTerm()));
                break;
            case Adyen_Subscription_Model_Product_Subscription::TERM_TYPE_YEAR:
                $dateInterval = new DateInterval(sprintf('P%sY',$this->getTerm()));
                break;
        }
        if ($dateInterval == null) {
            Adyen_Subscription_Exception::throwException('Could not calculate a correct date interval');
        }

        $schedule->add($dateInterval);

        Mage::dispatchEvent('adyen_subscription_calculatenextscheduledate', array('subscription' => $this, 'schedule' => $schedule));

        if ($asObject) {
            return $schedule;
        }

        return $schedule->format('Y-m-d H:i:s');
    }


    public function setBillingAgreement(Mage_Sales_Model_Billing_Agreement $billingAgreement, $validate = false)
    {

        if ($validate) {
            $billingAgreement->isValid();
            $billingAgreement->verifyToken();

            if ($billingAgreement->getStatus() !== $billingAgreement::STATUS_ACTIVE) {
                Adyen_Subscription_Exception::throwException(
                    Mage::helper('adyen_subscription')->__('Billing Agreement %s not active', $billingAgreement->getReferenceId()));
            }
        }

        $this->setBillingAgreementId($billingAgreement->getId());
        $this->setData('_billing_agreement', $billingAgreement);
        return $this;
    }

    /**
     * @return Mage_Sales_Model_Billing_Agreement
     */
    public function getBillingAgreement()
    {
        if (! $this->hasData('_billing_agreement')) {
            $billingAgreement = Mage::getModel('adyen/billing_agreement')
                ->load($this->getBillingAgreementId());

            $this->setData('_billing_agreement', $billingAgreement);
        }

        return $this->getData('_billing_agreement');
    }

    /**
     * Pause subscription, delete scheduled order (active quote) and hold linked orders (if possible)
     *
     * @return $this
     * @throws Exception
     */
    public function pause()
    {
        $this->setStatus(self::STATUS_PAUSED)
            ->setErrorMessage(null)
            ->setCancelCode(null)
            ->save();

        $helper = Mage::helper('adyen_subscription/config');
        $session = Mage::getSingleton('adminhtml/session');

        if ($helper->getHoldOrders()) {
            $activeQuote = $this->getActiveQuote();

            if ($activeQuote instanceof Mage_Sales_Model_Quote) {
                $session->addNotice(
                    $helper->__('Scheduled quote #%s deleted', $activeQuote->getId())
                );
                $activeQuote->delete();
            }

            foreach ($this->getOrders() as $order) {
                /** @var Mage_Sales_Model_Order $order */
                if (! $order->canHold()) {
                    $session->addError(
                        $helper->__('Can\'t hold order %s',
                            Mage::helper('adyen_subscription')->getAdminOrderUrlHtml($order),
                            $order->getStatusLabel())
                    );
                    continue;
                }

                if (in_array($order->getStatus(), $helper->getProtectedStatuses())) {
                    $session->addError(
                        $helper->__('Can\'t hold order %s (protected status: %s)',
                            Mage::helper('adyen_subscription')->getAdminOrderUrlHtml($order),
                            $order->getStatusLabel())
                    );
                    continue;
                }

                $session->addNotice(
                    $helper->__('Order %s on hold',
                        Mage::helper('adyen_subscription')->getAdminOrderUrlHtml($order),
                        $order->getStatusLabel())
                );
                $order->hold()->save();
            }
        }

        Mage::dispatchEvent('adyen_subscription_pause', array('subscription' => $this));

        return $this;
    }

    /**
     * Activate subscription, create scheduled order (quote) and unhold linked orders
     *
     * @return $this
     */
    public function activate()
    {
        $this->setActive()
            //->setScheduledAt(now())
            ->setEndsAt('0000-00-00 00:00:00')
            ->setCancelCode(null)
            ->save();

        $helper = Mage::helper('adyen_subscription/config');
        $session = Mage::getSingleton('adminhtml/session');

        if ($helper->getHoldOrders()) {
            foreach ($this->getOrders() as $order) {
                /** @var Mage_Sales_Model_Order $order */
                if (! $order->canUnhold() || ($order->getScheduledAt()!=null && strtotime($order->getScheduledAt()) < now())) {
                    continue;
                }

                $session->addNotice(
                    $helper->__('Order %s released from hold',
                        Mage::helper('adyen_subscription')->getAdminOrderUrlHtml($order),
                        $order->getStatusLabel())
                );
                $order->unhold()->save();
            }
        }

        Mage::dispatchEvent('adyen_subscription_activate', array('subscription' => $this));

        return $this;
    }

    public function cancel($reason)
    {
        if($reason) {
            $this->setCancelCode($reason);
        }
        $this->setStatus(self::STATUS_CANCELED);
        $this->setEndsAt(now());
        $this->save();

        $helper = Mage::helper('adyen_subscription/config');
        $session = Mage::getSingleton('adminhtml/session');

        if ($helper->getCancelOrders()) {
            $activeQuote = $this->getActiveQuote();

            if ($activeQuote instanceof Mage_Sales_Model_Quote) {
                $session->addNotice(
                    $helper->__('Scheduled quote #%s deleted', $activeQuote->getId())
                );
                $activeQuote->delete();
            }

            foreach ($this->getOrders() as $order) {
                /** @var Mage_Sales_Model_Order $order */
                if (! $order->canCancel()) {
                    $session->addError(
                        $helper->__('Can\'t cancel order %s',
                            Mage::helper('adyen_subscription')->getAdminOrderUrlHtml($order),
                            $order->getStatusLabel())
                    );
                    continue;
                }

                if (in_array($order->getStatus(), $helper->getProtectedStatuses())) {
                    $session->addError(
                        $helper->__('Can\'t cancel order %s (protected status: %s)',
                            Mage::helper('adyen_subscription')->getAdminOrderUrlHtml($order),
                            $order->getStatusLabel())
                    );
                    continue;
                }

                $session->addNotice(
                    $helper->__('Order %s canceled',
                        Mage::helper('adyen_subscription')->getAdminOrderUrlHtml($order),
                        $order->getStatusLabel())
                );
                $order->cancel()->save();
            }
        }

        Mage::dispatchEvent('adyen_subscription_cancel', array('subscription' => $this));

        return $this;
    }

    /**
     * @return $this
     */
    public function setActive()
    {
        $this->setStatus(self::STATUS_ACTIVE);
        $this->setErrorMessage(null);
        return $this;
    }

    public function save()
    {
        parent::save();
        $subscriptionHistory = Mage::getModel('adyen_subscription/subscription_history');
        $subscriptionHistory->saveFromSubscription($this);
        return $this;
    }

    /**
     * Delete object from database
     *
     * @return Mage_Core_Model_Abstract
     */
    public function delete()
    {
        Mage::dispatchEvent('adyen_subscription_delete', array('subscription' => $this));
        return parent::delete();
    }

    /**
     * @return array
     */
    public static function getStatuses()
    {
        $helper = Mage::helper('adyen_subscription');

        return [
            self::STATUS_ACTIVE             => $helper->__('Active'),
            self::STATUS_QUOTE_ERROR        => $helper->__('Quote Creation Error'),
            self::STATUS_ORDER_ERROR        => $helper->__('Order Creation Error'),
            self::STATUS_SUBSCRIPTION_ERROR => $helper->__('Subscription Error'),
            self::STATUS_CANCELED           => $helper->__('Canceled'),
            self::STATUS_EXPIRED            => $helper->__('Expired'),
            self::STATUS_AWAITING_PAYMENT   => $helper->__('Awaiting Payment'),
            self::STATUS_PAYMENT_ERROR      => $helper->__('Payment Error'),
            self::STATUS_PAUSED             => $helper->__('Paused')
        ];
    }


    /**
     * @return array
     */
    public static function getScheduleQuoteStatuses()
    {
        return [
            self::STATUS_ACTIVE,
            self::STATUS_QUOTE_ERROR
        ];
    }


    /**
     * @return array
     */
    public static function getPlaceOrderStatuses()
    {
        return [
            self::STATUS_ACTIVE,
            self::STATUS_ORDER_ERROR,
            self::STATUS_PAYMENT_ERROR
        ];
    }


    /**
     * @return array
     */
    public static function getErrorStatuses()
    {
        return [
            self::STATUS_ORDER_ERROR,
            self::STATUS_PAYMENT_ERROR,
            self::STATUS_QUOTE_ERROR,
            self::STATUS_SUBSCRIPTION_ERROR
        ];
    }


    /**
     * @return array
     */
    public static function getInactiveStatuses()
    {
        return [
            self::STATUS_CANCELED,
            self::STATUS_EXPIRED,
            self::STATUS_PAYMENT_ERROR
        ];
    }

    /**
     * @param string|null $status
     * @return string
     */
    public function getStatusLabel($status = null)
    {
        return Mage::helper('adyen_subscription')->__(self::getStatuses()[$status ? $status : $this->getStatus()]);
    }

    /**
     * @return array
     */
    public static function getStatusColors()
    {
        return array(
            self::STATUS_ACTIVE             => 'green',
            self::STATUS_QUOTE_ERROR        => 'darkred',
            self::STATUS_ORDER_ERROR        => 'darkred',
            self::STATUS_SUBSCRIPTION_ERROR => 'darkred',
            self::STATUS_CANCELED           => 'lightgray',
            self::STATUS_EXPIRED            => 'orange',
            self::STATUS_AWAITING_PAYMENT   => 'blue',
            self::STATUS_PAYMENT_ERROR      => 'red',
            self::STATUS_PAUSED             => 'orange',
        );
    }

    /**
     * @param string|null $status
     * @return string
     */
    public function getStatusColor($status = null)
    {
        return self::getStatusColors()[$status ? $status : $this->getStatus()];
    }

    /**
     * @param string|null $status
     * @return string
     */
    public function renderStatusBar($status = null, $inline = false)
    {
        if (is_null($status)) {
            $status = $this->getStatus();
        }

        $class = sprintf('status-pill status-pill-%s', $this->getStatusColor($status));

        if ($inline) {
            $class .= ' status-pill-inline';
        }

        return '<span class="' . $class . '"><span>' . $this->getStatusLabel($status) . '</span></span>';
    }

    /**
     * @param bool $multiple
     * @return array
     */
    public function getTermTypes($multiple = false)
    {
        return Mage::getModel('adyen_subscription/product_subscription')->getTermTypes($multiple);
    }

    /**
     * @return string
     */
    public function getTermLabel()
    {
        if (!$this->getTerm()) {
            // Only occurs when subscription is edited and no subscription items are in it
            return '-';
        }

        $multiple = $this->getTerm() > 1;
        $termTypeLabel = $this->getTermTypes($multiple)[$this->getTermType()];
        return Mage::helper('adyen_subscription')->__("Every %s %s", $this->getTerm(), $termTypeLabel);
    }

    /**
     * @return Mage_Customer_Model_Address|Mage_Sales_Model_Order_Address|void
     */
    public function getBillingAddress()
    {
        return Mage::getModel('adyen_subscription/subscription_address')
            ->getAddress($this, Adyen_Subscription_Model_Subscription_Address::ADDRESS_TYPE_BILLING);
    }

    /**
     * @return Mage_Customer_Model_Address|Mage_Sales_Model_Order_Address|void
     */
    public function getShippingAddress()
    {
        return Mage::getModel('adyen_subscription/subscription_address')
            ->getAddress($this, Adyen_Subscription_Model_Subscription_Address::ADDRESS_TYPE_SHIPPING);
    }

    /**
     * @return array
     */
    public function getBillingAddressData()
    {
        return $this->getBillingAddress()->getData();
    }

    /**
     * @return array
     */
    public function getShippingAddressData()
    {
        return $this->getShippingAddress()->getData();
    }

    /**
     * @return bool
     */
    public function canPause()
    {
        $result = new Varien_Object(array('is_allowed' => 1));

        $result->setData(
            'is_allowed',
            $this->getStatus() != self::STATUS_PAUSED && $this->getStatus() != self::STATUS_CANCELED
        );

        if (! $result->getData('is_allowed')) {
            Mage::dispatchEvent(
                'adyen_subscription_subscription_can_pause',
                array('subscription' => $this, 'result' => $result)
            );
        }

        return $result->getData('is_allowed');
    }

    /**
     * @return bool
     */
    public function isPaused()
    {
        return $this->getStatus() == self::STATUS_PAUSED;
    }

    /**
     * @return bool
     */
    public function canCancel()
    {
        $result = new Varien_Object(array('is_allowed' => 1));

        $result->setData('is_allowed', $this->getStatus() != self::STATUS_CANCELED);

        if ($result->getData('is_allowed')) {
            Mage::dispatchEvent(
                'adyen_subscription_subscription_can_cancel',
                array('subscription' => $this, 'result' => $result)
            );
        }

        return $result->getData('is_allowed');
    }

    /**
     * @return bool
     */
    public function isCanceled()
    {
        return $this->getStatus() == self::STATUS_CANCELED;
    }

    /**
     * @return string
     */
    public function getShippingMethodTitle()
    {
        $shippingCode = substr($this->getShippingMethod(), strpos($this->getShippingMethod(), '_') + 1);

        return $shippingTitle = Mage::getStoreConfig('carriers/' . $shippingCode . '/title');
    }

    /**
     * @return bool
     */
    public function canCreateQuote()
    {
        $result = new Varien_Object(array('is_allowed' => 1));

        if (! in_array($this->getStatus(), self::getScheduleQuoteStatuses())) {
            $result->setData('is_allowed', false);
        }

        if ($result->getData('is_allowed')) {
            Mage::dispatchEvent(
                'adyen_subscription_subscription_can_create_quote',
                array('subscription' => $this, 'result' => $result)
            );
        }

        return $result->getData('is_allowed');
    }

    /**
     * @return bool
     */
    public function canEditSubscription()
    {
        return ! $this->isCanceled();
    }

    /**
     * @return bool
     */
    public function canCreateOrder()
    {
        $result = new Varien_Object(array('is_allowed' => 1));

        if (! $this->getActiveQuote()) {
            $result->setData('is_allowed', false);
        } elseif (! in_array($this->getStatus(), self::getPlaceOrderStatuses())) {
            $result->setData('is_allowed', false);
        }

        if (! $result->getData('is_allowed')) {
            Mage::dispatchEvent(
                    'adyen_subscription_subscription_can_create_order',
                    array('subscription' => $this, 'result' => $result)
            );
        }

        return $result->getData('is_allowed');
    }

    /**
     * @return string
     */
    public function getCreatedAtFormatted($showTime = true)
    {
        return Mage::helper('core')->formatDate($this->getCreatedAt(), 'medium', $showTime);
    }

    /**
     * @return string
     */
    public function getUpdatedAtFormatted($showTime = true)
    {
        return Mage::helper('core')->formatDate($this->getUpdatedAt(), 'medium', $showTime);
    }

    /**
     * @return string
     */
    public function getScheduledAtFormatted($showTime = true)
    {
        return Mage::helper('core')->formatDate($this->getScheduledAt(), 'medium', $showTime);
    }

    /**
     * @return string
     */
    public function getEndsAtFormatted($showTime = true)
    {
        return Mage::helper('core')->formatDate($this->getEndsAt(), 'medium', $showTime);
    }

    /**
     * @param Mage_Sales_Model_Order $order
     * @return $this
     */
    public function loadByOrder(Mage_Sales_Model_Order $order)
    {
        $this->_getResource()->loadByOrder($this, $order);
        return $this;
    }

    /**
     * @return string|bool
     */
    public function getEndsAt()
    {
        return $this->getData('ends_at') != '0000-00-00 00:00:00' ? $this->getData('ends_at') : false;
    }
}
