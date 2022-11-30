<?php

namespace Drupal\rest_toolkit_commerce;
use Drupal\commerce_order\AddressBookInterface;
use Drupal\commerce_order\Entity\Order;
use Drupal\commerce_order\Entity\OrderInterface;
// Only used for the delete drafts right now:
use Drupal\commerce_payment\Entity\Payment;
use Drupal\commerce_payment\Entity\PaymentMethod;
use Drupal\commerce_shipping\Event\ShippingEvents;
// New.
use Drupal\commerce_shipping\Event\ShippingRatesEvent;
use Drupal\commerce_shipping\PackerManagerInterface;

/**
 * Class RTCommerceService.
 */
class RTCommerceService implements RTCommerceServiceInterface {

	/**
	 * Drupal\commerce_order\AddressBookInterface definition.
	 *
	 * @var \Drupal\commerce_order\AddressBookInterface
	 */
	protected $addressBook;

	/**
	 * Drupal\commerce_shipping\PackerManagerInterface definition.
	 *
	 * @var \Drupal\commerce_shipping\PackerManagerInterface
	 */
	protected $commerceShippingPackerManager;

	/**
	 * @var Boolean
	 */
	protected $devMode;

	/**
	 * Constructs a new RTCommerceService object.
	 */
	public function __construct(AddressBookInterface $commerce_order_address_book, PackerManagerInterface $commerce_shipping_packer_manager) {
		$this->addressBook = $commerce_order_address_book;
		$this->commerceShippingPackerManager = $commerce_shipping_packer_manager;

		global $base_url;
		$this->devMode = FALSE;
		// @TODO Better discovery at some point.
		$devUrls = [
			'daugment.',
			'ddev.site',
			'stage-api.',
			'stage.api.',
		];
		foreach ($devUrls as $devUrl) {
			if (stripos($base_url, $devUrl) !== FALSE) {
				$this->devMode = TRUE;
			}
		}

	}

	/**
	 * Based on how we are using the system.  Any time we start a new order,
	 * delete the previous draft(s).
	 *
	 * @param  [type] $uid [description]
	 * @return [type]      [description]
	 */
	public function deleteAllDraftOrders($uid, $type = 'default') {
		if (empty($uid)) {
			return;
		}
		// Load this user's draft orders and delete before proceeding.
		$orders = \Drupal::entityTypeManager()->getStorage('commerce_order')
			->loadByProperties([
				'state' => 'draft',
				'uid' => $uid,
				'type' => $type,
			]);

		if (empty($orders)) {
			return;
		}

		foreach ($orders as $order) {
			if (empty($order->getTotalPaid())) {
				$order->setRefreshState(OrderInterface::REFRESH_SKIP);
				// inspect('RTCommerceService: Deleting an order that had bad variations in it: ' . $order->id());

				$order->delete();
				continue;
			}
			if ($order->getTotalPaid()->isZero()) {
				$order->delete();
			}
		}

	}

	/**
	 * Logic to ensure the user account whether anonymous or authenticated.
	 *
	 * @param $postData
	 *   The POSTed values.
	 *
	 * @return $user
	 *   The user to attach to the order as customer.
	 */
	public function ensureOrderUser($postData) {
		$user = \Drupal::currentUser();
		// If not logged in, and mail is sent, CREATE USER.
		if ($user->id()) {
			// Load user.
			$user = \Drupal\user\Entity\User::load($user->id());
			return $user;
		}

		// If UID was sent, maybe a link?
		if (!empty($postData['uid'])) {
			$user = \Drupal\user\Entity\User::load($postData['uid']);
		}

		// If anonymous and neither mail or uid was sent, we're done.
		if (empty($postData['mail'])) {
			return $user;
		}

		// Attempt to load or create user by mail.
		if (!$user = user_load_by_mail($postData['mail'])) {
			// User didn't exist, create the user account.
			$user = _rest_toolkit_create_user($postData['mail']);
		}

		return $user;
	}

