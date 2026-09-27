<?php
/**
 * Parnian Pay — ParnianCoin (PARC) payments for OpenCart 3.0.x (storefront).
 *
 * confirm  : checkout button -> invoice created server-to-server -> buyer redirected to the hosted payment page
 * callback : buyer returns -> invoice re-checked with the gateway (the return itself proves nothing)
 * webhook  : signed events from Parnian Pay -> invoice re-checked -> order updated
 */
class ControllerExtensionPaymentParnianPay extends Controller {

	public function index() {
		$this->load->language('extension/payment/parnian_pay');
		$data['button_confirm'] = $this->language->get('button_confirm');
		$data['text_description'] = $this->language->get('text_description');
		$data['text_loading'] = $this->language->get('text_loading');
		return $this->load->view('extension/payment/parnian_pay', $data);
	}

	public function confirm() {
		$this->load->language('extension/payment/parnian_pay');
		$this->load->model('extension/payment/parnian_pay');
		$this->load->model('checkout/order');
		$m = $this->model_extension_payment_parnian_pay;
		$json = array();

		if (!isset($this->session->data['order_id']) || !isset($this->session->data['payment_method']['code']) || $this->session->data['payment_method']['code'] != 'parnian_pay') {
			$json['error'] = $this->language->get('error_start');
			return $this->json($json);
		}
		$order_id = (int)$this->session->data['order_id'];
		$order = $this->model_checkout_order->getOrder($order_id);
		if (!$order) {
			$json['error'] = $this->language->get('error_start');
			return $this->json($json);
		}

		// Order total in the currency the shopper sees; the gateway converts it with the merchant's rate.
		$decimals = max(0, (int)$this->currency->getDecimalPlace($order['currency_code']));
		$fiat = number_format($this->currency->format($order['total'], $order['currency_code'], $order['currency_value'], false), $decimals, '.', '');
		$fiat_key = $fiat . ' ' . $order['currency_code'];
		$client = $m->client();

		// Reuse a still-open invoice for the same total (shopper pressed confirm again).
		$row = $m->getInvoiceRow($order_id);
		if ($row && $row['fiat'] === $fiat_key && in_array($row['state'], array('pending', 'confirming'), true)) {
			$inv = $client->getInvoice($row['invoice_id']);
			if ($inv !== null && in_array($inv['status'], array('pending', 'confirming'), true)) {
				$json['redirect'] = $inv['pay_url'];
				return $this->json($json);
			}
		}

		$body = array(
			'fiat_amount'   => $fiat,
			'fiat_currency' => $order['currency_code'],
			'order_id'      => (string)$order_id,
			'description'   => mb_substr(sprintf($this->language->get('text_invoice_description'), $order_id, $this->config->get('config_name')), 0, 200),
			// OpenCart 3 escapes & as &amp; in links (meant for HTML); the gateway needs the raw URL.
			'return_url'    => str_replace('&amp;', '&', $this->url->link('extension/payment/parnian_pay/callback', 'order_id=' . $order_id, true)),
			'expires_in'    => max(5, min(1440, (int)$this->config->get('payment_parnian_pay_expires') ?: 30)) * 60,
		);
		$idem = 'oc-' . $order_id . '-' . substr(md5($fiat_key . '|' . ($row ? $row['invoice_id'] : '')), 0, 16);
		$inv = $client->createInvoice($body, $idem);

		if ($inv === null) {
			$m->log('create invoice failed for order ' . $order_id . ': ' . $client->last_code . ' ' . $client->last_error, true);
			if (in_array($client->last_code, array('merchant_not_approved', 'rate_not_set'), true)) {
				$m->clearStatus();
				$json['error'] = $this->language->get('error_unavailable');
			} else {
				$json['error'] = $this->language->get('error_start');
			}
			return $this->json($json);
		}

		$m->saveInvoiceRow($order_id, $inv, $fiat_key);
		$this->model_checkout_order->addOrderHistory($order_id, (int)$this->config->get('payment_parnian_pay_pending_status_id') ?: 1,
			sprintf($this->language->get('text_comment_created'), $inv['amount'], $inv['id']), false);
		$m->log('invoice ' . $inv['id'] . ' for order ' . $order_id . ' amount ' . $inv['amount']);

		$json['redirect'] = $inv['pay_url'];
		return $this->json($json);
	}

