<?php
    
use Tygh\Http;
use Tygh\Registry;

if (!defined('BOOTSTRAP')) { die('Access denied'); }
    
function fn_smsc_get_shipping_methods() {
    return db_get_hash_single_array("SELECT a.shipping_id, b.shipping FROM ?:shippings as a LEFT JOIN ?:shipping_descriptions as b ON a.shipping_id=b.shipping_id AND b.lang_code = '" . CART_LANGUAGE . "' ORDER BY a.position", array('shipping_id', 'shipping'));
}

function fn_smsc_get_order_statuses() {
    return db_get_hash_single_array("SELECT a.status, b.description FROM ?:statuses AS a LEFT JOIN ?:status_descriptions AS b ON a.status = b.status AND b.lang_code = ?s WHERE a.type = ?s", array('status', 'description'), CART_LANGUAGE,'O');
}

function fn_smsc_create_shipment(&$shipment_data, &$order_info, &$group_key, &$all_products) {
    $notify_user = fn_get_notification_rules($_REQUEST);
    $notify_user = $notify_user['C'];
    if ($notify_user == true) {
        if (empty($order_info['b_phone']) && empty($order_info['s_phone']) && empty($order_info['phone'])) {
            return;
        }
        
        $path = Registry::get('config.dir.addons') . 'smsc/';
        
        include ($path . 'vendor/autoload.php');
        
        $phone_area = Registry::get('addons.smsc.customer_phone_field');
        
        $phone_field = $phone_area . '_phone';
        
        $phone = $order_info[$phone_field];
        
        $country_field = $phone_area . '_country';
        
        $country = $order_info[$country_field];
        
        if (empty($phone)) {
            // If empty this field, than try to use default phone field
            if (!empty($order_info['phone'])) {
                $phone = $order_info['phone'];
            }
        }
        
        if (empty($country)) {
            if (!empty($order_info['country'])) {
                $country = $order_info['country'];
            } else {
                $country = Registry::get('settings.General.default_country');
            }
        }
        
        // Convert phone into E164 format
        
        $phoneUtil = \libphonenumber\PhoneNumberUtil::getInstance();
        
        $phone_proto = $phoneUtil->parse($phone, $country);
        
        $phone = $phoneUtil->format($phone_proto, \libphonenumber\PhoneNumberFormat::E164);
        
        $shipping = db_get_field('SELECT shipping FROM ?:shipping_descriptions WHERE shipping_id = ?i AND lang_code = ?s', $shipment_data['shipping_id'], $order_info['lang_code']);
        
        Tygh::$app['view']->assign('tracking_number', $shipment_data['tracking_number']);
        Tygh::$app['view']->assign('order_id', $order_info['order_id']);
        Tygh::$app['view']->assign('shipping_method', $shipping);
        
        $body = Tygh::$app['view']->fetch('addons/smsc/views/sms/components/new_shipment_sms.tpl');
        
        $body = fn_smsc_strip_tags($body);
        
        fn_smsc_send_sms($phone, $body);
    }
}

function fn_smsc_change_order_status(&$status_to, &$status_from, &$order_info, &$force_notification, &$order_statuses, &$place_order) {

    if (Registry::get('addons.smsc.admin_sms_order_updated') == 'Y' || Registry::get('addons.smsc.customer_sms_order_updated') == 'Y') {
        
        $order_id = $order_info['order_id'];
        
        Tygh::$app['view']->assign('order_id', $order_id);
        Tygh::$app['view']->assign('total', $order_info['total']);
        Tygh::$app['view']->assign('order_email', $order_info['email']);
        Tygh::$app['view']->assign('order_payment_info', $order_info['payment_method']['payment']);
        Tygh::$app['view']->assign('order_status_name', $order_statuses[$status_to]['description']);
        
        $body = '';
        
        if (Registry::get('addons.smsc.admin_sms_order_updated') == 'Y' && $place_order == false) {
            $result = fn_smsc_check_order_conditions('admin', $status_to, $order_info, $order_statuses);
            if ($result == true) {
                $body = Tygh::$app['view']->fetch('addons/smsc/views/sms/components/admin_order_updated_sms.tpl');
                $body = fn_smsc_strip_tags($body);
                $phone = Registry::get('addons.smsc.admin_phone_number');
                $phone = explode(',', $phone);
                foreach($phone as $k => $v) {
                    fn_smsc_send_sms($v, $body);
                }
            }
        }
        
        if (Registry::get('addons.smsc.customer_sms_order_updated') == 'Y') {
            
            if (empty($order_info['b_phone']) && empty($order_info['s_phone']) && empty($order_info['phone'])) {
                return;
            }
            
            $path = Registry::get('config.dir.addons') . 'smsc/';
            
            include ($path . 'vendor/autoload.php');
            
            $phone_area = Registry::get('addons.smsc.customer_phone_field');
            
            $phone_field = $phone_area . '_phone';
            
            $phone = $order_info[$phone_field];
            
            $country_field = $phone_area . '_country';
            
            $country = $order_info[$country_field];
            
            if (empty($phone)) {
                // If empty this field, than try to use default phone field
                if (!empty($order_info['phone'])) {
                    $phone = $order_info['phone'];
                }
            }
            
            if (empty($country)) {
                if (!empty($order_info['country'])) {
                    $country = $order_info['country'];
                } else {
                    $country = Registry::get('settings.General.default_country');
                }
            }
            
            // Convert phone into E164 format
            
            $phoneUtil = \libphonenumber\PhoneNumberUtil::getInstance();
            
            $phone_proto = $phoneUtil->parse($phone, $country);
            
            $phone = $phoneUtil->format($phone_proto, \libphonenumber\PhoneNumberFormat::E164);
            
            $result = fn_smsc_check_order_conditions('customer', $status_to, $order_info, $order_statuses);
            
            if ($result == true) {
                if ($order_statuses[$status_to]['sms_body']) {
                    
                    $replacements = array(
                        '%ORDER_ID%' => $order_id,
                        '%AMOUNT%' => $order_info['total'],
                        '%NAME%' => $order_info['firstname'],
                        '%LAST_NAME%' => $order_info['lastname'],
                        '%USER_EMAIL%' => $order_info['email']
                    );
                    
                    $body = $order_statuses[$status_to]['sms_body'];
                    $body = str_replace(array_keys($replacements), $replacements, $body);
                    
                    $body = fn_smsc_strip_tags($body);
                    
                    fn_smsc_send_sms($phone, $body);
                }
            }
        }
    }
}

