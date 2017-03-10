<?php
declare(strict_types=1);
/**
 * Shopware 5
 * Copyright (c) shopware AG
 *
 * According to our dual licensing model, this program can be used either
 * under the terms of the GNU Affero General Public License, version 3,
 * or under a proprietary license.
 *
 * The texts of the GNU Affero General Public License with an additional
 * permission and of our proprietary license can be found at and
 * in the LICENSE file you have received along with this program.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * "Shopware" is a registered trademark of shopware AG.
 * The licensing of the program under the AGPLv3 does not imply a
 * trademark license. Therefore any rights, title and interest in
 * our trademarks remain entirely with us.
 */

namespace Shopware\Bundle\CartBundle\Infrastructure\Cart;

use Shopware\Bundle\CartBundle\Domain\Cart\CartContext;
use Shopware\Bundle\CartBundle\Domain\Cart\CartContextInterface;
use Shopware\Bundle\CartBundle\Domain\Customer\Customer;
use Shopware\Bundle\CartBundle\Domain\Delivery\DeliveryMethod;
use Shopware\Bundle\CartBundle\Domain\Payment\PaymentMethod;
use Shopware\Bundle\CartBundle\Infrastructure\Customer\AddressGateway;
use Shopware\Bundle\CartBundle\Infrastructure\Customer\CustomerService;
use Shopware\Bundle\CartBundle\Infrastructure\Delivery\DeliveryMethodGateway;
use Shopware\Bundle\CartBundle\Infrastructure\Payment\PaymentMethodGateway;
use Shopware\Bundle\StoreFrontBundle\Service\ContextServiceInterface;
use Shopware\Bundle\StoreFrontBundle\Struct\ShopContextInterface;
use Shopware\Bundle\StoreFrontBundle\Struct\TranslationContext;

class CartContextService implements CartContextServiceInterface
{
    /**
     * @var ContextServiceInterface
     */
    private $shopContextService;

    /**
     * @var \Enlight_Components_Session_Namespace
     */
    private $session;

    /**
     * @var DeliveryMethodGateway
     */
    private $deliveryMethodGateway;

    /**
     * @var PaymentMethodGateway
     */
    private $paymentMethodGateway;

    /**
     * @var AddressGateway
     */
    private $addressGateway;

    /**
     * @var CustomerService
     */
    private $customerService;

    /**
     * @var CartContext
     */
    private $context;

    /**
     * @var \Shopware_Components_Config
     */
    private $config;

    /**
     * @param ContextServiceInterface               $shopContextService
     * @param \Enlight_Components_Session_Namespace $session
     * @param DeliveryMethodGateway                 $deliveryMethodGateway
     * @param PaymentMethodGateway                  $paymentMethodGateway
     * @param AddressGateway                        $addressGateway
     * @param CustomerService                       $customerService
     * @param \Shopware_Components_Config           $config
     */
    public function __construct(
        ContextServiceInterface $shopContextService,
        \Enlight_Components_Session_Namespace $session,
        DeliveryMethodGateway $deliveryMethodGateway,
        PaymentMethodGateway $paymentMethodGateway,
        AddressGateway $addressGateway,
        CustomerService $customerService,
        \Shopware_Components_Config $config
    ) {
        $this->shopContextService = $shopContextService;
        $this->session = $session;
        $this->deliveryMethodGateway = $deliveryMethodGateway;
        $this->paymentMethodGateway = $paymentMethodGateway;
        $this->addressGateway = $addressGateway;
        $this->customerService = $customerService;
        $this->config = $config;
    }

    /**
     * {@inheritdoc}
     */
    public function getCartContext(): CartContextInterface
    {
        if ($this->context) {
            return $this->context;
        }

        $this->initializeContext();

        return $this->context;
    }

    /**
     * {@inheritdoc}
     */
    public function initializeContext(): void
    {
        $shopContext = $this->shopContextService->getShopContext();

        $customer = $this->getStoreFrontCustomer($shopContext);
        $addresses = $this->getStoreFrontCheckoutAddresses($shopContext->getTranslationContext(), $customer);

        $this->context = new CartContext(
            $shopContext,
            $this->getStoreFrontPaymentMethod($shopContext->getTranslationContext(), $customer),
            $this->getStoreFrontDeliveryMethod($shopContext->getTranslationContext()),
            $customer,
            $addresses['billing'],
            $addresses['shipping']
        );
    }

    private function getStoreFrontCustomer(ShopContextInterface $context): ? Customer
    {
        if (!($id = $this->session->get('sUserId'))) {
            return null;
        }

        $customer = $this->customerService->getList([$id], $context);

        return array_shift($customer);
    }

    private function getStoreFrontCheckoutAddresses(TranslationContext $context, ?Customer $customer): array
    {
        $ids = [];

        //switched in frontend?
        if (($shippingId = $this->session->get('checkoutShippingAddressId')) != null) {
            $ids[] = $shippingId;
        }
        if (($billingId = $this->session->get('checkoutBillingAddressId')) !== null) {
            $ids[] = $billingId;
        }

        //set customer default address as default result
        $result = [
            'billing' => $customer ? $customer->getDefaultBillingAddress() : null,
            'shipping' => $customer ? $customer->getDefaultShippingAddress() : null,
        ];

        if (0 === count($ids)) {
            return $result;
        }

        $addresses = $this->addressGateway->getList($ids, $context);

        if ($billingId) {
            $result['billing'] = $addresses[$billingId];
        }
        if ($shippingId) {
            $result['shipping'] = $addresses[$shippingId];
        }

        return $result;
    }

    private function getStoreFrontDeliveryMethod(TranslationContext $context): DeliveryMethod
    {
        if (!($id = $this->session->get('sDispatch'))) {
            $id = 9;
        }

        $services = $this->deliveryMethodGateway->getList([$id], $context);

        return array_shift($services);
    }

    private function getStoreFrontPaymentMethod(TranslationContext $context, ?Customer $customer): PaymentMethod
    {
        $id = $this->session->get('sPaymentID');

        //preselect last payment of customer if not selected
        if (!$id && $this->hasLastPayment($customer)) {
            return $customer->getLastPaymentMethod();

        //preselect payment of customer registration
        } elseif (!$id && $this->hasPresetPayment($customer)) {
            return $customer->getPresetPaymentMethod();
        }

        //customer not logged in
        if (!$id) {
            $id = (int) $this->config->offsetGet('paymentdefault');
        }

        $services = $this->paymentMethodGateway->getList([$id], $context);

        return array_shift($services);
    }

    private function hasPresetPayment(?Customer $customer): bool
    {
        if ($customer === null) {
            return false;
        }

        return $customer->getPresetPaymentMethod() !== null;
    }

    private function hasLastPayment(?Customer $customer): bool
    {
        if ($customer === null) {
            return false;
        }

        return $customer->getLastPaymentMethod() !== null;
    }
}