	/** Buyer is back from the payment page. */
	public function callback() {
		$this->load->language('extension/payment/parnian_pay');
		$this->load->model('extension/payment/parnian_pay');
		$m = $this->model_extension_payment_parnian_pay;

		$order_id = isset($this->request->get['order_id']) ? (int)$this->request->get['order_id'] : 0;
		$row = $order_id ? $m->getInvoiceRow($order_id) : null;
		if (!$row) {
			$this->response->redirect($this->url->link('checkout/cart', '', true));
			return;
		}
		$state = $row['state'];
		$inv = $m->client()->getInvoice($row['invoice_id']);
		if ($inv !== null) {
			$state = $m->sync($order_id, $inv);
		}

		if (in_array($state, array('paid', 'confirming', 'review'), true)) {
			// Paid or seen on the chain: the order is placed; confirmation (if pending) arrives by webhook.
			$this->response->redirect($this->url->link('checkout/success', '', true));
		} elseif ($state === 'expired') {
			$this->response->redirect($this->url->link('checkout/failure', '', true));
		} else {
			$this->session->data['error'] = $this->language->get('error_not_paid');
			$this->response->redirect($this->url->link('checkout/checkout', '', true));
		}
	}

	/** POST index.php?route=extension/payment/parnian_pay/webhook */
	public function webhook() {
		$this->load->model('extension/payment/parnian_pay');
		$m = $this->model_extension_payment_parnian_pay;
		$m->client(); // loads the library

		$raw = (string)file_get_contents('php://input');
		$sig = isset($_SERVER['HTTP_PARNIAN_SIGNATURE']) ? (string)$_SERVER['HTTP_PARNIAN_SIGNATURE'] : '';
		if (!ParnianPayClient::verifySignature($raw, $sig, (string)$this->config->get('payment_parnian_pay_webhook_secret'))) {
			$m->log('webhook rejected: bad signature', true);
			return $this->plain(400, 'bad signature');
		}

		$event = json_decode($raw, true);
		$data = (is_array($event) && isset($event['data']['invoice']) && is_array($event['data']['invoice'])) ? $event['data']['invoice'] : null;
		$order_id = $data && !empty($data['order_id']) ? (int)$data['order_id'] : 0;
		$row = $order_id ? $m->getInvoiceRow($order_id) : null;
		$m->log('webhook ' . (isset($event['type']) ? $event['type'] : '?') . ' invoice ' . (isset($data['id']) ? $data['id'] : '?'));

		if (!$row || $row['invoice_id'] !== (isset($data['id']) ? $data['id'] : '')) {
			return $this->plain(200, 'ignored'); // not ours, or a superseded invoice
		}
		// Trust the gateway's current state, not only the event body.
		$inv = $m->client()->getInvoice($row['invoice_id']);
		if ($inv === null) {
			$m->log('webhook re-check failed', true);
			return $this->plain(503, 'retry');
		}
		$m->sync($order_id, $inv);
		return $this->plain(200, 'ok');
	}

	private function json(array $json) {
		$this->response->addHeader('Content-Type: application/json');
		$this->response->setOutput(json_encode($json));
	}

	private function plain($code, $text) {
		$reasons = array(200 => 'OK', 400 => 'Bad Request', 503 => 'Service Unavailable');
		$this->response->addHeader(($this->request->server['SERVER_PROTOCOL'] ?? 'HTTP/1.1') . ' ' . $code . ' ' . $reasons[$code]);
		$this->response->addHeader('Content-Type: text/plain');
		$this->response->setOutput($text);
	}
}
