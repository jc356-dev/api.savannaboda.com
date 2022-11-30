<?php

namespace Drupal\aquila\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\rest_toolkit\RestToolkitEndpointTrait;
use Stripe\Stripe;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Serializer\Encoder\JsonEncoder;
use Symfony\Component\Serializer\Normalizer\GetSetMethodNormalizer;
use Symfony\Component\Serializer\Serializer;

/**
 * Returns responses for aquila routes.
 */
class AquilaController extends ControllerBase {
	use RestToolkitEndpointTrait;

	/**
	 * Get podcast endpoint.
	 */
	public function get_podcast() {
		$episodes = [];
		$client = \Drupal::httpClient();

		try {
			$response = $client->get('https://api.transistor.fm/v1/episodes/?show_id&=10407&pagination[page]=1&pagination[per]=6', [
				'headers' => [
					'x-api-key' => "wDP2nWbaZbRZAXutPWjkGw",
				],
			]);
			// Expected result.
			// getBody() returns an instance of Psr\Http\Message\StreamInterface.
			// @see http://docs.guzzlephp.org/en/latest/psr7.html#body
			$data = json_decode($response->getBody(), TRUE);
		} catch (RequestException $e) {
			watchdog_exception('my_module', $e);
		}
		// dd(json_decode($data, TRUE));
		return $this->sendResponse($data);
	}

	public function create_ac_contact(Request $request) {
		$postData = $request->getContent();
		$client = \Drupal::httpClient();
		$headers = array('Api-Token' => "00a7da9f53945821f885abaadb04b24a050b322dc2525ecaf8cfd861526d26241f1f2902");
		// $request = $client->createRequest('GET', 'https://api.transistor.fm/v1/episodes');
		// $request->addHeader('x-api-key', "wDP2nWbaZbRZAXutPWjkGw");

		try {
			$response = $client->post(
				'https://savannaboda.api-us1.com/api/3/contacts',
				array('headers' => $headers, 'body' => $postData)
			);
			$data = json_decode($response->getBody(), TRUE);
		} catch (RequestException $e) {
			watchdog_exception('my_module', $e);
		}
		// dd($data);
		return $this->sendResponse($postData);
	}

	/**
	 * Get (Load) an entity.
	 *
	 * @return object $entity
	 *   Return loaded entity.
	 */
	public function get($entity_type, $id) {
		$load_by_field = FALSE;
		if (!empty($this->request->query->get('load_by_field'))) {
			$load_by_field = $this->request->query->get('load_by_field');
		}
		$entity = [];
		$stopFields = [];
		$usesOwnerTrait = TRUE;

		switch ($entity_type) {
		// case 'commerce_store':
		//   break;
		// case 'commerce_product':
		//   break;
		case 'user':
			$usesOwnerTrait = FALSE;
			$entity = $this->loadEntity($entity_type, $id);

			// Prevent some fields on user that could cause issues.
			$stopFields = [
				'init',
				'changed',
				'created',
				'pass',
				'login',
				'preferred_admin_langcode',
				'role_change',
				'roles',
				/*'status',
					          'uid',
					          'user_picture',
				*/
			];

			break;

		default:
			$entity = $this->loadEntity($entity_type, $id, $load_by_field);
			break;
		}

		// $usesOwnerTrait = in_array(Drupal\user\EntityOwnerTrait::class, class_uses($entity::class));

		// If user is owner of this entity, inform normalizer it can send sensitive fields.
		if ($usesOwnerTrait && $entity->getOwnerId() == $this->user->id()) {
			$this->normalizerContext['is_owner'] = TRUE;
		} elseif (!$usesOwnerTrait && $entity_type == 'user') {
			if ($entity->id() == $this->user->id()
				// @TODO: If necessary, any particular role to allow admins to see sensitive user fields.
				 || $this->user->hasPermission('administer site configuration')) {
				$this->normalizerContext['is_owner'] = TRUE;
			}
		}

		return $this->sendNormalizedResponse($entity, $stopFields);
	}

	/**
	 * Patch (Update) an entity.
	 *
	 * @return object $entity
	 *   Return updated entity.
	 */
	public function patch($entity_type, $id) {
		$entity = [];
		$this->postData['entity_type'] = $entity_type;
		// $this->postData['type'] = $bundle;
		$this->postData['id'] = $id;
		$entity = $this->loadEntity($entity_type, $id);

		switch ($entity_type) {
		// case 'commerce_store':
		//   break;
		// case 'commerce_product':
		//   break;
		case 'user':
			// @todo better check for permission.
			if ($this->user->id() != $id && !$this->user->hasPermission('administer site configuration')) {
				return $this->send403('Cannot edit others');
			}

			// @todo: Carry over my work here if we need this - jw.
			// $this->handleUserPass($entity);

			// Prevent some fields on user that could cause issues.
			$stopFields = [
				'init',
				'changed',
				'created',
				'login',
				'preferred_admin_langcode',
				'role_change',
				'roles',
				'status',
				'uid',
				'user_picture',
				'uuid',
			];

			foreach ($stopFields as $stopField) {
				unset($this->postData[$stopField]);
			}

			foreach ($this->postData as $fieldName => $postedData) {
				if (empty($postedData)) {
					unset($this->postData[$fieldName]);
				}
			}

			unset($entity);
			$entity = $this->deNormalizePostData(TRUE);
			break;

		default:
			// For now, don't allow anything else for security.
			return $this->send403('Not allowed.');

			// Blanket default if we handle permissions another way.
			unset($entity);
			$entity = $this->deNormalizePostData(TRUE);
			break;
		}

		return $this->sendNormalizedResponse($entity);
	}