	/**
	 * Determine shipping address.
	 *
	 * @param  [type] $postData [description]
	 * @param  [type] $user     [description]
	 * @return [type]           [description]
	 */
	public function discernShippingAddress($postData, $user) {
		$shippingProfile = FALSE;

		// If shipping is to be the same as billing, just let it load that one.
		if (!empty($postData['shipping_same_as_billing'])) {
			return FALSE;
		}

		// Otherwise, get to work.
		if (!empty($postData['shipping_profile'])) {
			$shippingProfile = \Drupal\profile\Entity\Profile::load($postData['shipping_profile']);
			// Handle a funky situation with bogus profile ID given?
			if (empty($shippingProfile) || $shippingProfile->getOwnerId() != $user->id()) {
				// return $this->error410('Shipping address not found, try creating a new one.');
			} else {
				// $shippingProfile->setData('copy_to_address_book', TRUE);
				// If we use the addressBook copy method, we shouldn't need to save here.
				// $shippingProfile->save();
				// Making too many duplicates, let's see what Commerce's own behavior does.
				// @todo Lookup if we already have a good version of this address first.
				// It appears we should copy when existing profile is used.
				$this->addressBook->copy($shippingProfile, $user);
			}
		} else if (!empty($postData['shipping_address'])) {
			// Not sure why Profile entity type has both uid and owner property
			// (from the EntityOwner trait).
			$shippingProfile = \Drupal\profile\Entity\Profile::create([
				'type' => 'customer',
				'uid' => $user->id(),
				'owner' => $user->id(),
				'address' => $postData['shipping_address'],
			]);

			// So that a copy can be created for the customer.
			// $shippingProfile->setData('copy_to_address_book', TRUE);
			$shippingProfile->save();
			// This would only seemingly create extra copies if user didn't select "same billing as shipping", but entered in the same address anyway.
			// $this->addressBook->copy($shippingProfile, $user);
			// Because of changes in commerce setting uid=0 on order profiles to prevent orphans.
			// Making too many duplicates, let's see what Commerce's own behavior does.
			// @todo Lookup if we already have a good version of this address first.
			// $this->addressBook->copy($shippingProfile, $user);
		}

		return $shippingProfile;
	}

	/**
	 * Determine billing address.
	 *
	 * @param  [type] $postData [description]
	 * @param  [type] $user     [description]
	 * @return [type]           [description]
	 */
	public function discernBillingAddress($postData, $user) {
		$billingProfile = FALSE;

		if (!empty($postData['billing_profile'])) {
			$billingProfile = \Drupal\profile\Entity\Profile::load($postData['billing_profile']);
			// Handle bogus profile provided with separate error message?
			if (empty($billingProfile) || $billingProfile->getOwnerId() != $user->id()) {
				// return $this->error410('Billing address not found, try creating a new one.');
			} else {
				// JW - Testing to see if this will work, makes another copy of the address
				// since each order will set it to uid=0 now.
				// $billingProfile->setData('copy_to_address_book', TRUE);
				// $billingProfile->save();
				//
				// Making too many duplicates, let's see what Commerce's own behavior does.
				// @todo Lookup if we already have a good version of this address first.
				$this->addressBook->copy($billingProfile, $user);
			}
		} else if (!empty($postData['billing_address'])) {
			// Create the profile.
			$billingProfile = \Drupal::entityTypeManager()
				->getStorage('profile')->create([
				'type' => 'customer',
				'uid' => $user->id(),
				'owner' => $user->id(),
				'address' => $postData['billing_address'],
			]);

			// $billingProfile->setData('copy_to_address_book', TRUE);
			$billingProfile->save();
			$this->addressBook->copy($billingProfile, $user);
			// Now copy because of the fix for orphaned profiles on orders.
			// $billingProfile->setData('copy_to_address_book', TRUE);
			// Making too many duplicates, let's see what Commerce's own behavior does.
			// @todo Lookup if we already have a good version of this address first.
			// $this->addressBook->copy($billingProfile, $user);
		}
		/*else {
			            return $this->error400('You must choose a billing address.');
		*/

		return $billingProfile;
	}