function fn_smsc_place_order(&$order_id, &$action, &$fake1, &$cart) {
    if ($action !== 'save' && Registry::get('addons.smsc.admin_sms_new_order_placed') == 'Y') {
        Tygh::$app['view']->assign('order_id', $order_id);
        Tygh::$app['view']->assign('total', $cart['total']);
        
        $send_info = Registry::get('addons.smsc.admin_sms_send_payment_info');
        $send_email = Registry::get('addons.smsc.admin_sms_send_customer_email');
        $send_min_amount = Registry::get('addons.smsc.admin_sms_send_min_amout');
        $shippings = Registry::get('addons.smsc.admin_sms_send_shipping');
        
        if (!is_array($shippings)) {
            $shippings = array ();
        }
        
        Tygh::$app['view']->assign('send_info', $send_info == 'Y' ? true : false);
        Tygh::$app['view']->assign('send_email', $send_email == 'Y' ? true : false);
        Tygh::$app['view']->assign('send_min_amount', $send_min_amount == 'Y' ? true : false);
        
        $order = fn_get_order_info($order_id);
        
        Tygh::$app['view']->assign('order_email', $order['email']);
        Tygh::$app['view']->assign('order_payment_info', $order['payment_method']['payment']);
        
        if (count($shippings) && !isset($shippings['N'])) {
            $in_shipping = false;

            if (!empty($order['shipping'])) {
                foreach ($order['shipping'] as $id => $data) {
                    if ($shippings[$id] == 'Y') {
                        $in_shipping = true;
                        break;
                    }
                }
            }
        } else {
            $in_shipping = true;
        }
        
        if ($in_shipping && $order['subtotal'] > doubleval($send_min_amount)) {
            $body = Tygh::$app['view']->fetch('addons/smsc/views/sms/components/admin_order_place_sms.tpl');
            $body = fn_smsc_strip_tags($body);
            $phone = Registry::get('addons.smsc.admin_phone_number');
            $phone = explode(',', $phone);
            foreach($phone as $k => $v) {
                fn_smsc_send_sms($v, $body);
            }
        }        
    }
}

function fn_smsc_update_profile(&$action, &$user_data) {
    if ($action == 'add' && AREA == 'C' && Registry::get('addons.smsc.admin_sms_new_cusomer_registered') == 'Y') {
        Tygh::$app['view']->assign('customer', $user_data['firstname'] . (empty($user_data['lastname']) ? '' : $user_data['lastname']));
        $body = Tygh::$app['view']->fetch('addons/smsc/views/sms/components/new_profile_sms.tpl');
        $body = fn_smsc_strip_tags($body);
        $phone = Registry::get('addons.smsc.admin_phone_number');
        $phone = explode(',', $phone);
        foreach($phone as $k => $v) {
            fn_smsc_send_sms($v, $body);
        }
    }    
}

