<?php
namespace Nasr\Academy;
defined('ABSPATH') || exit;

/**
 * Contract shared by Nasr Academy payment-gateway add-ons.
 *
 * Gateway add-ons are intentionally separate WordPress plugins. They register an
 * instance through the nasr_academy_payment_gateways filter and keep all API
 * credentials on the server.
 */
abstract class Payment_Gateway {
    abstract public function id();
    abstract public function title();

    public function description() { return ''; }
    public function fields() { return array(); }

    public function configured($settings) {
        foreach ($this->fields() as $field) {
            if (empty($field['required'])) { continue; }
            $key = sanitize_key($field['key'] ?? '');
            if ($key === '' || !isset($settings[$key]) || trim((string)$settings[$key]) === '') { return false; }
        }
        return true;
    }

    /** Return true, a public presentation array, or WP_Error. */
    public function availability($context, $settings) { return true; }

    /** Return true or WP_Error after validating saved administrator settings. */
    public function validate_settings($settings) { return true; }

    /**
     * Return redirect_url and provider_token. Optional keys: provider_order_id,
     * request, response. Never return an access token or API credential.
     */
    abstract public function request($transaction, $context, $settings);

    /**
     * Return success=true and a reference after remote verification. Optional
     * response data is stored in the private transaction log.
     */
    abstract public function verify($transaction, $callback, $settings);

    protected function amount_in_rials($amount, $currency) {
        return (int)$amount * ($currency === 'تومان' ? 10 : 1);
    }

    protected function gateway_error($message, $code = 'nasr_gateway_error', $status = 502) {
        return new \WP_Error($code, $message, array('status'=>$status));
    }

    protected function response_message($data, $fallback) {
        if (!is_array($data)) { return $fallback; }
        $candidates = array(
            $data['message'] ?? null,
            $data['error_description'] ?? null,
            $data['errorData']['message'] ?? null,
            $data['result']['message'] ?? null,
            $data['data']['message'] ?? null,
        );
        foreach ($candidates as $message) {
            if (!is_scalar($message)) { continue; }
            $message = trim(sanitize_text_field((string)$message));
            if ($message !== '') { return Support::length($message) > 300 ? $fallback : $message; }
        }
        return $fallback;
    }

    /**
     * HTTP wrapper used by add-ons. It deliberately returns parsed JSON only;
     * secrets and request headers are never persisted by the core plugin.
     */
    protected function remote($url, $method = 'POST', $headers = array(), $body = null, $form = false, $timeout = 30) {
        if (!wp_http_validate_url($url) || strtolower((string)wp_parse_url($url, PHP_URL_SCHEME)) !== 'https') {
            return $this->gateway_error('آدرس وب‌سرویس درگاه امن یا معتبر نیست.');
        }
        $args = array(
            'method'      => strtoupper($method),
            'timeout'     => max(10,min(45,(int)$timeout)),
            'redirection' => 2,
            'reject_unsafe_urls' => true,
            'headers'     => array_merge(array('Accept'=>'application/json','User-Agent'=>'Nasr-Academy/'.NASR_ACADEMY_VERSION),$headers),
        );
        if ($body !== null) {
            if ($form === 'multipart') {
                $boundary='----------------Nasr'.wp_generate_password(24,false,false);
                $parts='';
                foreach ((array)$body as $key=>$value) {
                    if (!is_scalar($value)) { continue; }
                    $name=preg_replace('/[^A-Za-z0-9_-]/','',(string)$key);
                    $parts.='--'.$boundary."\r\n".'Content-Disposition: form-data; name="'.$name.'"'."\r\n\r\n".(string)$value."\r\n";
                }
                $parts.='--'.$boundary."--\r\n";
                $args['headers']['Content-Type'] = 'multipart/form-data; boundary='.$boundary;
                $args['body']=$parts;
            } elseif ($form) {
                $args['headers']['Content-Type'] = 'application/x-www-form-urlencoded; charset=UTF-8';
                $args['body'] = http_build_query($body,'','&');
            } else {
                $args['headers']['Content-Type'] = 'application/json; charset=UTF-8';
                $args['body'] = wp_json_encode($body,JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }
        }
        $response = wp_safe_remote_request($url,$args);
        if (is_wp_error($response)) { return $this->gateway_error('ارتباط امن با وب‌سرویس درگاه برقرار نشد.'); }
        $code = (int)wp_remote_retrieve_response_code($response);
        $raw = (string)wp_remote_retrieve_body($response);
        $data = json_decode($raw,true,512,JSON_BIGINT_AS_STRING);
        if (!is_array($data)) { return $this->gateway_error('پاسخ وب‌سرویس درگاه قابل پردازش نیست.'); }
        return array('ok'=>$code >= 200 && $code < 300,'code'=>$code,'data'=>$data);
    }
}
