<?php
namespace Opencart\Catalog\Controller\Extension\ParnianPay\Payment;
/**
 * Parnian Pay — ParnianCoin (PARC) payments for OpenCart 4.0.2+ (storefront).
 *
 * confirm  : checkout button -> invoice created server-to-server -> buyer redirected to the hosted payment page
 * callback : buyer returns -> invoice re-checked with the gateway (the return itself proves nothing)
 * webhook  : signed events from Parnian Pay -> invoice re-checked -> order updated
 */
class ParnianPay extends \Opencart\System\Engine\Controller {

	public function index(): string {
		$this->load->language('extension/parnian_pay/payment/parnian_pay');
		$data['language'] = $this->config->get('config_language');
		return $this->load->view('extension/parnian_pay/payment/parnian_pay', $data);
	}

	public function confirm(): void {
		$this->load->language('extension/parnian_pay/payment/parnian_pay');
		$this->load->model('extension/parnian_pay/payment/parnian_pay');
		$this->load->model('checkout/order');
		$m = $this->model_extension_parnian_pay_payment_parnian_pay;
		$json = [];

		if (!isset($this->session->data['order_id'])) {
			$json['error'] = $this->language->get('error_order');
		} elseif (!isset($this->session->data['payment_method']['code']) || $this->session->data['payment_method']['code'] != 'parnian_pay.parnian_pay') {
			$json['error'] = $this->language->get('error_start');
		}
		$order = $json ? [] : $this->model_checkout_order->getOrder((int)$this->session->data['order_id']);
		if (!$json && !$order) {
			$json['error'] = $this->language->get('error_order');
		}
		if ($json) {
			$this->json($json);
			return;
		}
		$order_id = (int)$order['order_id'];

		// Order total in the currency the shopper sees; the gateway converts it with the merchant's rate.
		$decimals = max(0, (int)$this->currency->getDecimalPlace($order['currency_code']));
		$fiat = number_format((float)$this->currency->format((float)$order['total'], $order['currency_code'], (float)$order['currency_value'], false), $decimals, '.', '');
		$fiat_key = $fiat . ' ' . $order['currency_code'];
		$client = $m->client();

		// Reuse a still-open invoice for the same total (shopper pressed confirm again).
		$row = $m->getInvoiceRow($order_id);
		if ($row && $row['fiat'] === $fiat_key && in_array($row['state'], ['pending', 'confirming'], true)) {
			$inv = $client->getInvoice($row['invoice_id']);
			if ($inv !== null && in_array($inv['status'], ['pending', 'confirming'], true)) {
				$this->json(['redirect' => $inv['pay_url']]);
				return;
			}
		}

		$body = [
			'fiat_amount'   => $fiat,
			'fiat_currency' => $order['currency_code'],
			'order_id'      => (string)$order_id,
			'description'   => mb_substr(sprintf($this->language->get('text_invoice_description'), $order_id, $this->config->get('config_name')), 0, 200),
			'return_url'    => $this->url->link('extension/parnian_pay/payment/parnian_pay.callback', 'language=' . $this->config->get('config_language') . '&order_id=' . $order_id, true),
			'expires_in'    => max(5, min(1440, (int)$this->config->get('payment_parnian_pay_expires') ?: 30)) * 60,
		];
		$idem = 'oc-' . $order_id . '-' . substr(md5($fiat_key . '|' . ($row ? $row['invoice_id'] : '')), 0, 16);
		$inv = $client->createInvoice($body, $idem);

		if ($inv === null) {
			$m->log('create invoice failed for order ' . $order_id . ': ' . $client->last_code . ' ' . $client->last_error, true);
			if (in_array($client->last_code, ['merchant_not_approved', 'rate_not_set'], true)) {
				$m->clearStatus();
				$json['error'] = $this->language->get('error_unavailable');
			} else {
				$json['error'] = $this->language->get('error_start');
			}
			$this->json($json);
			return;
		}

		$m->saveInvoiceRow($order_id, $inv, $fiat_key);
		$this->model_checkout_order->addHistory($order_id, (int)$this->config->get('payment_parnian_pay_pending_status_id') ?: 1,
			sprintf($this->language->get('text_comment_created'), $inv['amount'], $inv['id']));
		$m->log('invoice ' . $inv['id'] . ' for order ' . $order_id . ' amount ' . $inv['amount']);

		$this->json(['redirect' => $inv['pay_url']]);
	}