	/**
	 * Helper function to load an entity;
	 *
	 * @param      <type>  $entity_type  The entity type
	 * @param      <type>  $id           The identifier
	 *
	 * @return     <type>  ( description_of_the_return_value )
	 */
	public function loadEntity($entity_type, $id, $load_by_field) {
		$entity_storage = \Drupal::entityTypeManager()->getStorage($entity_type);
		if ($load_by_field) {
			$entity = $entity_storage
				->loadByProperties([
					$load_by_field => '/' . $id,
				]);
			$entity = reset($entity);
		} else {
			$entity = $entity_storage->load($id);
		}

		if (empty($entity)) {
			// If we couldn't load the entity.
			$message = 'Could not load ' . $entity_type .
				' ID: ' . $id;
			return $this->send400($message);
		}
		return $entity;
	}

	public function stripe_pay(Request $request) {
		// Loading the configuration.
		$config = \Drupal::config('stripe.settings');

		// Setting the secret key.
		$secretKey = $config->get('stripe.use_test') ?
		$config->get('stripe.sk_test') :
		$config->get('stripe.sk_live');

		// \Drupal::logger('aquila-stripe')->debug('<pre>Secret' . print_r($secretKey, TRUE) . '</pre>');
		Stripe::setApiKey($secretKey);

		$encoders = [new JsonEncoder()];
		$normalizers = [new GetSetMethodNormalizer()];

		$serializer = new Serializer($normalizers, $encoders);
		$content = $request->getContent();
		// \Drupal::logger('Content')->debug($content);

		// dd($content);
		$postData = $serializer->decode($content, 'json');
		$postData = (object) $postData;
		// \Drupal::logger('aquila-stripe')->debug('<pre>postData ' . print_r($postData, TRUE) . '</pre>');
		$amount = $postData->amount * 100;
		// \Drupal::logger('aquila-stripe')->debug('<pre>amount ' . print_r($amount, TRUE) . '</pre>');

		// if(isset($postData->)coupon'])){
		//   $entityManager = \Drupal::entityTypeManager()->getStorage('coupon_codes');
		//   $coupon = $entityManager->getQuery()
		//     ->condition('field_coupon', $postData->coupon'])
		//     ->execute();
		//   // dd($coupon);
		//   if($coupon){
		//     $couponResponse =  $entityManager->load(reset($coupon));
		//     // dd($couponResponse);
		//     // dd($couponResponse->field_discount->value);
		//     $amount = intval(floor($amount - ($amount * ($couponResponse->field_discount->value/100))));
		//   }
		// }

		// Set your secret key. Remember to switch to your live secret key in production.
		// See your keys here: https://dashboard.stripe.com/account/apikeys

		// dd($postData->clienttoken']['id']);

		// // Creating the customer
		try {
			$customer = \Stripe\Customer::create([
				'email' => $postData->receipt_email,
				'source' => $postData->token['id'],
			]);

		} catch (\Stripe\Exception\CardException $e) {
			\Drupal::logger('aquila-stripe-pay')->debug('<pre>Error' . print_r($e->getError(), TRUE) . '</pre>');
			// dd($e->getError()->message);
			$response = $e->getError()->message;

			throw new BadRequestHttpException($response);
			// return new Response(json_encode($response));
		}

		// // Creating the payment intent.
		// try {

		// 	$intent = \Stripe\PaymentIntents::create([
		// 		'amount' => $amount,
		// 		'currency' => 'usd',
		// 		'payment_method_types' => ['card'],
		// 	]);
		// } catch (\Stripe\Exception\CardException $e) {
		// 	//dd($e->getError()->message);
		// 	$response = ['error' => $e->getError()->message];
		// }
		try {
			if ($customer) {

				$charge = \Stripe\Charge::create([
					'amount' => $amount,
					'currency' => 'usd',
					'source' => $postData->token['card']['id'],
					'description' => 'Charge for ' . $postData->receipt_email,
					'receipt_email' => $postData->receipt_email,
					'customer' => $customer->id,
				]);

				$response = [$charge->status => "Your payment went through", 'charge' => $charge];
			} else {
				$response = ['error' => 'No customer was created'];

			}

		} catch (\Stripe\Exception\CardException $e) {
			//dd($e->getError()->message);
			$response = ['error' => $e->getError()->message];
		}
		return new Response(json_encode($response));
	}

}
