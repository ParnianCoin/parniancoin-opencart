<?php
/**
 * Parnian Pay API client — shared by the OpenCart 3 and OpenCart 4 extensions.
 * https://pay.parniancoin.com/docs
 */
if (!class_exists('ParnianPayClient')) {
	class ParnianPayClient {
		const VERSION = '1.0.0';

		private $base;
		private $key;
		public $last_error = '';
		public $last_code = '';

		public function __construct($base_url, $api_key) {
			$this->base = rtrim((string)$base_url, '/');
			$this->key = trim((string)$api_key);
		}

		/** Merchant status + rates for this API key. */
		public function me() {
			return $this->request('GET', '/api/v1/me');
		}

		/** fiat_amount + fiat_currency (converted with the merchant's rate) or amount in PARC. */
		public function createInvoice(array $body, $idempotency_key) {
			return $this->request('POST', '/api/v1/invoices', $body, array('Idempotency-Key: ' . $idempotency_key));
		}

		public function getInvoice($invoice_id) {
			if (!preg_match('/^inv_[A-Za-z0-9_-]{8,64}$/', (string)$invoice_id)) {
				$this->last_error = 'Invalid invoice id';
				$this->last_code = 'bad_id';
				return null;
			}
			return $this->request('GET', '/api/v1/invoices/' . rawurlencode($invoice_id));
		}

		/** @return array|null Decoded JSON, or null on any error (see last_error / last_code). */
		private function request($method, $path, $body = null, array $headers = array()) {
			$this->last_error = '';
			$this->last_code = '';
			if ($this->key === '') {
				$this->last_error = 'API key is not configured';
				$this->last_code = 'no_key';
				return null;
			}
			$ch = curl_init($this->base . $path);
			$h = array_merge(array(
				'Authorization: Bearer ' . $this->key,
				'Accept: application/json',
				'User-Agent: ParnianPay-OpenCart/' . self::VERSION,
			), $headers);
			$opts = array(
				CURLOPT_CUSTOMREQUEST  => $method,
				CURLOPT_RETURNTRANSFER => true,
				CURLOPT_FOLLOWLOCATION => false,
				CURLOPT_CONNECTTIMEOUT => 10,
				CURLOPT_TIMEOUT        => 20,
			);
			if ($body !== null) {
				$h[] = 'Content-Type: application/json';
				$opts[CURLOPT_POSTFIELDS] = json_encode($body);
			}
			$opts[CURLOPT_HTTPHEADER] = $h;
			curl_setopt_array($ch, $opts);
			$raw = curl_exec($ch);
			if ($raw === false) {
				$this->last_error = curl_error($ch);
				$this->last_code = 'network';
				curl_close($ch);
				return null;
			}
			$code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
			curl_close($ch);
			$json = json_decode($raw, true);
			if ($code < 200 || $code >= 300 || !is_array($json)) {
				$err = (is_array($json) && isset($json['error']) && is_array($json['error'])) ? $json['error'] : array();
				$this->last_code = isset($err['code']) ? preg_replace('/[^a-z0-9_]/', '', strtolower($err['code'])) : 'http_' . $code;
				$this->last_error = isset($err['message']) ? (string)$err['message'] : 'HTTP ' . $code;
				return null;
			}
			return $json;
		}

		/**
		 * Header "Parnian-Signature: t=<unix>,v1=<hex>", v1 = HMAC-SHA256(secret, t + "." + raw_body),
		 * t within $tolerance seconds.
		 */
		public static function verifySignature($raw_body, $header, $secret, $tolerance = 300, $now = null) {
			if ((string)$secret === '' || (string)$header === '') {
				return false;
			}
			$parts = array();
			foreach (explode(',', (string)$header) as $kv) {
				$pair = explode('=', trim($kv), 2);
				if (count($pair) === 2) {
					$parts[$pair[0]] = $pair[1];
				}
			}
			if (empty($parts['t']) || empty($parts['v1']) || !ctype_digit($parts['t'])) {
				return false;
			}
			$now = $now === null ? time() : (int)$now;
			if (abs($now - (int)$parts['t']) > $tolerance) {
				return false;
			}
			$expected = hash_hmac('sha256', $parts['t'] . '.' . $raw_body, (string)$secret);
			return hash_equals($expected, strtolower($parts['v1']));
		}

		/** "12.5000" -> 125000 TAR (1 PARC = 10,000 TAR) */
		public static function tar($parc) {
			if (!preg_match('/^(\d+)(?:\.(\d{1,4}))?$/', (string)$parc, $m)) {
				return null;
			}
			return (int)$m[1] * 10000 + (int)str_pad(isset($m[2]) ? $m[2] : '', 4, '0');
		}

		/**
		 * What to do with an order, given the invoice the gateway reports.
		 * Pure logic, shared by both OpenCart versions.
		 *
		 * @param array  $inv          Invoice from the gateway (GET /invoices/{id}).
		 * @param string $order_id     Our order id.
		 * @param string $invoice_id   Invoice id stored for that order.
		 * @param string $amount       PARC amount stored for that order.
		 * @param string $local_status Our last recorded state: pending|confirming|paid|review|expired.
		 * @return array{action:string,state:string}|null  action: paid|confirming|review|expired|mismatch|none
		 */
		public static function decide(array $inv, $order_id, $invoice_id, $amount, $local_status) {
			if (empty($inv['id']) || $inv['id'] !== $invoice_id) {
				return null;
			}
			if ((string)(isset($inv['order_id']) ? $inv['order_id'] : '') !== (string)$order_id) {
				return null;
			}
			$expected = self::tar($amount);
			$paid = isset($inv['paid_tar']) ? (int)$inv['paid_tar'] : 0;
			$status = isset($inv['status']) ? $inv['status'] : '';
			$final = in_array($local_status, array('paid', 'review'), true);

			switch ($status) {
				case 'paid':
					if ($local_status === 'paid') {
						return array('action' => 'none', 'state' => 'paid');
					}
					if ($expected === null || (int)$inv['amount_tar'] !== $expected || $paid < $expected) {
						return $local_status === 'review' ? array('action' => 'none', 'state' => 'review') : array('action' => 'mismatch', 'state' => 'review');
					}
					return array('action' => 'paid', 'state' => 'paid');
				case 'confirming':
					return $local_status === 'pending' ? array('action' => 'confirming', 'state' => 'confirming') : array('action' => 'none', 'state' => $local_status);
				case 'underpaid':
				case 'late':
					return $final ? array('action' => 'none', 'state' => $local_status) : array('action' => 'review', 'state' => 'review');
				case 'expired':
					return in_array($local_status, array('pending', 'confirming'), true) ? array('action' => 'expired', 'state' => 'expired') : array('action' => 'none', 'state' => $local_status);
			}
			return array('action' => 'none', 'state' => $local_status);
		}
	}
}