	/** Buyer is back from the payment page. */
	public function callback(): void {
		$this->load->language('extension/parnian_pay/payment/parnian_pay');
		$this->load->model('extension/parnian_pay/payment/parnian_pay');
		$m = $this->model_extension_parnian_pay_payment_parnian_pay;
		$lang = 'language=' . $this->config->get('config_language');

		$order_id = (int)($this->request->get['order_id'] ?? 0);
		$row = $order_id ? $m->getInvoiceRow($order_id) : null;
		if (!$row) {
			$this->response->redirect($this->url->link('checkout/cart', $lang, true));
			return;
		}
		$state = $row['state'];
		$inv = $m->client()->getInvoice($row['invoice_id']);
		if ($inv !== null) {
			$state = $m->sync($order_id, $inv);
		}

		if (in_array($state, ['paid', 'confirming', 'review'], true)) {
			$this->response->redirect($this->url->link('checkout/success', $lang, true));
		} elseif ($state === 'expired') {
			$this->response->redirect($this->url->link('checkout/failure', $lang, true));
		} else {
			$this->session->data['error'] = $this->language->get('error_not_paid');
			$this->response->redirect($this->url->link('checkout/checkout', $lang, true));
		}
	}

	/** POST index.php?route=extension/parnian_pay/payment/parnian_pay.webhook */
	public function webhook(): void {
		$this->load->model('extension/parnian_pay/payment/parnian_pay');
		$m = $this->model_extension_parnian_pay_payment_parnian_pay;
		$m->client(); // loads the library

		$raw = (string)file_get_contents('php://input');
		$sig = (string)($_SERVER['HTTP_PARNIAN_SIGNATURE'] ?? '');
		if (!\ParnianPayClient::verifySignature($raw, $sig, (string)$this->config->get('payment_parnian_pay_webhook_secret'))) {
			$m->log('webhook rejected: bad signature', true);
			$this->plain(400, 'bad signature');
			return;
		}

		$event = json_decode($raw, true);
		$data = (is_array($event) && isset($event['data']['invoice']) && is_array($event['data']['invoice'])) ? $event['data']['invoice'] : null;
		$order_id = $data && !empty($data['order_id']) ? (int)$data['order_id'] : 0;
		$row = $order_id ? $m->getInvoiceRow($order_id) : null;
		$m->log('webhook ' . ($event['type'] ?? '?') . ' invoice ' . ($data['id'] ?? '?'));

		if (!$row || $row['invoice_id'] !== ($data['id'] ?? '')) {
			$this->plain(200, 'ignored'); // not ours, or a superseded invoice
			return;
		}
		// Trust the gateway's current state, not only the event body.
		$inv = $m->client()->getInvoice($row['invoice_id']);
		if ($inv === null) {
			$m->log('webhook re-check failed', true);
			$this->plain(503, 'retry');
			return;
		}
		$m->sync($order_id, $inv);
		$this->plain(200, 'ok');
	}

	private function json(array $json): void {
		$this->response->addHeader('Content-Type: application/json');
		$this->response->setOutput(json_encode($json));
	}

	private function plain(int $code, string $text): void {
		$reasons = [200 => 'OK', 400 => 'Bad Request', 503 => 'Service Unavailable'];
		$this->response->addHeader(($this->request->server['SERVER_PROTOCOL'] ?? 'HTTP/1.1') . ' ' . $code . ' ' . $reasons[$code]);
		$this->response->addHeader('Content-Type: text/plain');
		$this->response->setOutput($text);
	}
}