	public function prepareShipments(&$order, $shippingProfile) {
		$proposedShipments = $shipments = [];
		// $packer = \Drupal::service('commerce_shipping.packer_manager');
		// $proposedShipments = $packer->pack($order, $shippingProfile);

		$proposedShipments = $this->commerceShippingPackerManager->pack($order, $shippingProfile);

		/*$testCrap = $this->commerceShippingPackerManager->packToShipments($order, $shippingProfile, []);
			          // $fofo = print_r($testCrap, true);
			          inspect($testCrap);

		*/

		// Debugging current.
		/*$this->debug('PROPOSED SHIPMENTS');
	      foreach ($proposedShipments as $something) {
	        $this->debug($something->getTitle() . ' ' . $something->getOrderId());
*/

		if (empty($proposedShipments)) {
			return [];
		}
		// inspect($proposedShipments);

		// Create Shipment from each ProposedShipment from Packer.
		foreach ($proposedShipments as $proposedShipment) {
			$newShipment = NULL;
			//dd($proposedShipment->getType());
			$newShipment = \Drupal\commerce_shipping\Entity\Shipment::create([
				'type' => $proposedShipment->getType(),
				'shipping_profile' => $shippingProfile,
				'order_id' => $order->id(),
				//'order_id' => $order,
			]);
			// This populates several of the values, and only requires type and a
			// ProposedShipment.
			$newShipment->populateFromProposedShipment($proposedShipment);

			// inspect($newShipment->getPackageType());
			// $newShipment->setTitle('Shipment for order: ' . $order->id());
			$shippingMethods = $this->loadAllShippingMethodsAvailable($newShipment);
			// dd($shippingMethods);
			// \Drupal::logger('rt-shipping')->debug('<pre>' . print_r($newShipment, TRUE) . '</pre>');
			// No applicable shipping_methods.
			if (empty($shippingMethods)) {
				continue;
				$error = 'One of your orders cannot by fulfilled by our current shipment options.';
				// return [];
			}

			// Otherwise, add it as a plugin we used.
			/*if (!in_array($shipping_method->getPlugin(), $pluginsUsed)) {
	          $pluginsUsed[] = $shipping_method->getPlugin();
*/

			// These should be sorted by cheapest first.
			foreach ($shippingMethods as $method) {
				if ($order->store_id->target_id == $method->stores->target_id) {
					// dd('Store', $method->stores, 'Order', $order->store_id);
				}
			}
			$shippingMethod = reset($shippingMethods);
			// dd($shippingMethods, $order->store_id);
			// This is where it gets intense.  For the shippingMethod, we now find
			// the shippingRates, which are already sorted by lowest, so use first.
			$shippingMethodPlugin = $shippingMethod->getPlugin();
			$shippingRates = $shippingMethodPlugin->calculateRates($newShipment);
			$shippingRate = reset($shippingRates);
			$shippingAmount = $shippingRate->getAmount();
			// Added for Printful to get from service since they don't set description.
			// @todo Evaluate for broader use.
			$newShipment->setTitle($shippingRate->getService()->getLabel());
			// Assign the shipping method.
			$newShipment->setShippingMethod($shippingMethod);
			// Now have to assign the amount of the method to the shipment, not sure
			// why, I suppose for ones with multiple methods.
			$newShipment->setAmount($shippingAmount);
			// Save the shipment.
			$newShipment->save();
			$shipments[] = $newShipment;

			// This should probably be after getting rates and method, but...
			$event = new ShippingRatesEvent([$shippingRate], $shippingMethod, $newShipment);
			\Drupal::service('event_dispatcher')->dispatch(ShippingEvents::SHIPPING_RATES, $event);
			$rates = $event->getRates();
		}

		/*if (empty($shipments)) {
			            return [];
		*/

		return $shipments;
	}

	/**
	 * Loads all applicable commerce_shipping_methods for this shipment.
	 *
	 * @param ShipmentInterface $shipment
	 *   The shipment.
	 *
	 * @return array $shipping_methods
	 *   The available shipping_methods.
	 */
	public function loadAllShippingMethodsAvailable(\Drupal\commerce_shipping\Entity\ShipmentInterface $shipment) {
		$order = $shipment->getOrder();
		$order_id = $order->store_id->target_id;
		// dd($order->store_id->target_id);
		$shipmentStorage = \Drupal::entityTypeManager()
			->getStorage('commerce_shipping_method');
		$query = $shipmentStorage->getQuery();
		$query
		//->condition('stores', $shipment->getOrder()->getStore()->id())
		->condition('stores', $order_id)
			->condition('status', TRUE);
		$result = $query->execute();

		// dd($result);

		if (empty($result)) {
			return [];
		}
		$shipping_methods = $shipmentStorage->loadMultiple($result);
		$pluginsUsed = [];
		// Allow the list of shipping methods to be filtered via code.
		/*$event = new FilterShippingMethodsEvent($shipping_methods, $shipment);
	      $shipmentStorage->eventDispatcher->dispatch(ShippingEvents::FILTER_SHIPPING_METHODS, $event);
*/
		// Evaluate conditions for the remaining ones.
		foreach ($shipping_methods as $shipping_method_id => $shipping_method) {
			if (!$shipping_method->applies($shipment)) {
				unset($shipping_methods[$shipping_method_id]);
				continue;
			}
			// Otherwise, add it as a plugin we used.
			/*if (!in_array($shipping_method->getPlugin(), $pluginsUsed)) {
	          $pluginsUsed[] = $shipping_method->getPlugin();
*/
		}
		$entityType = $shipmentStorage->getEntityType();
		uasort($shipping_methods, [$entityType->getClass(), 'sort']);

		return $shipping_methods;
	}