function fn_smsc_update_product_amount(&$new_amount, &$product_id) {
    if ($new_amount <= Registry::get('settings.General.low_stock_threshold') && Registry::get('addons.smsc.admin_sms_product_negative_amount') == 'Y') {
        $lang_code = Registry::get('settings.Appearance.backend_default_language');

        Tygh::$app['view']->assign('product_id', $product_id);
        Tygh::$app['view']->assign('product', db_get_field("SELECT product FROM ?:product_descriptions WHERE product_id = ?i AND lang_code = ?s", $product_id, $lang_code));
        $body = Tygh::$app['view']->fetch('addons/smsc/views/sms/components/low_stock_sms.tpl');
        $body = fn_smsc_strip_tags($body);
        $phone = Registry::get('addons.smsc.admin_phone_number');
        $phone = explode(',', $phone);
        foreach($phone as $k => $v) {
            fn_smsc_send_sms($v, $body);
        }
    }    
}

function fn_smsc_check_order_conditions($for = 'admin', $status_to, $order, $order_statuses) {
    $send_min_amount = Registry::get('addons.smsc.' . $for . '_sms_send_min_amount');
    $shippings = Registry::get('addons.smsc.' . $for . '_sms_send_shipping');
    $statuses = Registry::get('addons.smsc.' . $for . '_sms_send_order_statuses');
    if ($for == 'admin') {
        $send_email = Registry::get('addons.smsc.admin_sms_send_customer_email');
    }
    
    if (!is_array($statuses)) {
        $statuses = array();
    }

    if (!is_array($shippings)) {
        $shippings = array ();
    }
    
    if (count($shippings) && !isset($shippings['N'])) {
        $in_shipping = false;

        if (!empty($order['shipping'])) {
            foreach ($order['shipping'] as $id => $data) {
                if ($shippings[$id] == 'Y') {
                    $in_shipping = true;
                    break;
                }
            }
        }
    } else {
        $in_shipping = true;
    }

    if (count($statuses) && !isset($statuses['N'])) {
        $in_status = false;
        if ($statuses[$status_to] == 'Y') {
            $in_status = true;
        }
        // check if status N is a status
        if (isset($statuses['N']) && empty($statuses['N'])) {
            $in_status = true;
        }
    } else {
        $in_status = true;
    }
    
    if ($in_status == true && $in_shipping == true && $order['subtotal'] > doubleval($send_min_amount)) {
        return true;
    }
    return false;
}

function fn_smsc_send_sms($phone, $body) {
    
    $phone = trim($phone);
    
    if (!$phone || !$body) {
        return false;
    }
    
    $fail_responces = array(
        '1' => 'Ошибка в параметрах.',
        '2' => 'Неверный логин или пароль.',
        '3' => 'Недостаточно средств на счету Клиента.',
        '4' => 'IP-адрес временно заблокирован из-за частых ошибок в запросах.',
        '5' => 'Неверный формат даты.',
        '6' => 'Сообщение запрещено (по тексту или по имени отправителя).',
        '7' => 'Неверный формат номера телефона.',
        '8' => 'Сообщение на указанный номер не может быть доставлено.',
        '9' => 'Отправка более одного одинакового запроса на передачу SMS-сообщения в течение минуты.'
    );
    
    $client = new SoapClient('https://smsc.ru/sys/soap.php?wsdl');
    
    $balance = $client->get_balance(fn_smsc_get_auth());
    
    $balance = $balance->balanceresult->balance;
    
    $error = $balance->balanceresult->error;
    
    if ($balance > 0) {
        $text = $body;
        
        $sms = array(
            'login' => Registry::get('addons.smsc.smsc_login'),
            'psw' => Registry::get('addons.smsc.smsc_password'),
            'sender' => Registry::get('addons.smsc.smsc_sender'),
            'phones' => $phone,
            'mes' => $text
        );
        $result = $client->send_sms($sms);
        $result = $result->sendresult;
        
        $error = false;
        
        if (isset($result->error)) {
            $error = (string) $result->error;
        }
        
        if ($error == true) {
            if (AREA == 'A') {
                fn_set_notification('W', __('warning'), $fail_responces[$result]);
            }            
        }
    }    
}

function fn_smsc_get_auth() {
    return array(
        'login' => Registry::get('addons.smsc.smsc_login'),
        'psw' => Registry::get('addons.smsc.smsc_password')
    );
}

/**
 * Strip html tags from the data
 *
 * @param mixed $var variable to strip tags from
 * @return mixed filtered variable
 */
function fn_smsc_strip_tags(&$var)
{

    if (!is_array($var)) {
        return (strip_tags($var));
    } else {
        $stripped = array();
        foreach ($var as $k => $v) {
            $sk = strip_tags($k);
            if (!is_array($v)) {
                $sv = strip_tags($v);
            } else {
                $sv = fn_strip_tags($v);
            }
            $stripped[$sk] = $sv;
        }

        return ($stripped);
    }
}