	/**
	 * Determines the payment gateway.
	 *
	 * @todo Needs some way to decide what gateway to use.  Could be setting.
	 *
	 * @return CommercePaymentGateway
	 */
	public function determinePaymentGateway() {
		$return = NULL;
		// Load gateway depending on if this is a testing/stage URL.
		$configMode = $this->devMode ? 'test' : 'live';

		// Now load the gateway.
		// @TODO: Needs a way to decide.
		// Eventually a config page with Conditions/Rules to determine (or evaluate and use those by Commerce core).
		$paymentGateway = \Drupal::entityTypeManager()
			->getStorage('commerce_payment_gateway')
			->loadByProperties([
				// 'plugin' => 'commerce_stripe',
				'status' => TRUE,
				'configuration.mode' => $configMode,
			]);

		if (!empty($paymentGateway)) {
			$return = reset($paymentGateway);
		}

		return $return;
	}

	/**
	 * { function_description }
	 *
	 * $postData needs:
	 * 'payment': {
	'number': '4444424444444440',
	'expiration': {
	'month': '12',
	'year': '2026'
	},
	'name_on_account': 'Jeff Testerson',
	'security_code': '999'
	'mail': 'OPTIONALemail@example.com'
	}
	 *
	 * @param      <type>  $order            The order
	 * @param      <type>  $merchantAccount  The merchant account
	 * @param      <type>  $postData         The post data
	 * @param      <type>  $reusable         The reusable
	 *
	 * @return     <type>  ( description_of_the_return_value )
	 */
	public function preparePayment($order, $paymentGateway, $postData, $reusable = TRUE) {
		// Get the commerce_payment_gateway plugin.
		$paymentGatewayPlugin = $paymentGateway->getPlugin();
		// This returns the PaymentGateway's config for payment method type
		// e.g. 'credit_card'.
		$paymentMethodType = $paymentGatewayPlugin->getDefaultPaymentMethodType();
		// Catch exceptions when creating card.
		try {
			// Create the inital (unsaved) PaymentMethod.
			$paymentMethod = PaymentMethod::create([
				'payment_gateway' => $paymentGateway,
				'type' => $paymentMethodType->getPluginId(),
				'uid' => $order->getCustomer(),
				'reusable' => $reusable,
				// 'remote_id' => $remote_id,
				'billing_profile' => $order->getBillingProfile(),
				//'is_default' => TRUE,
				//'expires' => get timestamp from card exp date,
			]);

			// Gateway handling and ->save().
			// $paymentGatewayPlugin->createPaymentMethod($paymentMethod, $postData['payment']);
		} catch (\Throwable $e) {
			inspect($e);
			// Now throw it for the parent.
			// @todo Even bother catching it here?
			throw $e;
			//return $this->send400('There was a problem using this card.  Please double check the values you input and try again.');
		}

		// Add gateway and payment method to the Order.
		try {
			$order->set('payment_gateway', $paymentGateway);
			$order->set('payment_method', $paymentMethod);
		} catch (\Throwable $e) {
			inspect($e);
			// Now throw it for the parent.
			// @todo Even bother catching it here?
			throw $e;
			//return $this->send400('There was a problem using this card.  Please double check the values you input and try again.');
		}
		try {
			// Create the inital (unsaved) Payment.
			$payment = Payment::create([
				'type' => 'payment_default',
				// See: commerce/modules/payment/commerce_payment.workflows.yml
				// authorize, authorize_capture, capture, completed
				'state' => 'new',
				//'amount' => $order->getTotalPrice(),
				'amount' => $order->getBalance(),
				'payment_gateway' => $paymentGateway->id(),
				// Should go by the gateway, we have test and live.
				// Here we could check for accessing domain also, may not need.
				//'payment_gateway_mode' => 'live',
				'order_id' => $order->id(),
				// 'remote_id' => GET VALUE FROM GATEWAY,
				// 'remote_state' => GET VALUE FROM GATEWAY,
				'payment_method' => $paymentMethod,
			]);

			// Gateway is supposd to ->save().
			$payment->save();
			$order->save();
		} catch (\Throwable $e) {
			inspect($e);
			// Now throw it for the parent.
			// @todo Even bother catching it here?
			throw $e;
			//return $this->send400('There was a problem using this card.  Please double check the values you input and try again.');
		}

		return $payment;
	}

}
